<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\Fixtures\BrowserGuardedForm;
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
