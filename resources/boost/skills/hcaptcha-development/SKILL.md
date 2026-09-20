---
name: hcaptcha-development
description: Build and work with core45/laravel-h-captcha features including the Blade widget, validation rule, middleware, Livewire resets, the Filament form field, credential profiles for multi-site installs, manual verification, testing with HCaptcha::fake(), the hcaptcha:doctor diagnostics command, and the verification audit trail.
license: MIT
metadata:
  author: core45
---

# hCaptcha Development

## Overview
Use core45/laravel-h-captcha to add hCaptcha to a Laravel app. It uses Laravel's `Http` client instead of Guzzle directly, provides a Blade widget with a Livewire-aware explicit render mode, a validation rule object, route middleware, a Filament form field, and an optional database audit trail.

## When to Activate
- Activate when adding or configuring an hCaptcha widget, validating a captcha token, or protecting a route/form/Filament schema against spam with hCaptcha.
- Activate when code references `<x-hcaptcha`, `Core45\HCaptcha\Rules\HCaptcha`, the `hcaptcha`/`captcha` validation rules, the `hcaptcha` middleware alias, `Core45\HCaptcha\Filament\Forms\Components\HCaptcha`, the `HCaptcha` facade, `HCaptchaVerification`, or `hcaptcha:prune`.
- Activate when debugging a captcha that shows `token-already-used` on a form that has more than one verification entry point (rule + middleware, or a Filament field validating twice).
- Activate when migrating an application off `thinhbuzz/laravel-h-captcha` (its `Captcha` facade, `CAPTCHA_*` env keys, or a published `config/captcha.php`).
- Activate when one install must serve several sites or brands from different hCaptcha accounts — code referencing `hcaptcha.profiles`, a `profile` prop/argument, or `sitekey-secret-mismatch`.
- Activate when writing tests for a captcha-guarded form (`HCaptcha::fake()`, `FakeVerifier`) or diagnosing a misconfiguration (`hcaptcha:doctor`).

## Scope
- In scope: widget rendering and attributes, validation (rule object and string rules), middleware, Livewire integration and widget resets, the Filament field, credential profiles, manual `verify()` calls, the `VerificationCompleted` event, the audit trail and pruning, `hcaptcha:doctor`, translations, testing with `HCaptcha::fake()` or `Http::fake()`, migrating from `thinhbuzz/laravel-h-captcha`.
- Out of scope: writing a captcha solution from scratch, other captcha providers (reCAPTCHA, Turnstile), non-Laravel frameworks.

## Workflow
1. Identify the task (widget placement, validation, middleware, Livewire, Filament, audit trail, manual verification).
2. Read `references/hcaptcha-guide.md` and focus on the relevant section.
3. Apply the patterns from the reference. Never introduce a second call path to `siteverify` — always go through the shared `Verifier`.

## Core Concepts

### The single memoizing verifier — the most important rule
**hCaptcha tokens are single-use.** All verification in this package goes through exactly one `Verifier` implementation, `Core45\HCaptcha\Support\HttpVerifier`, which memoizes the verdict per request by `hash('sha256', $token)` **and a scope** — `verify(?string $token, ?string $clientIp = null, string|VerificationContext|null $scope = null)`. A string scope is the field name; a `Core45\HCaptcha\Support\VerificationContext` also carries the protected action and the expected sitekey. The validation rule, the `hcaptcha` string rule, the route middleware, the Blade component's field, and the Filament form field are all thin callers of that same service — never call hCaptcha's `siteverify` endpoint directly, and never construct a second `Verifier`-like path. Stacking a rule and the middleware on the same field is safe in a plain form because they share a scope. Inside a Livewire request the rule also scopes by the executing component and the middleware cannot, so guard Livewire and Filament forms with the rule or the field alone; a Filament field that validates on update and again on submit still spends the token once because both checks run in the same request and share the per-request memo. Bypassing the shared verifier reintroduces the `token-already-used` bug this package exists to avoid.

The scope matters for a reason beyond convenience: the memoization is a safety mechanism, not a cache. Keyed on the token alone, one solved captcha would authorise every field checking it in the request — and Livewire can process up to 200 components in a single HTTP request. Always pass the attribute/field name as `$scope` when calling `verify()` directly; the rule and middleware already do this for you.

