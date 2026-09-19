<?php

declare(strict_types=1);

use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Tests\Fixtures\BrowserModalComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

const CSP_NONCE = 'browser-test-nonce';

/**
 * A named class rather than a route closure: Route::middleware() casts each
 * entry to a string to resolve it from the container, so a closure cannot be
 * registered directly.
 */
final class CspModalPolicyMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $policy = "default-src 'self'; script-src 'self' 'nonce-".CSP_NONCE."' 'unsafe-eval'; style-src 'self'; frame-src 'self'";

        return $next($request)->header('Content-Security-Policy', $policy);
    }
}

beforeEach(function (): void {
    Route::get('/csp', function () {
        $policy = "default-src 'self'; script-src 'self' 'nonce-".CSP_NONCE."'; style-src 'self'; frame-src 'self'";

        return response()->view('hcaptcha-tests::widget-form', ['action' => '/csp'])
            ->header('Content-Security-Policy', $policy);
    });

    // Modal starts closed, so the widget's first appearance on the page is a
    // Livewire update -- the @script fallback path in script.blade.php,
    // never the literal <script> tags. 'unsafe-eval' is on this policy
    // deliberately: Livewire evaluates a @script block's content through a
    // function constructor, which no nonce can authorise. Do not tighten
    // this policy to drop 'unsafe-eval' -- without it Livewire's own
    // evaluation of the fallback is blocked and the widget never renders,
    // which is exactly the documented limitation this test exists to prove
    // is real, not theoretical.
    Route::get('/csp-modal', BrowserModalComponent::class)->middleware(CspModalPolicyMiddleware::class);
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

/*
 * Covers the claim that the @script fallback's clone actually carries the
 * nonce under a real, browser-enforced policy -- not just that the source
 * carries the nonce in a code read. The widget's first appearance here is
 * a Livewire update (the modal opens closed), so this can only pass if the
 * cloned <script> tags in script.blade.php picked up the nonce from their
 * source tags and Chromium accepted it.
 */
it('renders a widget whose first appearance is a Livewire update, under a strict policy with the nonce', function (): void {
    HCaptchaManager::nonceUsing(fn (): string => CSP_NONCE);

    visit('/csp-modal')
        ->assertSee('closed')
        ->click('#toggle')
        ->waitForText('open')
        ->wait(0.5)
        ->assertPresent('#modal [data-fake-hcaptcha-checkbox]')
        ->assertNoJavaScriptErrors();
});
