# Changelog

All notable changes to `core45/laravel-h-captcha` are documented in this file, in the format described by
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to
[Semantic Versioning](https://semver.org/).

## 2.0.0 - Unreleased

### Changed

- `VerificationResult` gained `accepted`, the package's final answer, which `passed()` and `failed()` now use. `success` is hCaptcha's own verdict and is never rewritten: a local rejection keeps `success: true` and sets `accepted: false`; an accepted outage under `fail_open` is `success: false`, `accepted: true`. `toArray()` includes `accepted`.
- `Verifier::verify()` accepts `string|VerificationContext|null` as its third parameter. A string still means the field name. `Core45\HCaptcha\Support\VerificationContext` carries the field, the protected action and the expected sitekey, and the memo is keyed on all three.
- `Rules\HCaptcha` derives the protected action from the executing Livewire component, so two components validating the same property name in one batched request no longer share a verdict. It also accepts `sitekey:` and `action:` constructor arguments.
- Error-code predicates follow hCaptcha's documented table. `tokenAlreadyUsed()` matches `already-seen-response` and `invalid-or-already-seen-response` (`token-already-used` kept as an alias); new `tokenExpired()` and `tokenMalformed()`; `isConfigurationError()` recognises `missing-input-secret`, `invalid-input-secret`, `sitekey-secret-mismatch`, `bad-request`, `not-using-dummy-passcode` and `not-using-dummy-secret`, and no longer lists `bad-secret`, `no-such-user`, `invalid-sitekey` or `sitekey-mismatch`, which hCaptcha does not return.
- An oversized token is reported as `token-too-long` and the generic failure message, not as expired.
- `retries` counts additional attempts and defaults to `0`. The 1.x default of `1` was passed straight to the HTTP client's total-attempt count, so it also made one attempt.
- The "hostname check is inactive" error is logged once per process.
- `HCaptchaManager::configured()` accepts an override; the Blade component renders with an explicit `sitekey` when no global key is set and degrades instead of throwing when the override is a placeholder.
- An empty `sitekey` in a `VerificationContext` or `Rules\HCaptcha` falls back to the configured key instead of suppressing `send_sitekey`.
- The bootstrap script moved to `resources/js/bootstrap.js` and discovers widgets with a `MutationObserver` instead of Livewire's morph hooks; it prunes widgets removed from the page and marks both script tags `data-navigate-once`.
- Widget ids are deterministic per Livewire component instance or page, fixing the token binding lost after a Livewire re-render and the Filament id collision between two forms with the same state path.
- `Rules\HCaptcha` dispatches `core45HCaptcha:reset` with the validated field after any token is verified inside a Livewire component; manual reset calls are no longer needed.
- The Filament field renders no widget when no sitekey resolves but stays in validation.

### Added

- `hostnames_strict` (`HCAPTCHA_HOSTNAMES_STRICT`, default `false`). A response whose hostname is missing or `not-provided` passes with a `warning` by default, because hCaptcha documents the hostname as browser-derived and optional; strict mode rejects it with `rejectedBy: hostname-unknown`. An install that relied on 1.x rejecting an unreported hostname should set `HCAPTCHA_HOSTNAMES_STRICT=true`; the authoritative origin control is the domain allowlist on the sitekey in the hCaptcha dashboard, not this check.
- `logging.log_oversized_token` (`HCAPTCHA_LOG_OVERSIZED_TOKEN`, default `false`).
- Migration `2026_09_19_000000_add_accepted_to_hcaptcha_verifications_table`, adding an indexed `accepted` column backfilled from `success`. `HCaptchaVerification::failed()` now filters on it; new `rejectedLocally()` scope.
- The Filament field passes its `sitekey()` override to the validation rule, so verification is sent the key the widget was rendered with.
- `hcaptcha` middleware accepts an optional second parameter, the sitekey the widget was rendered with (`hcaptcha:h-captcha-response,<sitekey>`), so it shares a verdict with a rule constructed with the same `sitekey:`.
- Invisible mode: `size="invisible"` widgets execute the challenge on form submit, with duplicate-submit protection and an accessible status element.
- Custom `data-*-callback` options are invoked after the package's own handling.
- `HCaptchaManager::nonceUsing()`, `nonce()`, `nonceAttribute()`, `cspDirectives()` and `CSP_SOURCES`; the script tags carry a nonce from the resolver or `Vite::cspNonce()`.
- `HCaptchaManager::bootstrapScript()`, `RESET_EVENT`; `widgetId()` accepts a `key`.
- Translation keys `widget_error` and `widget_pending` in all locales.
- `resources/js/fake-api.js`, a network-free stand-in for `api.js` used by the package's Playwright suite.
- Response inputs carry `data-hcaptcha-field`.

### Removed

- `data-hcaptcha-model` attribute (never read).

### Fixed

- `fail_open` did nothing when a hostname policy was configured, which is the default: the outage verdict had no hostname and the hostname check rejected it. Local assertions are now skipped for a service-unavailable verdict. A provider rejection is still never accepted.
- `sitekey-secret-mismatch` is now logged as a configuration error instead of failing visitors silently.
- The middleware reads a configured field name containing a dot as a literal key rather than a nested path.
- A non-2xx siteverify response whose body still carries a verdict (for example `400` with `bad-request`) is now applied as that verdict and fails closed, instead of being treated as an outage that `fail_open` could accept.

### Tests

- Browser suite (`tests/Browser`, Pest + Playwright) covering Livewire re-render, two components, modal reopen, `wire:navigate`, custom callbacks, automatic reset after an unrelated validation failure, invisible submit in plain and Livewire forms, strict CSP with a negative control, and two Filament fields on one page.

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
