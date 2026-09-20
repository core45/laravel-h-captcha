# Upgrading from 1.x

Behaviour changes to check before moving a 1.x install to 2.0.0.

← back to the [README](../README.md)

2.0.0 changes behaviour in ten places. Each is a correctness fix; the common case of one form guarded by the rule and/or the middleware needs no code change, but a Livewire component should delete its manual `core45HCaptcha:reset` dispatch and a Livewire route should drop the middleware.

- **`VerificationResult::success` is now hCaptcha's verdict only.** Read `accepted` (or call `passed()`) for the final answer. In 1.x a local rejection overwrote `success` with `false`; now it leaves `success` as `true` and sets `accepted: false`. Anything that branched on `->success` should branch on `->passed()`.
- **The audit table has a new `accepted` column.** Run `php artisan migrate`. If you published the migrations, publish again with `php artisan vendor:publish --tag=hcaptcha-migrations`. The `failed()` model scope now filters on `accepted`.
- **Memoization is scoped to the executing Livewire component.** Two components validating the same property name in one batched request no longer share a verdict. The middleware and the rule on the same field in a plain form still collapse to one call. If you called `HCaptcha::verify($token, $ip, 'some-scope')` with your own scope string, that still works and now means "field".
- **Stacking the middleware on a Livewire update route no longer shares a verdict with the rule.** The rule scopes by the executing component; the middleware cannot, so the second check is told `already-seen-response`. Guard a Livewire form with the rule alone, which was always the documented setup.
- **Error codes are hCaptcha's.** `tokenAlreadyUsed()` now matches `already-seen-response`; `token-already-used` is kept as an alias. `expired-input-response` reports the expired message; `invalid-input-response` no longer does. `isConfigurationError()` recognises `sitekey-secret-mismatch`, `bad-request` and the dummy-passcode codes and no longer lists codes hCaptcha never returns.
- **An install that relied on 1.x rejecting an unreported hostname should set `HCAPTCHA_HOSTNAMES_STRICT=true`.** A missing or `not-provided` hostname now passes with a warning by default; the authoritative origin control is the domain allowlist on the sitekey in the hCaptcha dashboard, not this check.
- **Published views:** if you published `hcaptcha::script` or `hcaptcha::widget` in 1.x, re-publish them. The bootstrap now lives in `resources/js/bootstrap.js` and the widget view emits a status element and `data-hcaptcha-field`.
- **Widget ids are deterministic** (`hcaptcha-page-N`, `hcaptcha-<livewire-id>-N`) instead of random; selectors that relied on the `hcaptcha-` prefix still match.
- **`data-hcaptcha-model` is no longer emitted.**
- **The package's audit migration is opt-in.** In 1.x (and earlier 2.0 development builds) it always loaded. Now `hcaptcha.logging.migrations` (`HCAPTCHA_LOGGING_MIGRATIONS`) decides: unset follows `logging.enabled`, so an install that already turned on the audit trail is unaffected, but an install that has the table without `logging.enabled` set (for example because it always intended to enable it later) will find the migration no longer registers on the next `migrate:status`. Set `HCAPTCHA_LOGGING_MIGRATIONS=true`, or publish the migration yourself with `php artisan vendor:publish --tag=hcaptcha-migrations`. See [Audit migrations](audit-trail.md#audit-migrations).

Two defaults changed without changing behaviour: `retries` now counts *additional* attempts and defaults to `0` (1.x's default of `1` also made one attempt), and the "hostname check inactive" error is logged once per process rather than once per verification. New opt-ins: `hostnames_strict` and `logging.log_oversized_token`, both `false`.
