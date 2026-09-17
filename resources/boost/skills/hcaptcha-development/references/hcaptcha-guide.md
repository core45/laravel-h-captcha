# hCaptcha Development Guide

Long-form reference for `core45/h-captcha`. See the package README for the quick-start version; this
goes deeper on failure modes and the error-code list.

## Setup

```bash
composer require core45/h-captcha
php artisan vendor:publish --tag=hcaptcha-config
```

Set in `.env`:

```
HCAPTCHA_SITEKEY=10000000-ffff-ffff-ffff-000000000001
HCAPTCHA_SECRET=0x0000000000000000000000000000000000000000
```

(the values above are hCaptcha's documented test pair — they always verify successfully and are
safe for local/CI use; the matching test token is `10000000-aaaa-bbbb-cccc-000000000001`).

Other publish tags, from `HCaptchaServiceProvider::bootPublishing()`:

- `hcaptcha-lang` → `lang/vendor/hcaptcha/{locale}/hcaptcha.php`
- `hcaptcha-views` → `resources/views/vendor/hcaptcha/*.blade.php`
- `hcaptcha-migrations` → `database/migrations/*_create_hcaptcha_verifications_table.php`

Only publish and migrate `hcaptcha-migrations` if you are turning on the audit trail. Nothing else
in the package touches a database.

Both `sitekey` and `secret` default to `null`. This is deliberate: a missing secret throws
`MissingSecretException` the first time a real verification is attempted, and a missing sitekey
throws `MissingSitekeyException` when something asks for one directly — rather than either silently
rejecting every visitor (a placeholder default would do this) or rendering a broken widget with no
indication why. `HCaptchaManager::configured()` lets a view check first and degrade instead.

## Config reference

`config/hcaptcha.php` may not call `app()`, `trans()`, or any other container helper — the file is
evaluated once by `php artisan config:cache` and frozen. This is why the widget locale is resolved
at render time in `HCaptchaManager::locale()`, not baked into the config file.

```php
'sitekey' => env('HCAPTCHA_SITEKEY'),
'secret' => env('HCAPTCHA_SECRET'),
'endpoint' => env('HCAPTCHA_ENDPOINT', 'https://api.hcaptcha.com/siteverify'),
'timeout' => (int) env('HCAPTCHA_TIMEOUT', 10),
'retries' => (int) env('HCAPTCHA_RETRIES', 1),
'max_token_length' => (int) env('HCAPTCHA_MAX_TOKEN_LENGTH', 8192),
'fail_open' => (bool) env('HCAPTCHA_FAIL_OPEN', false),
'send_sitekey' => (bool) env('HCAPTCHA_SEND_SITEKEY', true),
'hostnames' => env('HCAPTCHA_HOSTNAMES', parse_url((string) env('APP_URL'), PHP_URL_HOST)),
'max_score' => env('HCAPTCHA_MAX_SCORE') !== null ? (float) env('HCAPTCHA_MAX_SCORE') : null,
'field' => 'h-captcha-response',
'locale' => null,
'attributes' => ['theme' => 'light', 'size' => 'normal'],
'script' => ['enabled' => true, 'url' => 'https://js.hcaptcha.com/1/api.js'],
'logging' => [
    'enabled' => (bool) env('HCAPTCHA_LOGGING', false),
    'table' => 'hcaptcha_verifications',
    'connection' => env('HCAPTCHA_LOGGING_CONNECTION'),
    'store_ip' => (bool) env('HCAPTCHA_LOG_IP', false),
    'store_user_agent' => (bool) env('HCAPTCHA_LOG_USER_AGENT', false),
    'store_url' => (bool) env('HCAPTCHA_LOG_URL', false),
    'log_missing_token' => (bool) env('HCAPTCHA_LOG_MISSING_TOKEN', false),
    'retention_days' => (int) env('HCAPTCHA_RETENTION_DAYS', 90),
],
```

`max_token_length` rejects a token longer than this without making an HTTP call
(`HttpVerifier::maxTokenLength()`), so an unauthenticated request cannot make the package proxy a
huge body to hCaptcha while holding a PHP worker for the whole timeout.

`send_sitekey` includes the sitekey in the verification POST so hCaptcha itself rejects a token
minted for a different site (`sitekey-mismatch`), in addition to any local `hostnames` check.

