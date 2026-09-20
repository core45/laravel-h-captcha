<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Testing;

use Closure;
use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Support\VerificationContext;
use Core45\HCaptcha\Support\VerificationResult;
use PHPUnit\Framework\Assert;

/**
 * A verifier that answers without touching the network.
 *
 * Swapped in with `HCaptcha::fake()`, it lets an application test a protected
 * form without assembling siteverify JSON in every test, and -- paired with
 * the fake widget the views render while it is active -- lets a browser test
 * submit that form for real.
 *
 * It is deliberately not a subclass of HttpVerifier: it implements the same
 * contract and nothing else, so a test can never accidentally reach the real
 * verification path through it.
 */
final class FakeVerifier implements Verifier
{
    /**
     * The token the fake widget publishes. Recognisable on sight in a failing
     * test, and nothing hCaptcha would ever mint.
     */
    public const TOKEN = 'fake-hcaptcha-token';

    /**
     * Every call made through this fake, in order.
     *
     * @var list<array{token: string|null, clientIp: string|null, context: VerificationContext, result: VerificationResult}>
     */
    private array $verifications = [];

    /**
     * What to answer next: a result, or a closure given the token and context.
     *
     * @var (Closure(string|null, VerificationContext): VerificationResult)|VerificationResult|null
     */
    private Closure|VerificationResult|null $answer = null;

    public function __construct(private bool $passes = true) {}

    /**
     * Accept every token from here on.
     */
    public function pass(): self
    {
        $this->passes = true;
        $this->answer = null;

        return $this;
    }

    /**
     * Reject every token from here on. The code decides which message a
     * visitor sees -- `already-seen-response` for a spent token, for instance.
     */
    public function fail(string $errorCode = 'invalid-input-response'): self
    {
        $this->passes = false;
        $this->answer = new VerificationResult(success: false, errorCodes: [$errorCode]);

        return $this;
    }

    /**
     * Answer with an exact result, or with a closure that builds one per call.
     * For the cases the two switches above do not cover: an outage, a local
     * hostname rejection, a score.
     *
     * @param  (Closure(string|null, VerificationContext): VerificationResult)|VerificationResult  $answer
     */
    public function respondWith(Closure|VerificationResult $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    public function verify(?string $token, ?string $clientIp = null, string|VerificationContext|null $scope = null): VerificationResult
    {
        $context = VerificationContext::from($scope);

        $result = $this->resultFor($token, $context);

        $this->verifications[] = [
            'token' => $token,
            'clientIp' => $clientIp,
            'context' => $context,
            'result' => $result,
        ];

        return $result;
    }

    /**
     * No memo to clear. The recorded calls are the point of this class, so
     * they survive -- use a fresh fake for a fresh expectation.
     */
    public function flush(): void {}

    /**
     * @return list<array{token: string|null, clientIp: string|null, context: VerificationContext, result: VerificationResult}>
     */
    public function verifications(): array
    {
        return $this->verifications;
    }

    /**
     * Assert that at least one token was verified, optionally one matching a
     * callback given the token and its context.
     *
     * @param  (Closure(string|null, VerificationContext): bool)|null  $callback
     */
    public function assertVerified(?Closure $callback = null): self
    {
        if ($callback === null) {
            Assert::assertNotSame([], $this->verifications, 'Expected an hCaptcha token to have been verified, but none was.');

            return $this;
        }

        $matches = array_filter(
            $this->verifications,
            static fn (array $call): bool => $callback($call['token'], $call['context']),
        );

        Assert::assertNotSame([], $matches, 'Expected a matching hCaptcha verification, but none of the '.count($this->verifications).' recorded calls matched.');

        return $this;
    }

    /**
     * Assert the field a token was verified for. The field is what the rule
     * and the middleware scope on, so this is what proves the right form was
     * guarded.
     */
    public function assertVerifiedFor(string $field): self
    {
        return $this->assertVerified(static fn (?string $token, VerificationContext $context): bool => $context->field === $field);
    }

    public function assertVerifiedTimes(int $times): self
    {
        Assert::assertCount($times, $this->verifications, 'Unexpected number of hCaptcha verifications.');

        return $this;
    }

    public function assertNothingVerified(): self
    {
        Assert::assertSame([], $this->verifications, 'Expected no hCaptcha verification, but '.count($this->verifications).' happened.');

        return $this;
    }

    private function resultFor(?string $token, VerificationContext $context): VerificationResult
    {
        // A missing token is answered the way the real verifier answers it,
        // whatever the fake was told to do: a test that forgets to fill the
        // field should see the missing-token message, not a pass.
        if ($token === null || trim($token) === '') {
            return VerificationResult::missingToken();
        }

        if ($this->answer instanceof VerificationResult) {
            return $this->answer;
        }

        if ($this->answer instanceof Closure) {
            return ($this->answer)($token, $context);
        }

        return new VerificationResult(
            success: $this->passes,
            hostname: 'localhost',
            errorCodes: $this->passes ? [] : ['invalid-input-response'],
        );
    }
}
