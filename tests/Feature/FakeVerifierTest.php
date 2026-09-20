<?php

declare(strict_types=1);

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Facades\HCaptcha;
use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Rules\HCaptcha as HCaptchaRule;
use Core45\HCaptcha\Support\VerificationContext;
use Core45\HCaptcha\Support\VerificationResult;
use Core45\HCaptcha\Testing\FakeVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

it('swaps in the fake for the contract, the manager and the rule', function (): void {
    // Resolve both first, so the test proves the swap survives an already
    // resolved manager rather than a lucky resolution order.
    $manager = app(HCaptchaManager::class);
    expect($manager->faking())->toBeFalse();

    $fake = HCaptcha::fake();

    expect(app(Verifier::class))->toBe($fake)
        ->and(app(HCaptchaManager::class)->faking())->toBeTrue();

    Http::fake();

    $result = (new HCaptchaRule)->resultFor('h-captcha-response', 'any-token');

    expect($result->passed())->toBeTrue();

    $fake->assertVerifiedTimes(1)->assertVerifiedFor('h-captcha-response');

    // The whole point: no siteverify call happened.
    Http::assertNothingSent();
});

it('rejects every token when faked as failing', function (): void {
    $fake = HCaptcha::fake(false);

    $result = app(Verifier::class)->verify('any-token', null, VerificationContext::forField('captcha'));

    expect($result->passed())->toBeFalse()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.failed');

    $fake->assertVerified();
});

it('reports a missing token as missing however it was told to answer', function (): void {
    HCaptcha::fake();

    $result = app(Verifier::class)->verify(null);

    expect($result->passed())->toBeFalse()
        ->and($result->tokenMissing())->toBeTrue()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.missing');
});

it('answers with a specific error code', function (): void {
    HCaptcha::fake()->fail('already-seen-response');

    $result = app(Verifier::class)->verify('spent-token');

    expect($result->tokenAlreadyUsed())->toBeTrue()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.expired');
});

it('answers with a result built per call', function (): void {
    HCaptcha::fake()->respondWith(fn (?string $token): VerificationResult => new VerificationResult(
        success: $token === 'good',
    ));

    expect(app(Verifier::class)->verify('good')->passed())->toBeTrue()
        ->and(app(Verifier::class)->verify('bad')->passed())->toBeFalse();
});

it('passes the validation rule without any HTTP call', function (): void {
    HCaptcha::fake();
    Http::fake();

    $validator = Validator::make(
        ['h-captcha-response' => FakeVerifier::TOKEN],
        ['h-captcha-response' => 'hcaptcha'],
    );

    expect($validator->passes())->toBeTrue();

    Http::assertNothingSent();
});

it('records nothing when no token was verified', function (): void {
    HCaptcha::fake()->assertNothingVerified();
});
