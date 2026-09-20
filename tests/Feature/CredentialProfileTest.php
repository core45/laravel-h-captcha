<?php

declare(strict_types=1);

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\Rules\HCaptcha;
use Core45\HCaptcha\Support\VerificationContext;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    app()->setLocale('en');

    config()->set('hcaptcha.profiles', [
        'marketing' => [
            'sitekey' => '20000000-ffff-ffff-ffff-000000000002',
            'secret' => 'marketing-secret',
        ],
        'sitekey-only' => [
            'sitekey' => '30000000-ffff-ffff-ffff-000000000003',
        ],
        'secret-only' => [
            'secret' => 'secret-only-secret',
        ],
    ]);
});

function fakeProfileVerification(bool $success = true): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response([
            'success' => $success,
            'hostname' => 'example.test',
        ]),
    ]);
}

it('sends the profile sitekey and secret instead of the global ones', function (): void {
    fakeProfileVerification();

    app(Verifier::class)->verify(
        TestCase::TEST_TOKEN,
        null,
        new VerificationContext(field: 'f', profile: 'marketing'),
    );

    Http::assertSent(fn (Request $request): bool => $request['sitekey'] === '20000000-ffff-ffff-ffff-000000000002'
        && $request['secret'] === 'marketing-secret');
});

it('falls back to the global secret when the profile only sets a sitekey', function (): void {
    fakeProfileVerification();

    app(Verifier::class)->verify(
        TestCase::TEST_TOKEN,
        null,
        new VerificationContext(field: 'f', profile: 'sitekey-only'),
    );

    Http::assertSent(fn (Request $request): bool => $request['sitekey'] === '30000000-ffff-ffff-ffff-000000000003'
        && $request['secret'] === TestCase::TEST_SECRET);
});

it('falls back to the global sitekey when the profile only sets a secret', function (): void {
    fakeProfileVerification();

    app(Verifier::class)->verify(
        TestCase::TEST_TOKEN,
        null,
        new VerificationContext(field: 'f', profile: 'secret-only'),
    );

    Http::assertSent(fn (Request $request): bool => $request['sitekey'] === TestCase::TEST_SITEKEY
        && $request['secret'] === 'secret-only-secret');
});

it('throws for an unknown profile name', function (): void {
    fakeProfileVerification();

    app(Verifier::class)->verify(
        TestCase::TEST_TOKEN,
        null,
        new VerificationContext(field: 'f', profile: 'does-not-exist'),
    );
})->throws(InvalidArgumentException::class);

it('does not memoize the same token and field across different profiles', function (): void {
    fakeProfileVerification();

    $verifier = app(Verifier::class);

    $verifier->verify(TestCase::TEST_TOKEN, null, new VerificationContext(field: 'f', profile: 'marketing'));
    $verifier->verify(TestCase::TEST_TOKEN, null, new VerificationContext(field: 'f', profile: 'sitekey-only'));

    Http::assertSentCount(2);
});

it('memoizes the same token, field and profile together', function (): void {
    fakeProfileVerification();

    $verifier = app(Verifier::class);

    $verifier->verify(TestCase::TEST_TOKEN, null, new VerificationContext(field: 'f', profile: 'marketing'));
    $verifier->verify(TestCase::TEST_TOKEN, null, new VerificationContext(field: 'f', profile: 'marketing'));

    Http::assertSentCount(1);
});

it('lets an explicit sitekey in the context win over the profile sitekey', function (): void {
    fakeProfileVerification();

    app(Verifier::class)->verify(
        TestCase::TEST_TOKEN,
        null,
        new VerificationContext(field: 'f', sitekey: '40000000-ffff-ffff-ffff-000000000004', profile: 'marketing'),
    );

    Http::assertSent(fn (Request $request): bool => $request['sitekey'] === '40000000-ffff-ffff-ffff-000000000004'
        && $request['secret'] === 'marketing-secret');
});

it('renders the profile sitekey on the Blade component', function (): void {
    $html = (string) Blade::render('<x-hcaptcha profile="marketing" />');

    expect($html)->toContain('data-sitekey="20000000-ffff-ffff-ffff-000000000002"');
});

it('degrades instead of throwing when the profile sitekey is unusable', function (): void {
    config()->set('hcaptcha.profiles.marketing.sitekey', 'default_sitekey');
    config()->set('hcaptcha.sitekey', null);
    config()->set('app.debug', false);

    $html = (string) Blade::render('<x-hcaptcha profile="marketing" />');

    expect($html)
        ->not->toContain('data-hcaptcha')
        ->not->toContain('js.hcaptcha.com');
});

it('selects the profile secret through the middleware third parameter', function (): void {
    fakeProfileVerification();

    Route::post('/profile-guarded', fn (): string => 'ok')
        ->middleware('hcaptcha:h-captcha-response,,marketing');

    $this->post('/profile-guarded', ['h-captcha-response' => TestCase::TEST_TOKEN])
        ->assertOk();

    Http::assertSent(fn (Request $request): bool => $request['secret'] === 'marketing-secret');
});

it('sends the profile secret when the validation rule carries the profile', function (): void {
    fakeProfileVerification();

    $validator = Validator::make(
        ['h-captcha-response' => TestCase::TEST_TOKEN],
        ['h-captcha-response' => [new HCaptcha(profile: 'marketing')]],
    );

    expect($validator->passes())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request['secret'] === 'marketing-secret');
});
