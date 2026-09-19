<?php

declare(strict_types=1);

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Exceptions\MissingSecretException;
use Core45\HCaptcha\Support\HttpVerifier;
use Core45\HCaptcha\Support\VerificationContext;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @return array<string, mixed>
 */
function siteverifyBody(array $overrides = []): array
{
    return array_merge([
        'success' => true,
        'hostname' => 'example.test',
        'challenge_ts' => '2026-09-17T10:00:00Z',
    ], $overrides);
}

function fakeSiteverify(array $body = [], int $status = 200): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(siteverifyBody($body), $status),
    ]);
}

function verifier(): Verifier
{
    return app(Verifier::class);
}

it('accepts a token hCaptcha approves', function (): void {
    fakeSiteverify();

    $result = verifier()->verify(TestCase::TEST_TOKEN, '203.0.113.1');

    expect($result->passed())->toBeTrue()
        ->and($result->hostname)->toBe('example.test')
        ->and($result->challengeTs?->toIso8601String())->toStartWith('2026-09-17T10:00:00')
        ->and($result->errorCodes)->toBe([]);
});

it('sends the secret, token, client ip and sitekey', function (): void {
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN, '203.0.113.1');

    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe('https://api.hcaptcha.com/siteverify');

        return $request['secret'] === TestCase::TEST_SECRET
            && $request['response'] === TestCase::TEST_TOKEN
            && $request['remoteip'] === '203.0.113.1'
            && $request['sitekey'] === TestCase::TEST_SITEKEY;
    });
});

it('omits the sitekey when send_sitekey is off', function (): void {
    config()->set('hcaptcha.send_sitekey', false);
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN);

    Http::assertSent(fn ($request): bool => ! isset($request['sitekey']));
});

it('rejects a token hCaptcha refuses and surfaces the error codes', function (): void {
    fakeSiteverify(['success' => false, 'error-codes' => ['already-seen-response']]);

    $result = verifier()->verify('nope');

    expect($result->failed())->toBeTrue()
        ->and($result->success)->toBeFalse()
        ->and($result->errorCodes)->toBe(['already-seen-response'])
        ->and($result->tokenAlreadyUsed())->toBeTrue()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.expired');
});

/*
 * The reason this package exists in the shape it does. hCaptcha tokens are
 * single-use: if the rule and the middleware both verified, the second call
 * would be told token-already-used and the visitor would see a failure they
 * cannot act on.
 */
it('issues one HTTP request when the same token is verified twice for one scope', function (): void {
    fakeSiteverify();

    $first = verifier()->verify(TestCase::TEST_TOKEN, null, 'h-captcha-response');
    $second = verifier()->verify(TestCase::TEST_TOKEN, null, 'h-captcha-response');

    Http::assertSentCount(1);

    expect($second)->toBe($first);
});

/*
 * An unscoped call gets no memo entry at all. One anonymous bucket shared by
 * every caller would let a pass obtained for one action be reused by an
 * unrelated one, so an unscoped caller pays a real request instead and hCaptcha
 * answers `token-already-used` the second time -- correct for a single-use
 * token. Every entry point in the package passes a scope; this is the
 * fail-safe for anything that does not.
 */
it('does not memoize a verdict obtained without a scope', function (): void {
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN);
    verifier()->verify(TestCase::TEST_TOKEN);

    Http::assertSentCount(2);
});

/*
 * The memo is a safety mechanism, not a cache. Livewire processes up to 200
 * components in ONE HTTP request and nothing flushes scoped container
 * instances between them, so an unscoped memo meant one solved captcha
 * authorised every component in the batch. Scoping bounds a solved token to the
 * field it was solved for.
 */
it('does not let one solved token authorise a second, different field', function (): void {
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN, null, 'contact_form');
    verifier()->verify(TestCase::TEST_TOKEN, null, 'newsletter_form');

    Http::assertSentCount(2);
});

it('still collapses two checks of the same field into one call', function (): void {
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN, null, 'h-captcha-response');
    verifier()->verify(TestCase::TEST_TOKEN, null, 'h-captcha-response');

    Http::assertSentCount(1);
});

