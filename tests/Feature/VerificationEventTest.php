<?php

declare(strict_types=1);

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Events\VerificationCompleted;
use Core45\HCaptcha\Support\VerificationContext;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $overrides
 */
function fakeSiteverifyWith(bool $success, array $overrides = []): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(array_merge([
            'success' => $success,
            'hostname' => 'example.test',
        ], $overrides)),
    ]);
}

it('fires once for a real verification, carrying the token hash, the context and the verdict', function (): void {
    Event::fake([VerificationCompleted::class]);
    fakeSiteverifyWith(true);

    $context = new VerificationContext(field: 'h-captcha-response', action: 'checkout', profile: null);

    $result = app(Verifier::class)->verify(TestCase::TEST_TOKEN, null, $context);

    Event::assertDispatchedTimes(VerificationCompleted::class, 1);

    Event::assertDispatched(VerificationCompleted::class, function (VerificationCompleted $event) use ($context, $result): bool {
        return $event->tokenHash === hash('sha256', TestCase::TEST_TOKEN)
            && $event->context->field === $context->field
            && $event->context->action === $context->action
            && $event->context->profile === $context->profile
            && $event->passed() === $result->passed();
    });
});

it('does not fire a second time for a memoized repeat of the same token and scope', function (): void {
    Event::fake([VerificationCompleted::class]);
    fakeSiteverifyWith(true);

    $verifier = app(Verifier::class);
    $context = VerificationContext::forField('h-captcha-response');

    $verifier->verify(TestCase::TEST_TOKEN, null, $context);
    $verifier->verify(TestCase::TEST_TOKEN, null, $context);

    Http::assertSentCount(1);
    Event::assertDispatchedTimes(VerificationCompleted::class, 1);
});

it('does not fire for a null or empty token', function (): void {
    Event::fake([VerificationCompleted::class]);
    fakeSiteverifyWith(true);

    app(Verifier::class)->verify(null);
    app(Verifier::class)->verify('');
    app(Verifier::class)->verify('   ');

    Event::assertNotDispatched(VerificationCompleted::class);
    Http::assertNothingSent();
});

it('does not fire for a token longer than the configured maximum length', function (): void {
    config()->set('hcaptcha.max_token_length', 10);

    Event::fake([VerificationCompleted::class]);
    fakeSiteverifyWith(true);

    app(Verifier::class)->verify(str_repeat('a', 11));

    Event::assertNotDispatched(VerificationCompleted::class);
    Http::assertNothingSent();
});

it('fires with passed() false for a rejected verdict', function (): void {
    Event::fake([VerificationCompleted::class]);
    fakeSiteverifyWith(false, ['error-codes' => ['invalid-input-response']]);

    app(Verifier::class)->verify(TestCase::TEST_TOKEN);

    Event::assertDispatched(VerificationCompleted::class, fn (VerificationCompleted $event): bool => $event->passed() === false);
});

it('never carries the raw token or the secret in its payload', function (): void {
    Event::fake([VerificationCompleted::class]);
    fakeSiteverifyWith(true);

    app(Verifier::class)->verify(TestCase::TEST_TOKEN);

    Event::assertDispatched(VerificationCompleted::class, function (VerificationCompleted $event): bool {
        $reflection = new ReflectionObject($event);

        foreach ($reflection->getProperties() as $property) {
            $value = $property->getValue($event);

            if (! is_string($value)) {
                continue;
            }

            if (str_contains($value, TestCase::TEST_TOKEN) || str_contains($value, TestCase::TEST_SECRET)) {
                return false;
            }
        }

        return true;
    });
});
