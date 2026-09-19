---
name: hcaptcha-development
description: Build and work with core45/laravel-h-captcha features including the Blade widget, validation rule, middleware, Livewire resets, the Filament form field, manual verification, and the verification audit trail.
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

## Scope
- In scope: widget rendering and attributes, validation (rule object and string rules), middleware, Livewire integration and widget resets, the Filament field, manual `verify()` calls, the audit trail and pruning, translations, testing with `Http::fake()`, migrating from `thinhbuzz/laravel-h-captcha`.
- Out of scope: writing a captcha solution from scratch, other captcha providers (reCAPTCHA, Turnstile), non-Laravel frameworks.

## Workflow
1. Identify the task (widget placement, validation, middleware, Livewire, Filament, audit trail, manual verification).
2. Read `references/hcaptcha-guide.md` and focus on the relevant section.
3. Apply the patterns from the reference. Never introduce a second call path to `siteverify` — always go through the shared `Verifier`.

## Core Concepts

### The single memoizing verifier — the most important rule
**hCaptcha tokens are single-use.** All verification in this package goes through exactly one `Verifier` implementation, `Core45\HCaptcha\Support\HttpVerifier`, which memoizes the verdict per request by `hash('sha256', $token)` **and a scope** — `verify(?string $token, ?string $clientIp = null, string|VerificationContext|null $scope = null)`. A string scope is the field name; a `Core45\HCaptcha\Support\VerificationContext` also carries the protected action and the expected sitekey. The validation rule, the `hcaptcha` string rule, the route middleware, the Blade component's field, and the Filament form field are all thin callers of that same service — never call hCaptcha's `siteverify` endpoint directly, and never construct a second `Verifier`-like path. Stacking a rule and middleware (or a Filament field that validates on update and again on submit) on the same *field* is safe precisely because they share a scope and resolve to the same memoized call; bypassing the shared verifier reintroduces the `token-already-used` bug this package exists to avoid.

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
Set `HCAPTCHA_SITEKEY` and `HCAPTCHA_SECRET`. Only publish `hcaptcha-migrations` and run `php artisan migrate` if the audit trail (`HCAPTCHA_LOGGING=true`) is wanted.

### Widget
```blade
<x-hcaptcha />
<x-hcaptcha theme="dark" size="compact" model="captchaToken" />
```
Always renders in hCaptcha's explicit mode — there is no auto mode to opt into. Auto mode injects its own response field, loses the token on any DOM patch, and bypasses the hidden input this package tracks, so it was removed entirely.

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
Reset the widget after every submit (success or failure) or the next attempt fails with `token-already-used`:
```php
$this->dispatch('core45HCaptcha:reset');
```

### Filament
```php
use Core45\HCaptcha\Filament\Forms\Components\HCaptcha;

HCaptcha::make();
```
Calls `->markAsRequired()` (asterisk only, not a `required()` rule — the `HCaptcha` rule is already implicit) and `->dehydrated(false)` deliberately — the token must never be persisted to a model — validation still runs beforehand.

### Audit trail
Enable with `HCAPTCHA_LOGGING=true`, migrate, and schedule `php artisan hcaptcha:prune`. The raw token is never stored, only its SHA-256 hash.

### Migrating from thinhbuzz/laravel-h-captcha
`composer remove buzz/laravel-h-captcha && composer require core45/laravel-h-captcha` — no application code changes. The `Captcha` facade, `CAPTCHA_SECRET`/`CAPTCHA_SITEKEY`, a published `config/captcha.php`, and the `captcha` rule all keep working via a compat layer in `Core45\HCaptcha\Compat\`. `http_client` is ignored (that's the whole reason this package exists), and the old placeholder defaults (`default_secret`/`default_sitekey`) now throw instead of silently failing every verification. The one thing likely to break on swap: `hostnames` is on by default here and the old package had no such check — set `HCAPTCHA_HOSTNAMES` before going live if the form is served off a host other than `APP_URL`. See `references/hcaptcha-guide.md#migrating-from-thinhbuzzlaravel-h-captcha` for the full comparison.

## Do and Don't

- **Do** route every verification through the shared `Verifier` (facade, rule, middleware, Filament field) — never call `siteverify` yourself.
- **Do** reset the widget (`core45HCaptcha:reset` or `window.core45HCaptcha.reset()`) after every Livewire submit, success or failure.
- **Do** use `HCaptcha::configured()` to let a view degrade instead of throwing when no sitekey is set.
- **Don't** persist the captcha token to a model — it is single-use and already spent by the time validation passes.
- **Don't** pair `new \Core45\HCaptcha\Rules\HCaptcha` with a `required` rule — it is implicit already, and `required` only steals its message.
- **Don't** treat hCaptcha's `score` as a confidence score to maximize — it is a *risk* score, so `max_score` is a ceiling, not a floor.
- **Don't** assume `fail_open` is on by default — an hCaptcha outage rejects the form unless `HCAPTCHA_FAIL_OPEN=true` is explicitly set.
- **Don't** disable `hostnames` without also restricting the sitekey's hostnames in the hCaptcha dashboard — a public sitekey embedded on another site is otherwise indistinguishable from a legitimate submission.
- **Don't** put the `hcaptcha` middleware on a route group that also serves the form's GET — it verifies every method now, with no allowlist.
- **Don't** rely on this package for rate limiting — it verifies tokens, it does not throttle; add `throttle` on the guarded route yourself.
