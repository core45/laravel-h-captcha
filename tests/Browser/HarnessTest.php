<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\BrowserTestCase;
use Core45\HCaptcha\Tests\Fixtures\BrowserCounterComponent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

it('serves a Livewire component and handles a round trip', function (): void {
    Route::get('/harness-livewire', BrowserCounterComponent::class);

    visit('/harness-livewire')
        ->assertSee('0')
        ->click('#inc')
        ->assertSee('1')
        ->assertNoJavaScriptErrors();
});

it('applies Http::fake to requests made by the in-process server', function (): void {
    Http::fake(['example.com/*' => Http::response(['ok' => true])]);

    Route::get('/harness-http', fn (): string => Http::get('https://example.com/x')->json('ok') ? 'faked' : 'real');

    visit('/harness-http')->assertSee('faked');
});

it('serves the fake api.js stub and calls the onload callback', function (): void {
    Route::get('/harness-stub', fn (): string => '<html><body><script>window.hit = false; window.cb = function () { window.hit = true; };</script>'
        .'<script src="'.BrowserTestCase::FAKE_API_PATH.'?onload=cb"></script></body></html>');

    visit('/harness-stub')
        ->assertScript('window.hit', true)
        ->assertScript('typeof window.hcaptcha.render', 'function')
        ->assertScript('window.__fakeHCaptcha.loads', 1);
});
