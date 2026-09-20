<?php

declare(strict_types=1);

use Core45\HCaptcha\Facades\HCaptcha;
use Core45\HCaptcha\Testing\FakeVerifier;
use Illuminate\Support\Facades\Blade;

/**
 * @param  array<string, mixed>  $data
 */
function renderFakeWidget(string $template = '<x-hcaptcha />', array $data = []): string
{
    return (string) Blade::render($template, $data);
}

it('renders the fake widget instead of the real one when HCaptcha::fake() is bound', function (): void {
    HCaptcha::fake();

    $html = renderFakeWidget();

    expect($html)
        ->toContain('data-hcaptcha-fake')
        ->not->toContain('js.hcaptcha.com')
        ->not->toContain('data-hcaptcha-explicit');
});

it('fills the hidden input with the fake token under the right field name', function (): void {
    HCaptcha::fake();

    $html = renderFakeWidget();

    expect($html)
        ->toContain('value="'.FakeVerifier::TOKEN.'"')
        ->toContain('name="h-captcha-response"');
});

it('names a Livewire model field on the hidden input instead of a request field', function (): void {
    HCaptcha::fake();

    $html = renderFakeWidget('<x-hcaptcha model="captchaToken" />');

    expect($html)
        ->toContain('value="'.FakeVerifier::TOKEN.'"')
        ->toContain('wire:model="captchaToken"')
        ->toContain('data-hcaptcha-field="captchaToken"');
});

it('renders a checkbox button that publishes the fake token', function (): void {
    HCaptcha::fake();

    $html = renderFakeWidget();

    expect($html)->toContain('data-fake-hcaptcha-checkbox');
});

it('still renders the real widget normally when the fake is not bound', function (): void {
    $html = renderFakeWidget();

    expect($html)
        ->toContain('data-hcaptcha')
        ->toContain('data-hcaptcha-explicit')
        ->toContain('js.hcaptcha.com')
        ->not->toContain('data-hcaptcha-fake')
        ->not->toContain('data-fake-hcaptcha-checkbox');
});
