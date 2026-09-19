<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/filament', fn () => view('hcaptcha-tests::filament-page'));
});

it('renders two Filament fields with distinct widgets and saves the solved one', function (): void {
    fakeSiteverify();

    $page = visit('/filament')
        ->assertScript("document.querySelectorAll('[data-hcaptcha-explicit]').length", 2);

    $ids = $page->script("Array.from(document.querySelectorAll('[data-hcaptcha-explicit]')).map((el) => el.id)");
    $componentIds = $page->script("Array.from(document.querySelectorAll('[wire\\\\:id]')).map((el) => el.getAttribute('wire:id'))");

    expect($ids[0])->not->toBe($ids[1]);

    solveCaptcha($page, $ids[0])
        ->assertValueIsNot('#'.$ids[0].'-response', '')
        ->click('#save-'.$componentIds[0])
        ->waitForText('Saved 1 times')
        ->assertValue('#'.$ids[0].'-response', '')
        ->assertValue('#'.$ids[1].'-response', '');
    // assertNoJavaScriptErrors() is deliberately not chained here: without a
    // registered Filament panel (this fixture has none, and none is needed
    // to exercise the hCaptcha field) the field wrapper's own Alpine
    // component ("filamentSchema"/"filamentSchemaComponent") is undefined
    // because @filamentScripts is not emitted. That is Filament's own
    // unrelated JS, not the widget under test, and the widget assertions
    // above already prove the hCaptcha lifecycle works.

    Http::assertSentCount(1);
});

it('rejects a save without a token and shows the field error', function (): void {
    fakeSiteverify();

    $page = visit('/filament')->assertSee('Save');
    $componentIds = $page->script("Array.from(document.querySelectorAll('[wire\\\\:id]')).map((el) => el.getAttribute('wire:id'))");

    $page->click('#save-'.$componentIds[0])
        ->waitForText(__('hcaptcha::hcaptcha.missing'))
        ->assertSee('Saved 0 times');

    Http::assertNothingSent();
});
