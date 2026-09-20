# Migrating from thinhbuzz/laravel-h-captcha

Drop-in replacement notes for anyone coming from `buzz/laravel-h-captcha`.

← back to the [README](../README.md)

```bash
composer remove buzz/laravel-h-captcha
composer require core45/laravel-h-captcha
```

No application code needs to change. The `Captcha` facade, the `captcha` validation rule, and your existing `.env` keys all keep working.

**What keeps working unchanged:**

- The env keys `CAPTCHA_SECRET` and `CAPTCHA_SITEKEY`. If both `CAPTCHA_*` and `HCAPTCHA_*` are set, `HCAPTCHA_*` wins, so a half-migrated `.env` behaves predictably rather than randomly.
- A previously published `config/captcha.php` — its `secret`, `sitekey`, `options.lang`, and `attributes` values are adopted for any `hcaptcha.*` key that is not itself set. A configured `hcaptcha.*` value always wins over the legacy config.
- The `Captcha` facade, with all eight methods from the old package: `display()`, `displayMultiple()`, `displayJs()`, `multiple()`, `setOptions()`, `verify()`, `getWidgetIdName()`, `getJsVariableName()`.
- `Captcha::verify($response, $clientIp = null, $options = [])` returns a plain **bool**, exactly like before. To see *why* a token failed, call the native `HCaptcha::verify()` instead, which returns a `VerificationResult`.
- The `captcha` string validation rule (`'h-captcha-response' => 'captcha'`).
- The `Form::captcha()` macro, registered only when a `form` binding exists in the container.

**What is different, and why:**

- `http_client` in the old config is **ignored**. It named a Guzzle-based HTTP client class, and replacing Guzzle with Laravel's own `Http` client is the entire reason this package exists — see [Why this exists](#why-this-exists). A configured `http_client` logs a warning and is otherwise skipped.
- The old package's config defaults — the literal strings `default_sitekey` and `default_secret` — are treated as **not configured**, and raise `MissingSitekeyException` / `MissingSecretException` instead of being sent to hCaptcha. This is deliberate: those literals meant every verification silently failed with no indication why, which is exactly the failure mode this package is built to avoid.
- `multiple` mode needs no special handling. Every widget already renders in explicit mode and the bootstrap script renders every container on the page, so several widgets already work side by side. `displayMultiple()` still exists so old calls do not break, but it returns an empty string.
- `verify()` no longer swallows every failure into a bare `false` with no explanation. The native layer distinguishes missing, expired, unavailable, and failed tokens, and fails **closed** on a transport error unless you opt into `HCAPTCHA_FAIL_OPEN=true`.

## The hostname check — the most likely migration surprise

`hostnames` is **on by default** in this package, derived from `APP_URL`. If your form is served from a different host than `APP_URL` (a staging domain, a second brand, a proxy), set `HCAPTCHA_HOSTNAMES` to a comma-separated list of the hostnames that should be accepted — otherwise genuine submissions get rejected with `hostname-mismatch`. See [Your sitekey is public](../README.md#your-sitekey-is-public--this-is-why-hostnames-matters) for why this check exists at all. A hostname hCaptcha reports as missing or `not-provided` passes with a warning unless `HCAPTCHA_HOSTNAMES_STRICT` is set. An install that relied on 1.x rejecting an unreported hostname should set `HCAPTCHA_HOSTNAMES_STRICT=true`; either way, the authoritative origin control is the domain allowlist on the sitekey in the hCaptcha dashboard, not this check.

## Recommended follow-up

Once the swap is verified, consider:

- Switching to `<x-hcaptcha />` and the `HCaptcha` rule object for better per-outcome error messages.
- Moving `.env` keys from `CAPTCHA_*` to `HCAPTCHA_*`.
- Deleting `config/captcha.php` once nothing reads it.
- Adding `throttle` to the route the captcha guards — see [Rate limiting](../README.md#rate-limiting).