it('rejects an oversized token without proxying it to hCaptcha', function (): void {
    config()->set('hcaptcha.max_token_length', 64);
    fakeSiteverify();

    $result = verifier()->verify(str_repeat('a', 65));

    expect($result->failed())->toBeTrue()
        ->and($result->errorCodes)->toBe(['token-too-long'])
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.failed');

    Http::assertNothingSent();
});

it('treats a non-boolean success value as a rejection', function (mixed $success): void {
    fakeSiteverify(['success' => $success]);

    expect(verifier()->verify(TestCase::TEST_TOKEN)->passed())->toBeFalse();
})->with([
    ['false'],
    ['error'],
    [-1],
    [1],
    [[0]],
]);

it('issues a separate request for a different token', function (): void {
    fakeSiteverify();

    verifier()->verify('token-one');
    verifier()->verify('token-two');

    Http::assertSentCount(2);
});

it('forgets memoized verdicts when flushed', function (): void {
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN);
    verifier()->flush();
    verifier()->verify(TestCase::TEST_TOKEN);

    Http::assertSentCount(2);
});

it('never calls the service when no token was submitted', function (): void {
    fakeSiteverify();

    $result = verifier()->verify(null);

    Http::assertNothingSent();

    expect($result->failed())->toBeTrue()
        ->and($result->tokenMissing())->toBeTrue()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.missing');
});

it('treats a whitespace-only token as missing', function (): void {
    fakeSiteverify();

    expect(verifier()->verify('   ')->tokenMissing())->toBeTrue();

    Http::assertNothingSent();
});

it('fails closed when the service cannot be reached', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->failed())->toBeTrue()
        ->and($result->serviceUnavailable)->toBeTrue()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.unavailable');
});

it('fails closed when the service answers with an error status', function (): void {
    Http::fake(['api.hcaptcha.com/*' => Http::response('gateway down', 502)]);

    expect(verifier()->verify(TestCase::TEST_TOKEN)->failed())->toBeTrue();
});

it('fails open only when explicitly configured to', function (): void {
    config()->set('hcaptcha.fail_open', true);
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->passed())->toBeTrue()
        ->and($result->success)->toBeFalse()
        ->and($result->serviceUnavailable)->toBeTrue();
});

/*
 * The fail-open verdict has no hostname, because there was no response. The
 * hostname check used to reject it, so HCAPTCHA_FAIL_OPEN=true was a no-op for
 * every install that kept the default hostname policy.
 */
it('fails open even when a hostname policy is configured', function (): void {
    config()->set('hcaptcha.fail_open', true);
    config()->set('hcaptcha.hostnames', ['example.test']);
    Http::fake([
        'api.hcaptcha.com/*' => Http::response('<html>Service unavailable</html>', 503),
    ]);

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->passed())->toBeTrue()
        ->and($result->success)->toBeFalse()
        ->and($result->serviceUnavailable)->toBeTrue()
        ->and($result->rejectedBy)->toBeNull();
});

it('never turns a provider rejection into acceptance under fail-open', function (int $status): void {
    config()->set('hcaptcha.fail_open', true);
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => false, 'error-codes' => ['already-seen-response']], $status),
    ]);

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->passed())->toBeFalse()
        ->and($result->serviceUnavailable)->toBeFalse()
        ->and($result->hasErrorCode('already-seen-response'))->toBeTrue();
})->with([
    '200' => 200,
    '400' => 400,
]);

it('fails closed on a 2xx body that carries no verdict at all, even under fail-open', function (array $body): void {
    config()->set('hcaptcha.fail_open', true);
    Http::fake([
        'api.hcaptcha.com/*' => Http::response($body, 200),
    ]);

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->passed())->toBeFalse()
        ->and($result->serviceUnavailable)->toBeFalse();
})->with([
    'empty object' => [[]],
    'error codes only' => [['error-codes' => ['invalid-input-response']]],
]);

it('logs a configuration error carried in a non-2xx verdict body', function (): void {
    config()->set('hcaptcha.hostnames', ['example.test']);
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => false, 'error-codes' => ['bad-request']], 400),
    ]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'configuration problem'));
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->failed())->toBeTrue()
        ->and($result->isConfigurationError())->toBeTrue();
});

it('still treats a non-2xx response without a verdict body as an outage', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response('<html>Bad gateway</html>', 502),
    ]);

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->serviceUnavailable)->toBeTrue()
        ->and($result->passed())->toBeFalse();
});

