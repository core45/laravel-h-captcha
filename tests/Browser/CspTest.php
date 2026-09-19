<?php

declare(strict_types=1);

use Core45\HCaptcha\HCaptchaManager;
use Illuminate\Support\Facades\Route;

const CSP_NONCE = 'browser-test-nonce';

beforeEach(function (): void {
    Route::get('/csp', function () {
        $policy = "default-src 'self'; script-src 'self' 'nonce-".CSP_NONCE."'; style-src 'self'; frame-src 'self'";

        return response()->view('hcaptcha-tests::widget-form', ['action' => '/csp'])
            ->header('Content-Security-Policy', $policy);
    });
});

afterEach(function (): void {
    HCaptchaManager::nonceUsing(null);
});

it('renders and solves under a strict script-src when the nonce is supplied', function (): void {
    HCaptchaManager::nonceUsing(fn (): string => CSP_NONCE);

    $page = visit('/csp')->assertPresent('#hcaptcha-page-1 [data-fake-hcaptcha-checkbox]');

    solveCaptcha($page, 'hcaptcha-page-1')
        ->assertValueIsNot('#hcaptcha-page-1-response', '')
        ->assertNoJavaScriptErrors();
});

/*
 * Negative control: the same policy without a nonce blocks the inline
 * bootstrap, so the onload callback never exists and nothing renders. This
 * proves Chromium enforces the header in the test, so the positive case
 * above is meaningful.
 */
it('is blocked by the same policy without a nonce', function (): void {
    visit('/csp')
        ->wait(0.3)
        ->assertMissing('[data-fake-hcaptcha-checkbox]')
        ->assertScript('typeof window.core45HCaptcha', 'undefined');
});
