<?php

declare(strict_types=1);

use Core45\HCaptcha\Contracts\Verifier;
use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Octane keeps one Laravel application booted across many HTTP requests in
 * the same PHP process. HCaptchaServiceProvider binds Verifier,
 * HCaptchaManager and VerificationLogger with $this->app->scoped(), not
 * singleton(), specifically so Octane discards them between requests --
 * scoped() bindings are reset by Application::forgetScopedInstances(), which
 * Octane's RequestHandled/RequestTerminated pipeline calls after every
 * request. These tests simulate two requests in one process by calling that
 * same method Octane calls, and assert that neither a memoized verdict nor a
 * widget-id counter survives the boundary.
 */
it('does not reuse a memoized verdict from a previous simulated request', function (): void {
    Http::fake([
        'api.hcaptcha.com/*' => Http::response([
            'success' => true,
            'hostname' => 'example.test',
        ]),
    ]);

    // Request 1: verify the same token twice under the same scope. The
    // scoped Verifier instance memoizes the verdict for the rest of this
    // "request", so only one HTTP call goes out. (Verify() only memoizes
    // when a scope is given -- an unscoped call never hits the memo.)
    $verifierRequestOne = app(Verifier::class);
    $verifierRequestOne->verify(TestCase::TEST_TOKEN, null, 'octane-test');
    $verifierRequestOne->verify(TestCase::TEST_TOKEN, null, 'octane-test');

    Http::assertSentCount(1);

    // Octane calls this between requests to drop every scoped() binding.
    app()->forgetScopedInstances();

    // Request 2: same token, same scope, again. A fresh Verifier instance
    // must be resolved -- if the old memo survived, this would not hit the
    // network at all and the count below would stay at 1.
    $verifierRequestTwo = app(Verifier::class);

    expect($verifierRequestTwo)->not->toBe($verifierRequestOne);

    $verifierRequestTwo->verify(TestCase::TEST_TOKEN, null, 'octane-test');

    Http::assertSentCount(2);
});

it('restarts the widget id counters after a simulated request boundary', function (): void {
    $managerRequestOne = app(HCaptchaManager::class);

    expect($managerRequestOne->widgetId())->toBe('hcaptcha-page-1')
        ->and($managerRequestOne->widgetId())->toBe('hcaptcha-page-2');

    app()->forgetScopedInstances();

    $managerRequestTwo = app(HCaptchaManager::class);

    expect($managerRequestTwo)->not->toBe($managerRequestOne)
        // If the counter leaked across the boundary this would come back as
        // hcaptcha-page-3 instead of restarting at 1.
        ->and($managerRequestTwo->widgetId())->toBe('hcaptcha-page-1');
});
