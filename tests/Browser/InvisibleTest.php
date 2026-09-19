<?php

declare(strict_types=1);

use Core45\HCaptcha\Rules\HCaptcha as HCaptchaRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/invisible', fn () => view('hcaptcha-tests::widget-form', ['action' => '/invisible', 'options' => ['size' => 'invisible']]));

    Route::post('/invisible', function (Request $request): string {
        $request->validate(['name' => ['required'], 'h-captcha-response' => [new HCaptchaRule]]);

        return 'Thanks '.$request->string('name');
    });

    Route::get('/invisible-livewire', fn () => view('hcaptcha-tests::invisible-livewire'));
});

it('executes the challenge on submit and completes a plain form post', function (): void {
    fakeSiteverify();

    visit('/invisible')
        ->assertMissing('[data-fake-hcaptcha-checkbox]')
        ->type('#name', 'Ada')
        ->click('#send')
        ->waitForText('Thanks Ada');

    Http::assertSentCount(1);
});

it('shows the error message and allows another attempt when the challenge fails', function (): void {
    fakeSiteverify();

    $page = visit('/invisible')->type('#name', 'Ada');

    $page->script("window.__fakeHCaptcha.failNext = 'challenge-closed'");

    $page->click('#send')
        ->waitForText(__('hcaptcha::hcaptcha.widget_error'))
        ->assertDontSee('Thanks Ada')
        ->assertValue('#hcaptcha-page-1-response', '');

    $page->click('#send')->waitForText('Thanks Ada');

    Http::assertSentCount(1);
});

it('drops a second submit while a challenge is pending', function (): void {
    fakeSiteverify();

    $page = visit('/invisible')->type('#name', 'Ada');

    // Make execute() hang until released, so two clicks land while pending.
    // The trailing `true;` pins the completion value to something the driver
    // can serialise; without it this call misbehaves.
    $page->script('window.__slow = new Promise((resolve) => { window.__release = resolve; }); const original = window.hcaptcha.execute; window.hcaptcha.execute = (id, options) => window.__slow.then(() => original(id, options)); true;');

    $page->click('#send')
        ->click('#send')
        ->assertDontSee('Thanks Ada');

    $page->script('window.__release()');

    $page->waitForText('Thanks Ada');

    Http::assertSentCount(1);
});

it('does not resubmit or loop when the challenge resolves without a token', function (): void {
    fakeSiteverify();

    $page = visit('/invisible')->type('#name', 'Ada');

    $page->script('window.__fakeHCaptcha.emptyNext = true;');

    $page->click('#send')
        ->wait(0.5)
        ->assertDontSee('Thanks Ada')
        ->assertValue('#hcaptcha-page-1-response', '');

    // A single click must trigger exactly one challenge. A resubmit that
    // ignored the empty token would re-enter the interceptor and start a
    // second one, one per task, without ever posting the form.
    expect($page->script('window.__fakeHCaptcha.tokens'))->toBe(0);

    Http::assertNothingSent();
});

it('executes on wire:submit inside Livewire and resets after the round trip', function (): void {
    fakeSiteverify();

    $page = visit('/invisible-livewire')
        ->assertSee('Send form')
        ->assertMissing('[data-fake-hcaptcha-checkbox]')
        ->type('#name-form', 'Ada')
        ->click('#send-form')
        ->waitForText('Submitted 1 times');

    $widgetId = (string) $page->script("document.querySelector('[data-hcaptcha-explicit]').id");

    $page->assertValue('#'.$widgetId.'-response', '')
        ->assertNoJavaScriptErrors();

    Http::assertSentCount(1);
});