**`hostnames` is the only defence against sitekey theft, which is why it now defaults to on.** A
sitekey is public — it ships in your page HTML — so an attacker can embed *your* sitekey on *their*
page, get it solved there (or buy a solved token from a captcha farm), and post the genuine token to
your form. `siteverify` still answers `success: true`, because the token really was solved against
your sitekey; it just reports `hostname: attacker.example`. `send_sitekey` cannot catch this, because
the sitekey matches — there is no mismatch for it to see. `hostnames` defaults to
`env('HCAPTCHA_HOSTNAMES', parse_url((string) env('APP_URL'), PHP_URL_HOST))`. Set
`HCAPTCHA_HOSTNAMES` to a comma-separated list for multi-domain installs. Setting it to an empty
string disables the check and is logged as an `error` on every verification thereafter. Blank
entries inside a configured list are filtered out in `HttpVerifier::allowedHostnames()`, so an empty
string can't survive as a restriction that matches nothing. If the configured value resolves to no
usable scalar hostname at all, the check logs an `error` and is treated as inactive, rather than
silently behaving as "no restriction" with no signal that anything is wrong. Restrict the sitekey's
hostnames in the hCaptcha dashboard too — that allowlist is off by default for new sitekeys, so
skipping it leaves both layers of the origin check absent.

`max_score` is a **risk** score — the inverse of reCAPTCHA v3, where higher means more bot-like —
and only Publisher/Pro accounts ever populate `score` in the response; on other accounts the check
is silently skipped because `$result->score` is `null`.

## The widget

```blade
<x-hcaptcha />
<x-hcaptcha theme="dark" size="compact" />
<x-hcaptcha sitekey="..." locale="es" id="contact-captcha" />
<x-hcaptcha :options="['size' => 'invisible']" />
<x-hcaptcha model="captchaToken" />   {{-- Livewire --}}
```

`Core45\HCaptcha\View\Components\HCaptcha` constructor arguments: `sitekey`, `theme`, `size`,
`locale`, `id`, `script` (bool, default `true`), `model` (Livewire property name), `options`
(array of extra `data-*` overrides). There is no `explicit` argument — every widget always renders
in hCaptcha's explicit mode; see the next section.

Attribute keys passed through `options`/`theme`/`size` are normalised: anything not already
prefixed `data-` and not one of `id`/`class`/`style` gets a `data-` prefix
(`HCaptchaManager::normaliseAttribute()`), because hCaptcha reads its widget options from `data-*`
attributes on the container div. Every attribute value is escaped through `e()` when rendered
(`HCaptchaManager::attributeString()`) — the package this one replaces interpolated raw values,
which was an injection point.

Attribute *names* are validated, not merely escaped: `normaliseAttribute()` throws
`InvalidArgumentException` unless the normalised name matches `^[A-Za-z][A-Za-z0-9-]*$`, because
`e()` escapes `& < > " '` in the value but not whitespace or `=` in the key — a key like
`data-x onmouseover=alert(1)` would otherwise render as a second, live attribute. The two JavaScript
globals the package emits into `<script>` tags, `core45HCaptchaOnLoad` and `core45HCaptcha`, are
validated the same way by `HCaptchaManager::assertJsIdentifier()` against
`^[A-Za-z_$][A-Za-z0-9_$]*$` — they're hardcoded today, so this is a latch against a future change
turning them into stored XSS rather than a fix for a live bug.

### Every widget renders in explicit mode — there is no auto mode

There used to be a choice between hCaptcha's own auto mode (its `h-captcha` CSS-class convention,
which scans the DOM once on `DOMContentLoaded`) and explicit mode. Auto mode has been removed
entirely, and `<x-hcaptcha>` no longer accepts an `explicit` prop — every widget now always renders
explicitly. Auto mode was wrong for any widget that can appear after the initial render — a
Livewire component, an Alpine-toggled section, a modal — because it injects its own response field
outside this package's control, loses the token on any DOM patch, and bypasses the hidden input
the package tracks. That combination produced `missing-input-response` even when the visitor had
genuinely completed the challenge, which is why the mode was dropped rather than merely
defaulted away from.

Explicit mode works like this:

