<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\BrowserTestCase;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

uses(TestCase::class)->in('Unit', 'Feature');
uses(BrowserTestCase::class)->in('Browser');

/**
 * Fake every siteverify call with a fixed verdict. The hostname matches the
 * hcaptcha.hostnames value BrowserTestCase configures.
 */
function fakeSiteverify(bool $success = true): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response([
            'success' => $success,
            'hostname' => 'localhost',
            'challenge_ts' => now()->toIso8601String(),
        ]),
    ]);
}

/**
 * Click the fake widget's "I am human" button for one widget container id.
 *
 * Every visit()/click()/type()/assert*() chain off Pest's browser plugin
 * returns Pest\Browser\Api\AwaitableWebpage, not Webpage -- they are
 * unrelated classes, so this accepts and returns either.
 */
function solveCaptcha(Webpage|AwaitableWebpage $page, string $widgetId): Webpage|AwaitableWebpage
{
    $page->script(sprintf(
        "document.querySelector('#%s [data-fake-hcaptcha-checkbox]').click()",
        $widgetId,
    ));

    return $page;
}
