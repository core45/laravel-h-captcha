# Changelog

All notable changes to `core45/laravel-h-captcha` are documented in this file, in the format described by
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## 1.0.0 - 2026-09-17

### Added

- Drop-in compatibility with `thinhbuzz/laravel-h-captcha`, so that package can be swapped out without an application change: the `CAPTCHA_SECRET` / `CAPTCHA_SITEKEY` env keys are read as fallbacks (`HCAPTCHA_*` wins), a published `config/captcha.php` supplies `secret`, `sitekey`, `options.lang` and `attributes` wherever `hcaptcha.*` is unset, and the `captcha` container binding plus the `Captcha` facade alias expose `display()`, `displayMultiple()`, `displayJs()`, `multiple()`, `setOptions()`, `verify()` (still returning a bool), `getWidgetIdName()` and `getJsVariableName()` through `Core45\HCaptcha\Compat\CaptchaCompat`. A `Form::captcha()` macro is registered when a `form` binding exists. Two deliberate incompatibilities: `captcha.http_client` is ignored and logs a warning, because it named a Guzzle-based client and using Laravel's HTTP client instead is why this package exists; and `displayMultiple()` returns an empty string, because every widget already renders explicitly and the bootstrap script renders all of them.
- `HCaptchaManager::isUsableCredential()`, which treats `default_sitekey` and `default_secret` — the literals `thinhbuzz/laravel-h-captcha` defaulted to — as "not configured", so a half-migrated install throws instead of rejecting every visitor with no explanation.
- `Core45\HCaptcha\Support\HttpVerifier`, the single `Verifier` implementation every entry point routes through, using Laravel's `Http` client and memoizing verdicts per request by `hash('sha256', $token)` so a single-use token is spent exactly once regardless of how many guards check it.
- `Core45\HCaptcha\Support\VerificationResult`, a readonly value object distinguishing a genuine hCaptcha rejection from a local `hostname-mismatch` / `score-too-high` rejection, a missing token, and a service outage.
- `Core45\HCaptcha\Support\VerificationLogger` and an optional audit trail: `hcaptcha_verifications` migration, `Core45\HCaptcha\Models\HCaptchaVerification` model with `failed()` / `forToken()` scopes, and a `hcaptcha:prune` Artisan command with configurable retention.
- `<x-hcaptcha />` Blade component, always rendering in hCaptcha's explicit mode (auto mode is not supported), with a bootstrap script that re-renders widgets after Livewire DOM patches (`livewire:init`, `morphed`, `morph.added`, `livewire:navigated`) and exposes `window.core45HCaptcha.render/renderAll/reset` plus a `core45HCaptcha:reset` window event.
- `Core45\HCaptcha\Rules\HCaptcha` validation rule object, reporting missing / expired / failed / unavailable outcomes distinctly, plus a `hcaptcha` string validation rule and a `captcha` alias for migration from `buzz/laravel-h-captcha`.
- `hcaptcha` route middleware alias (`Core45\HCaptcha\Http\Middleware\VerifyHCaptcha`) that throws `ValidationException` on a failed token.
- `Core45\HCaptcha\Filament\Forms\Components\HCaptcha` Filament form field, with `dehydrated(false)` so the single-use token is never persisted to a model while still being validated.
- `HCaptchaManager` and the `HCaptcha` facade, exposing widget rendering data (`sitekey()`, `attributes()`, `scriptUrl()`, `widgetId()`, ...) and a `verify()` pass-through.
- `config/hcaptcha.php` covering credentials, endpoint/timeout/retries, `fail_open` failure behaviour, `send_sitekey`, `hostnames`, `max_score`, widget field/locale/attributes/script options, and the audit-trail block.
- Translations for the `hcaptcha::hcaptcha` namespace in 22 locales (`bg`, `cs`, `de`, `el`, `en`, `es`, `et`, `fi`, `fr`, `hr`, `hu`, `it`, `lt`, `lv`, `nl`, `pl`, `pt`, `sk`, `sl`, `sq`, `sv`, `uk`).
- `MissingSecretException` and `MissingSitekeyException`, thrown instead of silently failing every verification or rendering a broken widget.

### Security

- `hostnames` now defaults to the host of `APP_URL` (`env('HCAPTCHA_HOSTNAMES', parse_url((string) env('APP_URL'), PHP_URL_HOST))`) instead of `null`. A sitekey is public — it is in the page HTML — so an attacker can embed it on their own page, solve it there (or buy a solved token), and post the genuine token to your form; `siteverify` returns `success: true` with the attacker's hostname, and `send_sitekey` cannot detect this because the sitekey matches. The hostname check is the only defence against that attack, and `HCAPTCHA_HOSTNAMES` now accepts a comma-separated list for multi-domain installs.
- `Verifier::verify()` gained a third parameter, `?string $scope = null`, and `HttpVerifier` now memoizes per `(token, scope)` instead of per token alone. An unscoped memo let one solved captcha authorise every consumer of it in the same request; Livewire can process up to 200 components in a single HTTP request, so this closes a cross-field bypass. Two checks of the same field still collapse to one HTTP call.
- `VerifyHCaptcha` middleware no longer skips `GET`/`HEAD`/`OPTIONS` requests — it verifies every request it sees. The allowlist was removed because it was spoofable: Symfony's `_method` override refuses only `GET`, `HEAD`, `CONNECT`, and `TRACE`, so a POST carrying `_method=OPTIONS` was reported as `OPTIONS` and skipped verification while the controller still received the full POST body.
- New `max_token_length` config key (`HCAPTCHA_MAX_TOKEN_LENGTH`, default `8192`). Oversized tokens are rejected before any HTTP call, so an unauthenticated request cannot make the package proxy a large body to hCaptcha while holding a worker.
- New `logging.log_missing_token` config key (`HCAPTCHA_LOG_MISSING_TOKEN`, default `false`). A tokenless submission costs nothing and never reaches hCaptcha, so logging it by default would let anyone fill the audit table with free, unauthenticated POSTs.
- `logging.store_url` now defaults to `false` (was `true`) and, when enabled, records the request path without its query string — query strings routinely carry signed-URL signatures, password-reset tokens, and email addresses.
- `VerificationResult::fromResponse()` now compares `success` with `=== true` instead of casting the decoded value, so a malformed 200 response body cannot be coerced into a pass.
- Widget attribute names and the package's JavaScript global identifiers are now validated (`HCaptchaManager::normaliseAttribute()`, `assertJsIdentifier()`), throwing `InvalidArgumentException` on an invalid name rather than emitting it.
- Blank entries in a configured `hostnames` list are filtered out, so an empty string can no longer masquerade as an active restriction that matches nothing.

### Tests

- Feature coverage for the validation rule (including the implicit-vs-`required` interaction), the middleware, the Filament field, widget rendering, verification memoization, and the audit trail (`tests/Feature/ValidationTest.php`, `MiddlewareTest.php`, `FilamentFieldTest.php`, `WidgetComponentTest.php`, `VerifierTest.php`, `AuditTrailTest.php`).
