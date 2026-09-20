<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\Fixtures\HCaptchaFormComponent;
use Core45\HCaptcha\Tests\Fixtures\HCaptchaFormWithSitekeyComponent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeHCaptcha(bool $success): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response([
            'success' => $success,
            'hostname' => 'localhost',
            'challenge_ts' => now()->toIso8601String(),
        ]),
    ]);
}

it('passes validation when hCaptcha accepts the token', function (): void {
    fakeHCaptcha(success: true);

    Livewire::test(HCaptchaFormComponent::class)
        ->fillForm(['h-captcha-response' => 'accepted-token'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('fails validation when hCaptcha rejects the token, proving dehydrated(false) does not disable validation', function (): void {
    fakeHCaptcha(success: false);

    Livewire::test(HCaptchaFormComponent::class)
        ->fillForm(['h-captcha-response' => 'rejected-token'])
        ->call('save')
        ->assertHasFormErrors(['h-captcha-response']);
});

it('does not dehydrate the token into the saved form state', function (): void {
    fakeHCaptcha(success: true);

    $state = Livewire::test(HCaptchaFormComponent::class)
        ->fillForm(['h-captcha-response' => 'accepted-token'])
        ->instance()
        ->save();

    expect($state)->not->toHaveKey('h-captcha-response');
});

it('reports the hCaptcha-specific message on an empty token, not the generic "required" message', function (): void {
    fakeHCaptcha(success: false);

    Livewire::test(HCaptchaFormComponent::class)
        ->fillForm(['h-captcha-response' => ''])
        ->call('save')
        ->assertHasFormErrors(['h-captcha-response' => __('hcaptcha::hcaptcha.missing')]);
});

it('renders the widget markup with the explicit-mode and wire:ignore attributes', function (): void {
    fakeHCaptcha(success: true);

    Livewire::test(HCaptchaFormComponent::class)
        ->assertSeeHtml('data-hcaptcha-explicit')
        ->assertSeeHtml('wire:ignore');
});

it('verifies against the sitekey the field was rendered with', function (): void {
    fakeHCaptcha(success: true);

    Livewire::test(HCaptchaFormWithSitekeyComponent::class)
        ->fillForm(['h-captcha-response' => 'accepted-token'])
        ->call('save')
        ->assertHasNoFormErrors();

    Http::assertSent(fn (Request $request): bool => $request['sitekey'] === '20000000-ffff-ffff-ffff-000000000002');
});

it('renders no widget but still fails closed when no sitekey is configured', function (): void {
    config()->set('hcaptcha.sitekey', null);
    config()->set('app.debug', true);
    fakeHCaptcha(success: true);

    Livewire::test(HCaptchaFormComponent::class)
        ->assertDontSeeHtml('data-hcaptcha-explicit')
        ->assertSee('HCAPTCHA_SITEKEY is not set')
        ->call('save')
        ->assertHasFormErrors(['h-captcha-response' => __('hcaptcha::hcaptcha.missing')]);
});

it('renders nothing for the field in production when no sitekey is configured', function (): void {
    config()->set('hcaptcha.sitekey', null);
    config()->set('app.debug', false);

    Livewire::test(HCaptchaFormComponent::class)
        ->assertDontSeeHtml('data-hcaptcha-explicit')
        ->assertDontSee('HCAPTCHA_SITEKEY is not set');
});

it('scopes the widget id to the Livewire component', function (): void {
    fakeHCaptcha(success: true);

    $component = Livewire::test(HCaptchaFormComponent::class);

    $component->assertSeeHtml('id="hcaptcha-'.$component->instance()->getId().'-data-h-captcha-response"');
});

it('names the state path on the response input so a reset finds it', function (): void {
    fakeHCaptcha(success: true);

    Livewire::test(HCaptchaFormComponent::class)
        ->assertSeeHtml('data-hcaptcha-field="data.h-captcha-response"');
});
