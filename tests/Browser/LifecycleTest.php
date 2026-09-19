<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\Fixtures\BrowserModalComponent;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;

beforeEach(function (): void {
    Route::get('/two-forms', fn () => view('hcaptcha-tests::two-forms'));
    Route::get('/modal', BrowserModalComponent::class);
    Route::get('/navigate-one', fn () => view('hcaptcha-tests::navigate-one'));
    Route::get('/navigate-two', fn () => view('hcaptcha-tests::navigate-two'));
    Route::get('/callbacks', fn (): string => view('hcaptcha-tests::layouts.plain', [
        'content' => new HtmlString(
            '<script>window.spy = { solved: [], errors: [] }; window.spy.onSolved = (t) => window.spy.solved.push(t); window.spy.onError = (c) => window.spy.errors.push(c);</script>'
            .Blade::render('<x-hcaptcha :options="[\'callback\' => \'spy.onSolved\', \'error-callback\' => \'spy.onError\']" />')
        ),
    ])->render());
});

it('renders two identical components with independent widgets', function (): void {
    $page = visit('/two-forms')
        ->assertSee('Send alpha')
        ->wait(0.2)
        ->assertScript("document.querySelectorAll('[data-hcaptcha-explicit]').length", 2);

    $ids = $page->script("Array.from(document.querySelectorAll('[data-hcaptcha-explicit]')).map((el) => el.id)");

    expect($ids)->toBeArray()->toHaveCount(2)
        ->and($ids[0])->not->toBe($ids[1]);

    solveCaptcha($page, $ids[0])
        ->assertValueIsNot('#'.$ids[0].'-response', '')
        ->assertValue('#'.$ids[1].'-response', '');
});

it('renders a widget inside a reopened modal and prunes the closed one', function (): void {
    // The observer renders and prunes on the next animation frame, hence the
    // short waits after each Livewire round trip.
    $page = visit('/modal')
        ->assertSee('closed')
        ->click('#toggle')
        ->waitForText('open')
        ->wait(0.5)
        ->assertPresent('#modal [data-fake-hcaptcha-checkbox]')
        ->assertScript('Object.keys(window.core45HCaptcha.widgets).length', 1)
        ->click('#toggle')
        ->waitForText('closed')
        ->wait(0.5)
        ->assertMissing('#modal')
        ->assertScript('Object.keys(window.core45HCaptcha.widgets).length', 0)
        ->click('#toggle')
        ->waitForText('open')
        ->wait(0.5)
        ->assertPresent('#modal [data-fake-hcaptcha-checkbox]');

    $widgetId = (string) $page->script("document.querySelector('#modal [data-hcaptcha-explicit]').id");

    solveCaptcha($page, $widgetId)
        ->assertValueIsNot('#'.$widgetId.'-response', '')
        ->assertNoJavaScriptErrors();
});

it('keeps one SDK load across wire:navigate and renders the next page widget', function (): void {
    $page = visit('/navigate-one')
        ->assertSee('Page one')
        ->assertPresent('[data-fake-hcaptcha-checkbox]')
        ->assertScript('window.__fakeHCaptcha.loads', 1)
        ->click('#to-two')
        ->waitForText('Page two')
        ->wait(0.2)
        ->assertPresent('[data-fake-hcaptcha-checkbox]')
        ->assertScript('window.__fakeHCaptcha.loads', 1);

    $widgetId = (string) $page->script("document.querySelector('[data-hcaptcha-explicit]').id");

    solveCaptcha($page, $widgetId)
        ->assertValueIsNot('#'.$widgetId.'-response', '')
        ->assertNoJavaScriptErrors();
});

it('invokes documented custom callbacks after publishing the token', function (): void {
    $page = visit('/callbacks')->assertPresent('[data-fake-hcaptcha-checkbox]');

    solveCaptcha($page, 'hcaptcha-page-1')
        ->assertScript('window.spy.solved.length', 1)
        ->assertValueIsNot('#hcaptcha-page-1-response', '');

    $page->script("window.__fakeHCaptcha.failNext = 'challenge-closed'");

    solveCaptcha($page, 'hcaptcha-page-1')
        ->assertScript('window.spy.errors[0]', 'challenge-closed')
        ->assertValue('#hcaptcha-page-1-response', '')
        ->assertSee(__('hcaptcha::hcaptcha.widget_error'));
});
