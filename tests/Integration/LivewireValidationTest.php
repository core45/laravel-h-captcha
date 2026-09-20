<?php

declare(strict_types=1);

use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Tests\Fixtures\CaptchaGuardedComponent;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Livewire handles up to 200 components in one HTTP request, and the verifier
 * is request-scoped, so two components validating the same property name
 * share one memo. Without the action in the scope, the second component rode
 * on the first one's solved captcha. With it, the second pays a real request
 * and hCaptcha rejects the spent token.
 */
it('does not let a second Livewire component reuse a verdict for the same property name', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::sequence()
            ->push(['success' => true, 'hostname' => 'localhost'])
            ->push(['success' => false, 'error-codes' => ['already-seen-response']]),
    ]);

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', TestCase::TEST_TOKEN)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', TestCase::TEST_TOKEN)
        ->call('submit')
        ->assertHasErrors(['captcha'])
        ->assertSet('submitted', false);

    Http::assertSentCount(2);
});

it('still spends the token once when one Livewire component validates the same field twice', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'localhost']),
    ]);

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', TestCase::TEST_TOKEN)
        ->call('submitTwice')
        ->assertHasNoErrors()
        ->assertSet('submitted', true);

    Http::assertSentCount(1);
});

it('tells the browser to reset the widget after a token was verified inside Livewire', function (): void {
    Http::fake(['api.hcaptcha.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', 'spent-token')
        ->call('submit')
        ->assertHasErrors('captcha')
        ->assertDispatched(HCaptchaManager::RESET_EVENT, field: 'captcha');
});

it('also resets after a successful verification, because the token is spent either way', function (): void {
    Http::fake(['api.hcaptcha.com/*' => Http::response(['success' => true, 'hostname' => 'localhost'])]);
    config()->set('hcaptcha.hostnames', 'localhost');

    Livewire::test(CaptchaGuardedComponent::class)
        ->set('captcha', 'good-token')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched(HCaptchaManager::RESET_EVENT, field: 'captcha');
});

it('does not dispatch a reset when no token was submitted', function (): void {
    Livewire::test(CaptchaGuardedComponent::class)
        ->call('submit')
        ->assertHasErrors('captcha')
        ->assertNotDispatched(HCaptchaManager::RESET_EVENT);
});