1. `HCaptchaManager::scriptUrl()` loads `api.js` with `?render=explicit&onload=core45HCaptchaOnLoad`.
2. hCaptcha calls `window.core45HCaptchaOnLoad()` once the API is ready.
3. That callback calls `window.core45HCaptcha.markReady()`, which calls `renderAll()`.
4. `renderAll()` finds every `[data-hcaptcha-explicit]` element not already rendered (or whose
   rendered iframe has since disappeared) and calls `window.hcaptcha.render(el, {...})` on it.

Widgets are discovered from the DOM at render time rather than from a list baked in when the page
first loaded, which is what makes late-inserted widgets work without extra wiring.

### No sitekey configured

`resources/views/widget.blade.php` renders nothing when `HCaptchaManager::configured()` is false,
except a visible `<div class="hcaptcha-misconfigured">` warning when `config('app.debug')` is true.
It never throws mid-page render. The verifier still fails closed regardless (a missing token fails
the same as any other), so a misconfigured widget cannot be bypassed by simply not rendering it.

The widget's inline error paragraph is also guarded, with `@if (isset($errors) && $errors->has($fieldName()))`.
`$errors` is only shared into the view by the `ShareErrorsFromSession` middleware in the `web`
group, so a widget rendered outside it (an API route, a queued-mail preview, a non-web response)
would otherwise throw on an undefined variable instead of simply showing no inline error.

## Validation

### Rule object (preferred)

```php
$request->validate([
    'h-captcha-response' => [new \Core45\HCaptcha\Rules\HCaptcha],
]);
```

Do not add `required` alongside this rule. `Core45\HCaptcha\Rules\HCaptcha` declares
`public bool $implicit = true`, so it already runs — and reports its own message — when the field
is missing or empty. Pairing it with `required` makes Laravel's generic "required" message win the
race and suppresses the package's "Please complete the captcha." message; this is covered by
`tests/Feature/ValidationTest.php`.

`Core45\HCaptcha\Rules\HCaptcha::validate()` resolves the shared `Verifier` from the container (or
uses one injected via the constructor, useful in tests), calls `verify($token, $clientIp, $attribute)`
— passing the validated attribute as the memoization scope — and on failure calls
`$fail($result->messageKey())->translate()`. `messageKey()` distinguishes:

```php
public function messageKey(): string
{
    return match (true) {
        $this->tokenMissing() => 'hcaptcha::hcaptcha.missing',
        $this->serviceUnavailable => 'hcaptcha::hcaptcha.unavailable',
        $this->tokenAlreadyUsed() => 'hcaptcha::hcaptcha.expired',
        default => 'hcaptcha::hcaptcha.failed',
    };
}
```

`tokenAlreadyUsed()` checks both `token-already-used` and `invalid-input-response` error codes.
`clientIp()` reads from the bound `Request` unless one was passed to the constructor; hCaptcha
treats `remoteip` as a signal, not an assertion, so a missing/proxied IP only costs accuracy, never
correctness.

### String rules: `hcaptcha` and `captcha`

```php
$request->validate(['h-captcha-response' => 'hcaptcha']);
```

Do not add `required` here either. Registered in `HCaptchaServiceProvider::bootValidator()` via
`Validator::extendImplicit()`, so — like the rule object — it already runs, and already reports
`hcaptcha::hcaptcha.missing`, on an empty or absent token; a `required` rule alongside it would only
compete for which message wins. `captcha` is registered identically, purely as a migration alias
for `buzz/laravel-h-captcha` users. The string rule can only surface one message per failed
attribute; the provider stashes the resolved message key in
`$this->stringRuleMessages[$attribute]` between the `extend` and `replacer` callbacks (the
string-rule API gives no other way to hand a specific message to the replacer). The rule object
does not need this indirection and is the better entry point when you want to branch on the
specific failure reason in your own code. Message granularity is the only remaining difference
between the two forms — both are equally implicit.

## Middleware

```php
Route::post('/contact', ContactController::class)->middleware('hcaptcha');
Route::post('/contact', ContactController::class)->middleware('hcaptcha:my-field');
```