### Your sitekey is public — the hostname check matters
A sitekey is not secret; it is in the page HTML. An attacker can embed it on their own page, get it solved there (or buy a solved token), and post the genuine token to your form — `siteverify` returns `success: true` with the *attacker's* hostname, and `send_sitekey` cannot catch this because the sitekey still matches. `hostnames` (defaulting to the host of `APP_URL`) is the only defence against this. Never disable it without also restricting the sitekey's hostnames in the hCaptcha dashboard.

### Middleware verifies every request
`VerifyHCaptcha` no longer skips `GET`/`HEAD`/`OPTIONS` — it verifies whatever request it sees. Only ever attach `hcaptcha` middleware to the state-changing route, never to a route group that also serves the form's GET. The package does not rate limit; pair it with `throttle` on the guarded route.

### Setup
```bash
composer require core45/laravel-h-captcha
php artisan vendor:publish --tag=hcaptcha-config
```
Set `HCAPTCHA_SITEKEY` and `HCAPTCHA_SECRET`, then run `php artisan hcaptcha:doctor` to confirm the install. For the audit trail, set `HCAPTCHA_LOGGING=true` and run `php artisan migrate` — publishing `hcaptcha-migrations` is **not** required, because `logging.migrations` defaults to following `logging.enabled`, so the package loads its own migration only once the audit trail is switched on.

### Widget
```blade
<x-hcaptcha />
<x-hcaptcha theme="dark" size="compact" model="captchaToken" />
```
Always renders in hCaptcha's explicit mode — there is no auto mode to opt into. Auto mode injects its own response field, loses the token on any DOM patch, and bypasses the hidden input this package tracks, so it was removed entirely.

### Credential profiles (multi-site installs)
Name each sitekey/secret pair under `hcaptcha.profiles`, then select it per entry point:
```blade
<x-hcaptcha profile="marketing" />
```
```php
new \Core45\HCaptcha\Rules\HCaptcha(profile: 'marketing');
Route::post('/signup', C::class)->middleware('hcaptcha:h-captcha-response,,marketing'); // field,sitekey,profile
\Core45\HCaptcha\Filament\Forms\Components\HCaptcha::make()->profile('marketing');
```
An **unknown profile name throws `InvalidArgumentException`** rather than falling back to the global pair — a silent fallback would pair one account's sitekey with another's secret and fail every verification with `sitekey-secret-mismatch`. A profile that sets only one half inherits the other from the global config. The profile is part of the memo key (`VerificationContext::credentialKey()` is `profile|sitekey`), so a verdict under one profile never vouches for another. Never let the request choose its own profile — it picks the secret that vouches for the token.

### Testing
`HCaptcha::fake()` swaps the `Verifier` binding and makes the widget render the package's fake partial, so no HTTP layer is involved:
```php
$hcaptcha = HCaptcha::fake();  // or fake(false) to reject
$this->post('/contact', ['h-captcha-response' => \Core45\HCaptcha\Testing\FakeVerifier::TOKEN]);
$hcaptcha->assertVerifiedFor('h-captcha-response');
```
Shape it with `pass()`, `fail($errorCode)`, `respondWith($answer)`; assert with `assertVerified()`, `assertVerifiedFor()`, `assertVerifiedTimes()`, `assertNothingVerified()`. The fake does **not** memoize, so use `Http::fake()` plus `Http::assertSentCount(1)` when the thing under test is the memoization contract itself.

### Diagnostics and events
`php artisan hcaptcha:doctor` checks credentials, profile half-overrides, hostname policy, and the audit table; it makes no network calls, prints no secrets, and exits non-zero on a problem, so it gates CI. `Core45\HCaptcha\Events\VerificationCompleted` fires once per real verification with `$result`, `$context`, and `$tokenHash` — the hook for metrics without enabling the database audit trail.

### Validation
Prefer the rule object over the string rule — it distinguishes missing / expired / failed / unavailable outcomes via `VerificationResult::messageKey()`. Do **not** pair it with `required`: the rule is implicit and already fires on a missing token, and `required` would only make Laravel's generic message win instead of the package's own:
```php
$request->validate(['h-captcha-response' => [new \Core45\HCaptcha\Rules\HCaptcha]]);
```

### Middleware
```php
Route::post('/contact', Controller::class)->middleware(['throttle:10,1', 'hcaptcha']);
```
Throws `ValidationException` on failure — a 422 or redirect-with-errors, never a 500. Verifies every request it sees (no method allowlist), so keep it off any route that also serves the form's GET, and pair it with `throttle` since it does no rate limiting itself.

