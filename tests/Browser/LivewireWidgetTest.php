<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\Fixtures\BrowserGuardedForm;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/guarded', BrowserGuardedForm::class);
});

/*
 * Regression for spec finding 6: the widget container lives inside wire:ignore
 * and kept its first id, while the hidden input outside it was re-rendered
 * with a new random id. After one re-render the callback wrote the token
 * nowhere. Deterministic ids keep both halves in step.
 */
it('still publishes the token after a Livewire re-render', function (): void {
    $page = visit('/guarded')
        ->assertSee('Rendered 1 times')
        ->click('#refresh-form')
        ->waitForText('Rendered 2 times');

    $widgetId = (string) $page->script("document.querySelector('[data-hcaptcha-explicit]').id");

    solveCaptcha($page, $widgetId)
        ->assertValueIsNot('#'.$widgetId.'-response', '')
        ->assertNoJavaScriptErrors();
});

it('resets only the submitted widget after an unrelated validation failure and accepts a fresh solve', function (): void {
    fakeSiteverify();

    $page = visit('/guarded')->assertSee('Submitted 0 times');
    $widgetId = (string) $page->script("document.querySelector('[data-hcaptcha-explicit]').id");

    // Name left empty: the captcha token is verified (and spent) anyway.
    solveCaptcha($page, $widgetId)
        ->click('#send-form')
        ->waitForText('The name field is required.')
        ->assertSee('Submitted 0 times')
        ->assertValue('#'.$widgetId.'-response', '')
        ->assertScript("window.__fakeHCaptcha.widgets[window.core45HCaptcha.widgets['".$widgetId."']].resets", 1);

    $page->type('#name-form', 'Ada');

    solveCaptcha($page, $widgetId)
        ->click('#send-form')
        ->waitForText('Submitted 1 times')
        ->assertNoJavaScriptErrors();

    Http::assertSentCount(2);
});

it('leaves a second component alone when the first one resets', function (): void {
    fakeSiteverify();

    Route::get('/two-forms', fn () => view('hcaptcha-tests::two-forms'));

    $page = visit('/two-forms')->assertSee('Send alpha');
    $ids = $page->script("Array.from(document.querySelectorAll('[data-hcaptcha-explicit]')).map((el) => el.id)");

    solveCaptcha($page, $ids[0]);
    solveCaptcha($page, $ids[1]);

    $page->click('#send-alpha')
        ->waitForText('The name field is required.')
        ->assertValue('#'.$ids[0].'-response', '')
        ->assertValueIsNot('#'.$ids[1].'-response', '');
});
