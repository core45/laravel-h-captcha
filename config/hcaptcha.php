<?php

declare(strict_types=1);

/*
 * Site key and secret key come from https://dashboard.hcaptcha.com/sites
 *
 * Nothing in this file may call app(), trans(), or any other container helper:
 * `php artisan config:cache` evaluates it once and freezes the result.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Both default to null on purpose. A missing secret throws instead of
    | silently failing every verification, which is what a placeholder default
    | would do.
    |
    | hCaptcha publishes a test pair that always verifies successfully:
    |   sitekey 10000000-ffff-ffff-ffff-000000000001
    |   secret  0x0000000000000000000000000000000000000000
    |
    | CAPTCHA_SITEKEY and CAPTCHA_SECRET are the env keys
    | thinhbuzz/laravel-h-captcha used, read here so that package can be swapped
    | out without touching an existing .env. HCAPTCHA_* wins, so a project that
    | has already migrated is not overridden by a stale key it forgot to delete.
    |
    | That package defaulted these to the literals 'default_sitekey' and
    | 'default_secret', so a half-configured install carries those strings.
    | Both are treated as "not configured" rather than as credentials -- see
    | HCaptchaManager::PLACEHOLDER_CREDENTIALS.
    |
    */

    'sitekey' => env('HCAPTCHA_SITEKEY', env('CAPTCHA_SITEKEY')),

    'secret' => env('HCAPTCHA_SECRET', env('CAPTCHA_SECRET')),

    /*
    |--------------------------------------------------------------------------
    | Verification endpoint
    |--------------------------------------------------------------------------
    */

    'endpoint' => env('HCAPTCHA_ENDPOINT', 'https://api.hcaptcha.com/siteverify'),

    'timeout' => (int) env('HCAPTCHA_TIMEOUT', 10),

    // Additional attempts after the first, on a transport error or non-2xx
    // status. 0 means one attempt. Retrying a single-use token is a gamble:
    // if the first attempt reached hCaptcha and only the reply was lost, the
    // retry is told already-seen-response.
    'retries' => (int) env('HCAPTCHA_RETRIES', 0),

    /*
    | Tokens longer than this are rejected without an HTTP call. hCaptcha tokens
    | are a few hundred to a few thousand characters; without a cap, an
    | unauthenticated request can have the package proxy a multi-megabyte body
    | to hCaptcha while holding a PHP worker for the whole timeout.
    */

    'max_token_length' => (int) env('HCAPTCHA_MAX_TOKEN_LENGTH', 8192),

    /*
    |--------------------------------------------------------------------------
    | Failure behaviour
    |--------------------------------------------------------------------------
    |
    | When hCaptcha is unreachable the verification fails closed: the form is
    | rejected. Set fail_open to true to accept the submission instead, which
    | trades spam exposure for availability. The outage is logged either way.
    |
    */

    'fail_open' => (bool) env('HCAPTCHA_FAIL_OPEN', false),

    /*
    |--------------------------------------------------------------------------
    | Response assertions
    |--------------------------------------------------------------------------
    |
    | send_sitekey      Include the sitekey in the verification request so
    |                   hCaptcha itself rejects a token minted against a
    |                   different sitekey (sitekey-secret-mismatch).
    | hostnames         Hostnames the response is allowed to report.
    | hostnames_strict  Also reject a response whose hostname is missing or
    |                   `not-provided`.
    | max_score         Enterprise accounts return a *risk* score where higher
    |                   is more bot-like -- the inverse of reCAPTCHA v3. null
    |                   skips the check. Accounts without scoring never return
    |                   the field.
    |
    | Your sitekey is public -- it is in your HTML. An attacker can embed it on
    | their own page, solve the challenge there (or buy solutions from a farm),
    | and post the token to your form. siteverify answers `success: true`,
    | because the token is genuine.
    |
    | Two layers limit that. The authoritative one is the domain allowlist on
    | the sitekey in the hCaptcha dashboard, which is off by default for new
    | sitekeys: turn it on. The second is this application-side check of the
    | hostname hCaptcha reports. hCaptcha documents that hostname as derived
    | from the browser and unsuitable for authentication, and says it may be
    | `not-provided` under load, so treat this check as a policy that catches
    | careless misuse, not as proof of origin.
    |
    | `hostnames` defaults to the host of APP_URL. Set HCAPTCHA_HOSTNAMES to a
    | comma-separated list for multi-domain installs. Setting it to an empty
    | string disables the check, which is logged as an error once per process.
    | A missing or `not-provided` hostname passes with a warning unless
    | HCAPTCHA_HOSTNAMES_STRICT is true.
    |
    */

    'send_sitekey' => (bool) env('HCAPTCHA_SEND_SITEKEY', true),

    'hostnames' => env('HCAPTCHA_HOSTNAMES', parse_url((string) env('APP_URL'), PHP_URL_HOST)),

    'hostnames_strict' => (bool) env('HCAPTCHA_HOSTNAMES_STRICT', false),

    'max_score' => env('HCAPTCHA_MAX_SCORE') !== null
        ? (float) env('HCAPTCHA_MAX_SCORE')
        : null,

    /*
    |--------------------------------------------------------------------------
    | Widget
    |--------------------------------------------------------------------------
    |
    | field       Name of the POST field the widget writes its token into.
    |             hCaptcha's own script hard-codes h-captcha-response.
    | locale      null resolves the application locale when the widget renders.
    |             Do not put app()->getLocale() here; see the note at the top.
    | attributes  Rendered onto the widget div as data-* attributes.
    | script      Whether the widget renders the hCaptcha api.js tag itself.
    |
    */

    'field' => 'h-captcha-response',

    'locale' => null,

    'attributes' => [
        'theme' => 'light',
        'size' => 'normal',
    ],

    'script' => [
        'enabled' => true,
        'url' => 'https://js.hcaptcha.com/1/api.js',
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | Every verification attempt can be recorded. The raw token is never
    | stored -- it is a single-use credential -- only a SHA-256 hash of it, so
    | a replay can still be correlated.
    |
    */

    'logging' => [
        'enabled' => (bool) env('HCAPTCHA_LOGGING', false),

        'table' => 'hcaptcha_verifications',

        'connection' => env('HCAPTCHA_LOGGING_CONNECTION'),

        // Personal data. Off by default; enable only with a lawful basis.
        //
        // store_url records the path without the query string. A query string
        // routinely carries signed-URL signatures, password-reset tokens and
        // email addresses, none of which belong in a 90-day audit table.
        'store_ip' => (bool) env('HCAPTCHA_LOG_IP', false),
        'store_user_agent' => (bool) env('HCAPTCHA_LOG_USER_AGENT', false),
        'store_url' => (bool) env('HCAPTCHA_LOG_URL', false),

        // A submission carrying no token at all costs an attacker nothing and
        // never reaches hCaptcha, so recording it lets anyone inflate this
        // table with empty POSTs. Off by default; put `throttle` on the route
        // before turning it on.
        'log_missing_token' => (bool) env('HCAPTCHA_LOG_MISSING_TOKEN', false),

        // Same reasoning: a token over max_token_length is rejected before
        // any HTTP call, so recording it is free for the sender. Off by
        // default. The row carries no token hash.
        'log_oversized_token' => (bool) env('HCAPTCHA_LOG_OVERSIZED_TOKEN', false),

        // Rows older than this are removed by hcaptcha:prune. 0 disables
        // pruning and keeps every row.
        'retention_days' => (int) env('HCAPTCHA_RETENTION_DAYS', 90),
    ],

];
