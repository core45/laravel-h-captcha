# Audit trail

What the optional audit trail stores, how to prune it, and how its migration is registered.

← back to the [README](../README.md)

Enable with `HCAPTCHA_LOGGING=true` (or `hcaptcha.logging.enabled`) and run the published migration. Every verification attempt — success or failure — writes one row to `hcaptcha_verifications` (configurable table/connection) via `Core45\HCaptcha\Support\VerificationLogger`, which never lets a logging failure fail the verification itself.

Stored: `success`, `accepted`, `token_hash` (SHA-256 of the token), `hostname`, `challenge_ts`, `score`, `error_codes` (JSON), `rejected_by`, plus `ip` / `user_agent` / `url` when their respective PII toggles are on, and timestamps.

**Deliberately not stored: the raw token.** It is a single-use credential — by the time it could be logged it is already spent, so keeping it would be a liability with no benefit. Only its SHA-256 hash is kept, which is enough to correlate a replay without being able to replay it yourself.

The PII toggles (`logging.store_ip`, `logging.store_user_agent`, `logging.store_url`) all default to `false`. When `store_url` is enabled it records the request path **without the query string** — a query string routinely carries signed-URL signatures, password-reset tokens, and email addresses, none of which belong in a 90-day audit table. Enable any of these only with a lawful basis.

`logging.log_missing_token` also defaults to `false`. A submission with no token at all costs an attacker nothing and never reaches hCaptcha, so recording it by default would let anyone inflate the audit table with free, unauthenticated POSTs. Turn it on only alongside route throttling.

`Core45\HCaptcha\Models\HCaptchaVerification` ships three scopes: `scopeFailed()` (`where('accepted', false)`), `scopeRejectedLocally()` (`whereNotNull('rejected_by')`, a genuine token a local assertion turned down), and `scopeForToken(string $tokenHash)` (attempts sharing a token hash — the fingerprint of a replay).

## Audit migrations

The package's own migration only registers itself with Laravel when `hcaptcha.logging.migrations` resolves to `true`. That key (`HCAPTCHA_LOGGING_MIGRATIONS`) has three states:

- `null` (default) — follows `logging.enabled`: turn the audit trail on and the migration appears on the next `migrate`, leave it off and nothing is added.
- `true` — the migration always loads, regardless of `logging.enabled`. Set this if you already have the table (from an earlier install, or from publishing it yourself) and want `migrate:status` to keep recognising it.
- `false` — the migration never loads from inside the package. Publish it and own it outright: `php artisan vendor:publish --tag=hcaptcha-migrations`.

If you already have the `hcaptcha_verifications` table from an earlier release, set `HCAPTCHA_LOGGING_MIGRATIONS=true` (or publish the migration) so it stays recognised even if `logging.enabled` is off for any reason.

Prune old rows with the console command, and schedule it:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('hcaptcha:prune')->daily();
```

```bash
php artisan hcaptcha:prune                 # uses hcaptcha.logging.retention_days (default 90)
php artisan hcaptcha:prune --days=30
php artisan hcaptcha:prune --chunk=500
```

Setting `retention_days` to `0` disables pruning; the command exits with a warning and deletes nothing.
