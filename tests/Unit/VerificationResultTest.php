<?php

declare(strict_types=1);

use Core45\HCaptcha\Support\VerificationResult;

it('accepts by default exactly when hCaptcha said yes', function (): void {
    expect((new VerificationResult(success: true))->accepted)->toBeTrue()
        ->and((new VerificationResult(success: false))->accepted)->toBeFalse();
});

it('keeps the provider verdict when a local assertion rejects the token', function (): void {
    $result = VerificationResult::fromResponse(['success' => true, 'hostname' => 'attacker.example'])
        ->rejectedLocally('hostname-mismatch');

    expect($result->success)->toBeTrue()
        ->and($result->accepted)->toBeFalse()
        ->and($result->passed())->toBeFalse()
        ->and($result->failed())->toBeTrue()
        ->and($result->rejectedBy)->toBe('hostname-mismatch')
        ->and($result->errorCodes)->toBe(['hostname-mismatch']);
});

it('can accept an outage explicitly without claiming hCaptcha said yes', function (): void {
    $result = new VerificationResult(
        success: false,
        errorCodes: ['service-unavailable'],
        serviceUnavailable: true,
        accepted: true,
    );

    expect($result->success)->toBeFalse()
        ->and($result->passed())->toBeTrue()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.unavailable');
});

it('serialises accepted alongside success', function (): void {
    $array = (new VerificationResult(success: true))->toArray();

    expect($array)->toHaveKeys(['success', 'accepted'])
        ->and($array['accepted'])->toBeTrue();
});

it('recognises the spent-token codes hCaptcha documents', function (string $code): void {
    $result = VerificationResult::fromResponse(['success' => false, 'error-codes' => [$code]]);

    expect($result->tokenAlreadyUsed())->toBeTrue()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.expired');
})->with([
    'already-seen-response',
    'invalid-or-already-seen-response',
    'token-already-used',
]);

it('treats an expired token like a spent one for the visitor', function (): void {
    $result = VerificationResult::fromResponse(['success' => false, 'error-codes' => ['expired-input-response']]);

    expect($result->tokenExpired())->toBeTrue()
        ->and($result->tokenAlreadyUsed())->toBeFalse()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.expired');
});

it('does not call a malformed token expired', function (): void {
    $result = VerificationResult::fromResponse(['success' => false, 'error-codes' => ['invalid-input-response']]);

    expect($result->tokenMalformed())->toBeTrue()
        ->and($result->tokenAlreadyUsed())->toBeFalse()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.failed');
});

it('gives an oversized token its own code and the generic message', function (): void {
    $result = VerificationResult::oversizedToken();

    expect($result->errorCodes)->toBe(['token-too-long'])
        ->and($result->tokenMalformed())->toBeTrue()
        ->and($result->tokenAlreadyUsed())->toBeFalse()
        ->and($result->messageKey())->toBe('hcaptcha::hcaptcha.failed');
});

it('classifies the owner-side codes hCaptcha documents as configuration errors', function (string $code): void {
    $result = VerificationResult::fromResponse(['success' => false, 'error-codes' => [$code]]);

    expect($result->isConfigurationError())->toBeTrue();
})->with([
    'missing-input-secret',
    'invalid-input-secret',
    'sitekey-secret-mismatch',
    'bad-request',
    'not-using-dummy-passcode',
    'not-using-dummy-secret',
]);

it('does not classify visitor-side codes as configuration errors', function (string $code): void {
    $result = VerificationResult::fromResponse(['success' => false, 'error-codes' => [$code]]);

    expect($result->isConfigurationError())->toBeFalse();
})->with([
    'missing-input-response',
    'invalid-input-response',
    'expired-input-response',
    'already-seen-response',
    'missing-remoteip',
    'invalid-remoteip',
]);
