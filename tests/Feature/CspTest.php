<?php

declare(strict_types=1);

use Core45\HCaptcha\HCaptchaManager;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

afterEach(function (): void {
    HCaptchaManager::nonceUsing(null);
});

it('emits no nonce attribute by default', function (): void {
    expect((string) Blade::render('<x-hcaptcha />'))->not->toContain('nonce=');
});

it('puts a registered nonce on both script tags', function (): void {
    HCaptchaManager::nonceUsing(fn (): string => 'abc123');

    $html = (string) Blade::render('<x-hcaptcha />');

    // The inline bootstrap tag carries data-hcaptcha-bootstrap and the SDK
    // tag carries data-hcaptcha-sdk for the Livewire @script fallback to
    // find (see resources/views/script.blade.php); the nonce sits next to
    // data-navigate-once on each tag regardless of that marker.
    expect(substr_count($html, 'nonce="abc123"'))->toBe(2)
        ->and($html)->toContain('<script nonce="abc123" data-navigate-once data-hcaptcha-bootstrap>')
        ->and($html)->toContain('data-navigate-once data-hcaptcha-sdk nonce="abc123"></script>');
});

it('falls back to the Vite nonce when no resolver is registered', function (): void {
    Vite::useCspNonce('vite-nonce');

    expect(substr_count((string) Blade::render('<x-hcaptcha />'), 'nonce="vite-nonce"'))->toBe(2);
});

it('escapes the nonce value', function (): void {
    HCaptchaManager::nonceUsing(fn (): string => '"><script>');

    expect((string) Blade::render('<x-hcaptcha />'))->not->toContain('"><script>');
});

it('lists the origins hCaptcha requires per CSP directive', function (): void {
    expect(HCaptchaManager::cspDirectives())->toBe([
        'script-src' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
        'frame-src' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
        'style-src' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
        'connect-src' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
    ]);
});