`Core45\HCaptcha\Http\Middleware\VerifyHCaptcha`, aliased as `hcaptcha` in
`HCaptchaServiceProvider::bootMiddleware()`. It verifies **every** request it sees, including
`GET`/`HEAD`/`OPTIONS` — there is no method allowlist. Reads the token from the middleware parameter
if given, else `config('hcaptcha.field')`, and passes the resolved field name as the verifier's
memoization scope. On failure it throws
`Illuminate\Validation\ValidationException::withMessages([$field => [__($result->messageKey())]])`
— a 422 for JSON/API clients, a redirect-with-errors for a normal form post. Safe to combine with
the rule object or the Filament field on the same field: memoization means the token is spent once
regardless of how many of these run against it.

**Put this middleware on the state-changing route only, never on a route group that also serves the
GET that renders the form** — since it now verifies every method, a shared group would fail the
GET too. The previous version's method allowlist was removed deliberately: it was spoofable.
Symfony's `_method` override refuses to honour the override only for `GET`, `HEAD`, `CONNECT`, and
`TRACE` — so a POST carrying `_method=OPTIONS` was reported to the middleware as method `OPTIONS`
and skipped verification, while the controller still received and processed the full POST body. A
route that fails loudly (422) when misconfigured is safer than one that can be silently bypassed by
an attacker-controlled header.

The package does not rate limit anything. Pair `hcaptcha` with `throttle` on the same route:

```php
Route::post('/contact', ContactController::class)->middleware(['throttle:10,1', 'hcaptcha']);
```

## Livewire

```blade
<x-hcaptcha model="captchaToken" />
```

```php
public string $captchaToken = '';

public function submit(): void
{
    $this->validate(['captchaToken' => [new \Core45\HCaptcha\Rules\HCaptcha]]);

    // ... handle the submission ...

    $this->captchaToken = '';
    $this->dispatch('core45HCaptcha:reset');
}
```

The widget's outer `<div class="hcaptcha" wire:ignore>` stops Livewire's DOM morph from touching
the iframe hCaptcha owns; a morph mid-challenge kills the challenge. Re-rendering after a Livewire
DOM patch is instead handled entirely by the bootstrap script in `resources/views/script.blade.php`,
which hooks:

- `livewire:init` → registers `Livewire.hook('morphed', renderAll)` and
  `Livewire.hook('morph.added', renderAll)`
- `livewire:navigated` → `renderAll()`

**Why reset is mandatory:** hCaptcha tokens are single-use. After any submit — successful or
rejected — the widget is left holding a spent token. Leaving it in place makes the *next* attempt
fail with `token-already-used`, which is confusing because nothing about the next attempt was
actually wrong.

### JS entry points exposed by `script.blade.php`

The global namespace object is `window.core45HCaptcha` (`HCaptchaManager::namespaceName()`), with:

| Member | Signature | Behaviour |
| --- | --- | --- |
| `widgets` | object | Map of DOM id → hCaptcha widget id |
| `render(el)` | function | Renders one container element if the API is ready and it is not already rendered |
| `renderAll()` | function | Renders every `[data-hcaptcha-explicit]` element not currently holding a live iframe |
| `reset(id?)` | function | Resets one widget by DOM id via `window.hcaptcha.reset()` and clears its response field; called with no `id`, resets every widget |
| `markReady()` | function | Called once by the `onload` callback; flips `apiReady` and calls `renderAll()` |
| `container(id)` | function | `document.getElementById(id)` helper |

The `onload` callback itself is a separate global, `window.core45HCaptchaOnLoad`
(`HCaptchaManager::callbackName()`), which hCaptcha's `api.js` invokes once the library is ready —
it exists only to call `markReady()` and must be defined before `api.js` loads, which is why the
bootstrap `<script>` block is emitted before the `api.js` `<script src>` tag.

A `core45HCaptcha:reset` **window event** is also listened for, as a decoupled alternative to
calling `reset()` directly:

```js
window.addEventListener('core45HCaptcha:reset', (event) => reset(event.detail?.id));
```

Dispatch it from anywhere — Livewire's `$this->dispatch()`, plain JS, another framework — with
`{ detail: { id } }`, or no detail at all to reset every widget on the page.

## Filament