/*
 * hCaptcha documents that the hostname is browser-derived, unsuitable for
 * authentication, and may come back as `not-provided` under load. Rejecting it
 * by default would drop genuine traffic during hCaptcha's own busy periods.
 */
it('accepts a token whose hostname hCaptcha did not provide, and says so', function (string $hostname): void {
    config()->set('hcaptcha.hostnames', ['example.test']);
    fakeSiteverify(['hostname' => $hostname]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'did not report a hostname'));
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->passed())->toBeTrue()
        ->and($result->rejectedBy)->toBeNull();
})->with([
    'not-provided' => 'not-provided',
    'empty' => '',
]);

it('rejects an unreported hostname when the policy is strict', function (): void {
    config()->set('hcaptcha.hostnames', ['example.test']);
    config()->set('hcaptcha.hostnames_strict', true);
    fakeSiteverify(['hostname' => 'not-provided']);

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->failed())->toBeTrue()
        ->and($result->success)->toBeTrue()
        ->and($result->rejectedBy)->toBe('hostname-unknown');
});

it('sends the sitekey carried by the context instead of the configured one', function (): void {
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN, null, new VerificationContext(
        field: 'h-captcha-response',
        sitekey: '20000000-ffff-ffff-ffff-000000000002',
    ));

    Http::assertSent(fn (Request $request): bool => $request['sitekey'] === '20000000-ffff-ffff-ffff-000000000002');
});

it('falls back to the configured sitekey when the context carries an empty one', function (): void {
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN, null, new VerificationContext(field: 'f', sitekey: ''));

    Http::assertSent(fn (Request $request): bool => $request['sitekey'] === TestCase::TEST_SITEKEY);
});

it('keeps verdicts apart when the same field is verified for two different actions', function (): void {
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN, null, new VerificationContext(field: 'data.captcha', action: 'App\\Livewire\\Contact#a'));
    verifier()->verify(TestCase::TEST_TOKEN, null, new VerificationContext(field: 'data.captcha', action: 'App\\Livewire\\Newsletter#b'));

    Http::assertSentCount(2);
});

it('does not let a different expected sitekey share a memoized verdict', function (): void {
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN, null, new VerificationContext(field: 'f', sitekey: 'key-a'));
    verifier()->verify(TestCase::TEST_TOKEN, null, new VerificationContext(field: 'f', sitekey: 'key-b'));

    Http::assertSentCount(2);
});

it('makes exactly one attempt by default', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response('', 503),
    ]);

    verifier()->verify(TestCase::TEST_TOKEN);

    Http::assertSentCount(1);
});

it('retries as many additional times as configured', function (): void {
    config()->set('hcaptcha.retries', 2);
    Http::fake([
        'api.hcaptcha.com/*' => Http::response('', 503),
    ]);

    verifier()->verify(TestCase::TEST_TOKEN);

    Http::assertSentCount(3);
});

it('rejects a genuine token reported against an unexpected hostname', function (): void {
    config()->set('hcaptcha.hostnames', ['allowed.test']);
    fakeSiteverify(['hostname' => 'attacker.test']);

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->failed())->toBeTrue()
        ->and($result->rejectedBy)->toBe('hostname-mismatch');
});

it('accepts a configured hostname regardless of case', function (): void {
    config()->set('hcaptcha.hostnames', 'Allowed.Test');
    fakeSiteverify(['hostname' => 'allowed.test']);

    expect(verifier()->verify(TestCase::TEST_TOKEN)->passed())->toBeTrue();
});

it('rejects a token whose risk score is above max_score', function (): void {
    // hCaptcha scores risk: higher is more bot-like, the inverse of reCAPTCHA.
    config()->set('hcaptcha.max_score', 0.5);
    fakeSiteverify(['score' => 0.9, 'score_reason' => ['bot']]);

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->failed())->toBeTrue()
        ->and($result->rejectedBy)->toBe('score-too-high')
        ->and($result->scoreReasons)->toBe(['bot']);
});

it('accepts a token whose risk score is within max_score', function (): void {
    config()->set('hcaptcha.max_score', 0.5);
    fakeSiteverify(['score' => 0.1]);

    expect(verifier()->verify(TestCase::TEST_TOKEN)->passed())->toBeTrue();
});

