<?php

declare(strict_types=1);

use Core45\HCaptcha\Rules\HCaptcha as HCaptchaRule;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/widget', fn () => view('hcaptcha-tests::widget-form', ['action' => '/widget']));

    Route::post('/widget', function (Request $request): string {
        $request->validate(['name' => ['required'], 'h-captcha-response' => [new HCaptchaRule]]);

        return 'Thanks '.$request->string('name');
    });
});

it('publishes the token into the hidden input when the visitor solves the widget', function (): void {
    $page = visit('/widget')->assertPresent('#hcaptcha-page-1 [data-fake-hcaptcha-checkbox]');

    solveCaptcha($page, 'hcaptcha-page-1')
        ->assertValueIsNot('#hcaptcha-page-1-response', '')
        ->assertNoJavaScriptErrors();
});

it('submits a solved token that the server verifies', function (): void {
    fakeSiteverify();

    $page = visit('/widget')->type('#name', 'Ada');

    solveCaptcha($page, 'hcaptcha-page-1')
        ->click('#send')
        ->assertSee('Thanks Ada');

    Http::assertSent(fn (ClientRequest $request): bool => str_starts_with((string) $request['response'], 'fake-token-'));
});