```php
use Core45\HCaptcha\Filament\Forms\Components\HCaptcha;

HCaptcha::make()          // field name defaults to config('hcaptcha.field')
    ->theme('dark')
    ->size('compact')
    ->locale('es')
    ->sitekey('...'),     // per-field override
```

`Core45\HCaptcha\Filament\Forms\Components\HCaptcha extends Filament\Forms\Components\Field`.
`setUp()` calls `rule(new HCaptchaRule)`, `markAsRequired()`, `label(__('hcaptcha::hcaptcha.label'))`,
`live(false)` (the widget publishes its own token via JS; there is no reason for a Livewire round
trip on keystroke, and there are no keystrokes), and — deliberately — `dehydrated(false)`.

It calls `markAsRequired()`, **not** `required()`. `markAsRequired()` only adds the asterisk to the
field's label; it adds no validation rule. Calling `required()` here would add a second, competing
rule — and since the `HCaptcha` rule is already implicit (see the Validation section above),
`required()` would only race it and let Laravel's generic message win instead of the package's own.

`dehydrated(false)` only removes the field from the dehydrated/saved state that reaches
`->action()` or the model save. **Validation still runs against the raw form state before
dehydration**, so the captcha is still enforced; it is simply never written anywhere, which is
correct because the token is single-use and already spent by the time the form is valid — there is
nothing meaningful to persist.

## Manual verification

```php
use Core45\HCaptcha\Facades\HCaptcha;

$result = HCaptcha::verify($request->input('h-captcha-response'), $request->ip());
```

`VerificationResult` fields: `success`, `hostname`, `challengeTs` (`?Carbon`), `score`,
`scoreReasons` (list), `errorCodes` (list), `credit`, `serviceUnavailable`, `rejectedBy`.

`VerificationResult::fromResponse()` sets `success` with `($payload['success'] ?? null) === true` —
an identity check, not a cast. `(bool) "false"`, `(bool) "error"`, and `(bool) -1` are all `true` in
PHP, so casting a malformed 200 response body could have turned a rejection into a pass in the one
control whose entire job is to fail closed.

Helpers: `passed()` / `failed()`, `hasErrorCode(string $code)`, `tokenAlreadyUsed()`,
`tokenMissing()`, `isConfigurationError()`, `messageKey()`, `toArray()` / `jsonSerialize()`.

`passed()` is not simply hCaptcha's `success`. `HttpVerifier::assert()` applies two local
assertions after a genuine `success: true` response:

1. **Hostname check** — if `hostnames` is configured and the response's `hostname` is not in that
   list (case-insensitively), the result becomes `rejectedLocally('hostname-mismatch')`.
2. **Score check** — if `max_score` is configured and the response's `score` exceeds it, the result
   becomes `rejectedLocally('score-too-high')`.

`rejectedLocally()` flips `success` to `false`, appends the reason to `errorCodes`, and sets
`rejectedBy`. Both of these get logged as `warning` (not `error`, since the token itself was
genuine) via `HttpVerifier::report()`.

## Failure modes and error codes

`HttpVerifier::call()` converts every transport failure into `unavailable()` rather than letting an
exception escape into the form handler:

- A thrown exception from the `Http` client (DNS, connection refused, timeout after retries).
- A non-2xx HTTP status (`$response->failed()`).
- A body that does not decode to a JSON object (`$response->json()` not an array).

All three are logged as `warning` with the exception message or status code. What happens next
depends on `fail_open`:

- **`fail_open = false` (default, fail closed):** `VerificationResult::unavailable()` —
  `success: false`, `errorCodes: ['service-unavailable']`, `serviceUnavailable: true`. The form is
  rejected. This is the safer default: an hCaptcha outage should not become an open door for spam.
- **`fail_open = true`:** a result with `success: true` but still `errorCodes: ['service-unavailable']`
  and `serviceUnavailable: true` — the submission is accepted, but the outage is still visible to
  anything inspecting the result (including the audit trail, if enabled).

Configuration errors are distinguished from visitor-caused rejections by
`isConfigurationError()`, which checks for: `missing-input-secret`, `invalid-input-secret`,
`bad-secret`, `no-such-user`, `invalid-sitekey`, `sitekey-mismatch`. These are logged at `error`
level (`HttpVerifier::report()`), since they mean the site owner misconfigured the package, not
that the visitor did anything wrong.