### Livewire
The rule resets the widget whose token it verified — on success or failure — by dispatching `core45HCaptcha:reset` with the validated field; don't dispatch it yourself. A submit carrying no token at all is the exception: the dispatch is guarded on a non-empty token, since nothing was spent. Keep `wire:ignore` on the widget container.

### Filament
```php
use Core45\HCaptcha\Filament\Forms\Components\HCaptcha;

HCaptcha::make();
```
Calls `->markAsRequired()` (asterisk only, not a `required()` rule — the `HCaptcha` rule is already implicit) and `->dehydrated(false)` deliberately — the token must never be persisted to a model — validation still runs beforehand.

### Audit trail
Enable with `HCAPTCHA_LOGGING=true`, migrate, and schedule `php artisan hcaptcha:prune`. The raw token is never stored, only its SHA-256 hash. Query the `accepted` column, not `success`: `success` is hCaptcha's raw verdict, `accepted` is the package's verdict after the local hostname and score checks, and it is what `VerificationResult::passed()` returns. `scopeFailed()` filters on `accepted`; `scopeRejectedLocally()` finds rows hCaptcha accepted but the package rejected.

### Migrating from thinhbuzz/laravel-h-captcha
`composer remove buzz/laravel-h-captcha && composer require core45/laravel-h-captcha` — no application code changes. The `Captcha` facade, `CAPTCHA_SECRET`/`CAPTCHA_SITEKEY`, a published `config/captcha.php`, and the `captcha` rule all keep working via a compat layer in `Core45\HCaptcha\Compat\`. `http_client` is ignored (that's the whole reason this package exists), and the old placeholder defaults (`default_secret`/`default_sitekey`) now throw instead of silently failing every verification. The one thing likely to break on swap: `hostnames` is on by default here and the old package had no such check — set `HCAPTCHA_HOSTNAMES` before going live if the form is served off a host other than `APP_URL`. See `references/hcaptcha-guide.md#migrating-from-thinhbuzzlaravel-h-captcha` for the full comparison.

## Do and Don't

- **Do** route every verification through the shared `Verifier` (facade, rule, middleware, Filament field) — never call `siteverify` yourself.
- **Don't** dispatch `core45HCaptcha:reset` yourself: the rule resets the widget whose token it verified. **Do** keep `wire:ignore` on the widget container.
- **Do** use `HCaptcha::configured()` to let a view degrade instead of throwing when no sitekey is set.
- **Do** use `size="invisible"` for an invisible widget; the bootstrap executes it on submit.
- **Do** register `HCaptchaManager::nonceUsing()` under a strict CSP and allow `HCaptchaManager::cspDirectives()`.
- **Don't** persist the captcha token to a model — it is single-use and already spent by the time validation passes.
- **Don't** pair `new \Core45\HCaptcha\Rules\HCaptcha` with a `required` rule — it is implicit already, and `required` only steals its message.
- **Don't** treat hCaptcha's `score` as a confidence score to maximize — it is a *risk* score, so `max_score` is a ceiling, not a floor.
- **Don't** assume `fail_open` is on by default — an hCaptcha outage rejects the form unless `HCAPTCHA_FAIL_OPEN=true` is explicitly set.
- **Don't** disable `hostnames` without also restricting the sitekey's hostnames in the hCaptcha dashboard — a public sitekey embedded on another site is otherwise indistinguishable from a legitimate submission.
- **Do** consider `HCAPTCHA_HOSTNAMES_STRICT=true` to also reject a response whose hostname is missing or `not-provided` — `hostnames` alone lets those through, and that is the hole it leaves open; the cost is occasional false rejections when hCaptcha omits the hostname under load.
- **Don't** put the `hcaptcha` middleware on a route group that also serves the form's GET — it verifies every method now, with no allowlist.
- **Don't** rely on this package for rate limiting — it verifies tokens, it does not throttle; add `throttle` on the guarded route yourself.
- **Don't** let a request pick its own `profile` — it selects the secret that vouches for the token, so a visitor-supplied profile name lets the visitor choose their own validator.
- **Don't** query the audit trail on `success` when you mean "did this submission pass" — that's the `accepted` column; `success` is hCaptcha's raw answer before the local hostname and score checks.
- **Do** run `php artisan hcaptcha:doctor` after install and in CI — a half-overridden profile and an unset `hostnames` are both silent until a real submission fails.
- **Do** reach for `HCaptcha::fake()` in tests over `Http::fake()`, except when the assertion *is* the memoization contract — the fake doesn't memoize, so only the HTTP-level `assertSentCount()` proves one token cost one call.
