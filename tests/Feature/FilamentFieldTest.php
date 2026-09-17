<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\Fixtures\HCaptchaFormComponent;
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