Full set of error codes this package interprets directly: `missing-input-response` (→
`tokenMissing()`), `token-already-used` and `invalid-input-response` (→ `tokenAlreadyUsed()`), plus
the configuration-error set above and the two locally-appended reasons `hostname-mismatch` /
`score-too-high`. Any other code from hCaptcha's response (e.g. `bad-request`, `invalid-user-ip`,
`internal-error`) is preserved in `errorCodes` but falls through to the generic
`hcaptcha::hcaptcha.failed` message.

## Audit trail

Enable: `HCAPTCHA_LOGGING=true`, publish + run `hcaptcha-migrations`.

`VerificationLogger::record()` is called after every verification attempt that actually reaches
`HttpVerifier::verify()` — including a missing-token short-circuit, which is recorded with
`token_hash: null`. A logging failure (e.g. a database outage) is caught and logged as a `warning`,
never allowed to fail the verification itself.

Stored columns (`hcaptcha_verifications` migration): `success` (indexed), `token_hash` (SHA-256,
64 chars, indexed — never the raw token), `hostname`, `challenge_ts`, `score` (`decimal(5,4)`,
nullable), `error_codes` (JSON, nullable), `rejected_by`, `ip` (nullable, gated by
`logging.store_ip`), `user_agent` (nullable, gated by `logging.store_user_agent`, truncated to 512
chars), `url` (nullable, gated by `logging.store_url`, truncated to 2048 chars), timestamps
(`created_at` indexed for pruning).

`logging.store_ip`, `logging.store_user_agent`, and `logging.store_url` all default to `false`.
When `store_url` is enabled, the stored value is the request path **without its query string** —
query strings routinely carry signed-URL signatures, password-reset tokens, and email addresses,
none of which belong sitting in a 90-day audit table.

`logging.log_missing_token` (`HCAPTCHA_LOG_MISSING_TOKEN`, default `false`) controls whether a
tokenless submission gets a row at all. `HttpVerifier::verify()` short-circuits on a missing token
before any HTTP call, so recording it by default would let anyone inflate the audit table for free
with unauthenticated POSTs that never touched hCaptcha. Only enable it alongside route throttling.

**Why not the raw token:** by the time a token could be logged it has already been spent against
hCaptcha — a stored copy would have no verification value and would only be a liability if the
database were ever compromised. The SHA-256 hash is enough to spot a replayed token
(`HCaptchaVerification::scopeForToken()`), without being usable to forge or replay anything.

Model scopes: `scopeFailed()` (`where('success', false)`), `scopeForToken(string $tokenHash)`.

Pruning:

```bash
php artisan hcaptcha:prune                 # uses hcaptcha.logging.retention_days (default 90)
php artisan hcaptcha:prune --days=30       # override the window for this run
php artisan hcaptcha:prune --chunk=500     # rows deleted per query, default 1000
```

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('hcaptcha:prune')->daily();
```

`retention_days <= 0` disables pruning entirely — the command prints a warning and does nothing,
rather than deleting the whole table.

## Testing

No network calls are needed to test anything in a consuming application — fake the HTTP layer:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.hcaptcha.com/*' => Http::response([
        'success' => true,
        'hostname' => 'example.test',
        'challenge_ts' => now()->toIso8601String(),
    ]),
]);

$this->post('/contact', ['h-captcha-response' => 'any-token'])
    ->assertSessionHasNoErrors();

Http::assertSentCount(1); // proves memoization: one token per field, one HTTP call
```

To test a rejected token:

```php
Http::fake([
    'api.hcaptcha.com/*' => Http::response([
        'success' => false,
        'error-codes' => ['invalid-input-response'],
    ]),
]);
```

To test the failure path without mocking a bad response, fake a connection exception or a non-2xx
status and assert against `hcaptcha.fail_open`.

If testing the same token against more than one guard on the *same* field (e.g. rule + middleware in
the same request), `Http::assertSentCount(1)` is the assertion that proves the memoization contract
holds — a second HTTP call means the shared-`Verifier` invariant was broken somewhere. Testing the
same token against two *different* fields should assert `Http::assertSentCount(2)` instead: the
verifier is memoized per `(token, scope)`, so different scopes are expected to hit hCaptcha
separately.