it('ignores max_score when the account returns no score', function (): void {
    config()->set('hcaptcha.max_score', 0.5);
    fakeSiteverify();

    expect(verifier()->verify(TestCase::TEST_TOKEN)->passed())->toBeTrue();
});

/*
 * The default install has no hostname list, so the only thing standing between
 * it and a token an attacker minted against their OWN sitekey is that the
 * package sends `sitekey` with the verification request and hCaptcha itself
 * answers `sitekey-mismatch`. That must land as a plain rejection of the
 * visitor, not be swallowed as a site-owner configuration problem.
 */
/*
 * The attack `send_sitekey` cannot stop. Your sitekey is public -- it is in
 * your HTML -- so an attacker embeds YOUR sitekey on their own page, solves the
 * challenge there or buys solutions from a farm, and posts a genuine token.
 * siteverify answers success: true with their hostname. The hostname check is
 * the only thing that rejects it, which is why it now defaults to the host of
 * APP_URL rather than to null.
 */
it('rejects a genuine token solved on another site using our own sitekey', function (): void {
    config()->set('hcaptcha.hostnames', 'example.test');
    fakeSiteverify(['hostname' => 'attacker.example']);

    $result = verifier()->verify(TestCase::TEST_TOKEN);

    expect($result->failed())->toBeTrue()
        ->and($result->rejectedBy)->toBe('hostname-mismatch');
});

/*
 * Disabling the hostname check is allowed but must never be silent. It is
 * logged once per process rather than on every verification: a deliberately
 * multi-domain install should not have its error channel flooded at request
 * rate.
 */
it('logs an error once per process while the hostname check is disabled', function (): void {
    HttpVerifier::forgetLoggedWarnings();
    config()->set('hcaptcha.hostnames', ['', null]);
    fakeSiteverify();

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'hostname check is inactive'));

    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    verifier()->verify(TestCase::TEST_TOKEN, null, 'first');
    verifier()->verify(TestCase::TEST_TOKEN, null, 'second');
});

it('rejects a token minted against a different sitekey and logs it as a configuration error', function (): void {
    // An active hostname policy, so the once-per-process "hostname check is
    // inactive" error cannot fire here and confuse the expectation below.
    config()->set('hcaptcha.hostnames', ['example.test']);
    fakeSiteverify(['success' => false, 'error-codes' => ['sitekey-secret-mismatch']]);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'configuration problem'));
    Log::shouldReceive('warning')->zeroOrMoreTimes();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $result = verifier()->verify('token-from-another-site');

    expect($result->failed())->toBeTrue()
        ->and($result->passed())->toBeFalse()
        ->and($result->hasErrorCode('sitekey-secret-mismatch'))->toBeTrue()
        ->and($result->isConfigurationError())->toBeTrue()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.failed');
});

it('rejects every documented owner-side error code rather than passing the request', function (string $code): void {
    fakeSiteverify(['success' => false, 'error-codes' => [$code]]);

    $result = verifier()->verify('some-token');

    expect($result->failed())->toBeTrue()
        ->and($result->isConfigurationError())->toBeTrue();
})->with([
    'missing-input-secret',
    'invalid-input-secret',
    'sitekey-secret-mismatch',
    'bad-request',
    'not-using-dummy-passcode',
    'not-using-dummy-secret',
]);

/*
 * The memo must not outlive the request. It is registered with scoped() rather
 * than singleton() precisely so Octane and long-running workers get a fresh
 * one, because a leaked verdict is a reusable captcha bypass.
 */
it('is registered per request, not as a process-wide singleton', function (): void {
    // scopedInstances has no public accessor, so read it directly: the
    // difference between scoped() and singleton() is the whole safety property
    // being asserted, and it is invisible outside Octane at runtime.
    $container = new ReflectionObject(app());
    $property = $container->getProperty('scopedInstances');

    /** @var list<string> $scoped */
    $scoped = $property->getValue(app());

    expect(app()->isShared(Verifier::class))->toBeTrue()
        ->and($scoped)->toContain(Verifier::class);
});

it('throws rather than silently rejecting everyone when no secret is set', function (): void {
    config()->set('hcaptcha.secret', null);
    fakeSiteverify();

    verifier()->verify(TestCase::TEST_TOKEN);
})->throws(MissingSecretException::class);
