# hCaptcha Development Guide

Long-form reference for `core45/laravel-h-captcha`. See the package README for the quick-start version; this
goes deeper on failure modes and the error-code list.

## Setup

```bash
composer require core45/laravel-h-captcha
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

`hcaptcha-migrations` only needs publishing if you want to own the audit migration yourself —
switching the audit trail on is enough for the package to load its own copy, so the usual path is
`HCAPTCHA_LOGGING=true` plus `php artisan migrate` with no publish step. See
[Audit trail](#audit-trail). Nothing else in the package touches a database.

Both `sitekey` and `secret` default to `null`. This is deliberate: a missing secret throws
`MissingSecretException` the first time a real verification is attempted, and a missing sitekey
throws `MissingSitekeyException` when something asks for one directly — rather than either silently
rejecting every visitor (a placeholder default would do this) or rendering a broken widget with no
indication why. `HCaptchaManager::configured()` lets a view check first and degrade instead.

## Migrating from thinhbuzz/laravel-h-captcha

```bash
composer remove buzz/laravel-h-captcha
composer require core45/laravel-h-captcha
```

No application code needs to change for the swap itself. A compatibility layer, isolated in
`Core45\HCaptcha\Compat\`, reproduces the old package's `Captcha` facade and container binding on
top of the native `HCaptchaManager` and `Verifier` — the old surface is a thin caller of the same
code every native entry point uses, not a second implementation.

### What keeps working unchanged

- The env keys `CAPTCHA_SECRET` and `CAPTCHA_SITEKEY`. Precedence is `HCAPTCHA_*` over `CAPTCHA_*`,
  so a project that has migrated part of its `.env` is not silently overridden by a stale key it
  forgot to delete.
- A previously published `config/captcha.php`. Its `secret`, `sitekey`, `options.lang`, and
  `attributes` values are adopted into the equivalent `hcaptcha.*` key wherever that key is itself
  unset. A configured `hcaptcha.*` value always wins over the legacy config — this is a fallback,
  not an override.
- The `Captcha` facade (container binding `'captcha'`, alias `Captcha`), with all eight methods the
  old package exposed: `display()`, `displayMultiple()`, `displayJs()`, `multiple()`,
  `setOptions()`, `verify()`, `getWidgetIdName()`, `getJsVariableName()`.
- `Captcha::verify($response, $clientIp = null, $options = [])` returns a plain **bool**, exactly as
  the old package did, because old call sites write `if (Captcha::verify(...))`. Reach for the
  native `HCaptcha::verify()` (see [Manual verification](#manual-verification)) when the caller
  needs to know *why* a token failed, not just whether it passed — it returns the full
  `VerificationResult`.
- The `captcha` string validation rule — registered as an alias of this package's own `hcaptcha`
  string rule, so it resolves the same translated message keys via
  `VerificationResult::messageKey()`.
- The `Form::captcha()` macro, registered only when the container has a `form` binding, exactly like
  the old provider guarded it.

### What is different, and why

- **`http_client` is ignored.** The old config named a Guzzle-based HTTP client class. Replacing
  Guzzle with Laravel's own `Http` client is the entire reason this package exists — see
  [Highlights](../../../../../README.md#highlights) in the README: `buzz/laravel-h-captcha` pins
  `guzzlehttp/guzzle 6.*|7.*` and cannot install alongside Guzzle 8. A configured `http_client` logs
  a warning and is otherwise skipped; it never fails the boot.
- **The old placeholder defaults now throw.** The reference package's config defaulted `secret` and
  `sitekey` to the literal strings `default_secret` and `default_sitekey`. A half-configured install
  therefore carried those literals into every `siteverify` call and every verification failed with
  no indication why — the visitor just saw a rejected captcha. This package treats those two
  literals as **not configured** and raises `MissingSecretException` / `MissingSitekeyException`
  instead, which is loud where the old behaviour was silent.
- **`multiple` mode needs no emulation.** Every widget in this package already renders in hCaptcha's
  explicit mode, and the bootstrap script's `renderAll()` picks up every `[data-hcaptcha-explicit]`
  container on the page, so several widgets on one page already work without any special mode.
  `displayMultiple()` exists purely so an old call site does not fatal or double-render; it returns
  an empty string.
- **`verify()` no longer swallows every failure into an unexplained `false`.** Underneath the bool,
  the native layer distinguishes a missing token, an already-spent token, an unreachable hCaptcha
  endpoint, and an outright rejection (see
  [Failure modes and error codes](#failure-modes-and-error-codes)), and fails **closed** on a
  transport error unless `HCAPTCHA_FAIL_OPEN=true` is set — see
  [Fail-open vs fail-closed](../../../../../README.md#fail-open-vs-fail-closed) in the README.

### The hostname check is the most likely migration surprise

`hostnames` is **on by default** in this package (see
[Config reference](#config-reference)), derived from `APP_URL`. The old
package had no equivalent check. If the migrated form is served from a host other than `APP_URL` —
a staging subdomain, a second brand on the same install, a reverse proxy — genuine submissions will
start failing with `hostname-mismatch` immediately after the swap, with no code change to point to.
Set `HCAPTCHA_HOSTNAMES` to a comma-separated list of every hostname the form is legitimately served
from before going live with the migration.

### Recommended follow-up after migrating

- Switch call sites to `<x-hcaptcha />` and the `HCaptcha` rule object — better per-outcome error
  messages than the compat layer's single bool.
- Move `.env` keys from `CAPTCHA_*` to `HCAPTCHA_*`.
- Delete `config/captcha.php` once nothing reads it.
- Add `throttle` to the route the captcha guards (see
  [Rate limiting](../../../../../README.md#rate-limiting) in the README) — neither package throttles
  on its own.

## Config reference

`config/hcaptcha.php` may not call `app()`, `trans()`, or any other container helper — the file is
evaluated once by `php artisan config:cache` and frozen. This is why the widget locale is resolved
at render time in `HCaptchaManager::locale()`, not baked into the config file.

```php
'sitekey' => env('HCAPTCHA_SITEKEY'),
'secret' => env('HCAPTCHA_SECRET'),
'profiles' => [
    // 'marketing' => [
    //     'sitekey' => env('HCAPTCHA_MARKETING_SITEKEY'),
    //     'secret' => env('HCAPTCHA_MARKETING_SECRET'),
    // ],
],
'endpoint' => env('HCAPTCHA_ENDPOINT', 'https://api.hcaptcha.com/siteverify'),
'timeout' => (int) env('HCAPTCHA_TIMEOUT', 10),
'retries' => (int) env('HCAPTCHA_RETRIES', 0),
'max_token_length' => (int) env('HCAPTCHA_MAX_TOKEN_LENGTH', 8192),
'fail_open' => (bool) env('HCAPTCHA_FAIL_OPEN', false),
'send_sitekey' => (bool) env('HCAPTCHA_SEND_SITEKEY', true),
'hostnames' => env('HCAPTCHA_HOSTNAMES', parse_url((string) env('APP_URL'), PHP_URL_HOST)),
'hostnames_strict' => (bool) env('HCAPTCHA_HOSTNAMES_STRICT', false),
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
    'log_oversized_token' => (bool) env('HCAPTCHA_LOG_OVERSIZED_TOKEN', false),
    'retention_days' => (int) env('HCAPTCHA_RETENTION_DAYS', 90),
    'migrations' => env('HCAPTCHA_LOGGING_MIGRATIONS') !== null
        ? filter_var(env('HCAPTCHA_LOGGING_MIGRATIONS'), FILTER_VALIDATE_BOOL)
        : null,
],
```

`max_token_length` rejects a token longer than this without making an HTTP call
(`HttpVerifier::maxTokenLength()`), so an unauthenticated request cannot make the package proxy a
huge body to hCaptcha while holding a PHP worker for the whole timeout.

`send_sitekey` includes the sitekey in the verification POST so hCaptcha itself rejects a token
minted for a different site (`sitekey-secret-mismatch`), in addition to any local `hostnames` check.

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
and only Enterprise accounts ever populate `score` in the response; on other accounts the check
is silently skipped because `$result->score` is `null`.

`hostnames_strict` (`HCAPTCHA_HOSTNAMES_STRICT`, default `false`) extends the `hostnames` check to
reject a response whose hostname is *missing* or reported as `not-provided`, rather than letting it
through. hCaptcha omits the hostname during busy periods, so turning this on trades a small number of
false rejections for closing the one hole `hostnames` otherwise leaves open. `hcaptcha:doctor` warns
when it is enabled.

## Credential profiles

One installation can serve several sites or brands from different hCaptcha accounts. Name each
sitekey/secret pair under `hcaptcha.profiles`, then select it per widget, per rule, per route, or per
Filament field.

```php
// config/hcaptcha.php
'profiles' => [
    'marketing' => [
        'sitekey' => env('HCAPTCHA_MARKETING_SITEKEY'),
        'secret' => env('HCAPTCHA_MARKETING_SECRET'),
    ],
],
```

```blade
<x-hcaptcha profile="marketing" />
```

```php
use Core45\HCaptcha\Rules\HCaptcha as HCaptchaRule;

$request->validate(['h-captcha-response' => [new HCaptchaRule(profile: 'marketing')]]);

// middleware parameters are field,sitekey,profile -- leave the sitekey empty to take it from the profile
Route::post('/signup', SignupController::class)->middleware('hcaptcha:h-captcha-response,,marketing');

// Filament
\Core45\HCaptcha\Filament\Forms\Components\HCaptcha::make()->profile('marketing');
```

Resolution rules (`Core45\HCaptcha\Support\Credentials::for()`):

- A `null` or empty profile returns the global `hcaptcha.sitekey` / `hcaptcha.secret`.
- **An unknown profile name throws `InvalidArgumentException`** — it deliberately does *not* fall back
  to the global pair. A silent fallback would pair one account's sitekey with another account's
  secret and fail every verification with `sitekey-secret-mismatch`, with nothing to say why.
- A profile that defines only one half inherits the other half from the global config, so a profile
  may override the sitekey alone. A value that is present but unusable — an empty string, or one of
  the placeholder literals in `HCaptchaManager::PLACEHOLDER_CREDENTIALS`, tested by
  `HCaptchaManager::isUsableCredential()` — counts as unset and inherits too. `hcaptcha:doctor` warns
  about a half-overridden profile, because that is how a mismatch usually arrives.

**Never let the request choose its own profile.** The profile selects the secret that vouches for the
token, so a visitor-supplied profile name lets the visitor decide which account validates their
answer. Pick the profile in server-side code.

Facade helpers: `HCaptcha::profileNames()`, `HCaptcha::profileSitekey(?string $profile)`,
`HCaptcha::profileCredentials(?string $profile)`.

The profile is part of the memoization key, not just the credentials. `HttpVerifier` keys its memo on
`$tokenHash.'|'.$scope.'|'.$context->credentialKey()`, and `VerificationContext::credentialKey()` is
`profile|sitekey` — so a verdict obtained under one profile can never vouch for a check made under
another, even for the same token and field.

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

### Deterministic widget ids

Widget ids follow `hcaptcha-page-N` on a plain page and `hcaptcha-<livewire-id>-N` inside a Livewire
component, so a widget re-rendered after a DOM patch binds to the same hidden input instead of
minting a new, unbound one. Pass `id="..."` to choose your own. The hidden response input is always
`<id>-response`, and a `<p id="<id>-status" role="status" aria-live="polite">` live region carries
the widget's pending/error text (`hcaptcha::hcaptcha.widget_pending`, `widget_error`).

### Custom callbacks

`callback`, `expired-callback`, `chalexpired-callback`, `error-callback`, `open-callback` and
`close-callback` in `options` name a global function (dotted paths resolve from `window`, e.g.
`app.onCaptcha`). The package writes the token to the hidden input first, then calls yours.

### Invisible mode

`size="invisible"` renders nothing until the form is submitted. The bootstrap intercepts the form's
`submit` event, runs `hcaptcha.execute()`, writes the token, and resubmits — for plain forms and
`wire:submit` alike. A second submit while a challenge is already pending is ignored. A failed or
closed challenge shows `widget_error` in the status element and lets the visitor try again.
`window.core45HCaptcha.execute(id)` runs it programmatically and resolves with the token.

### Content Security Policy

Both script tags carry `nonce="..."` when a nonce resolves, from `HCaptchaManager::nonceUsing()` or
else `Vite::cspNonce()`. `HCaptchaManager::cspDirectives()` returns the origins hCaptcha needs for
`script-src`, `frame-src`, `style-src` and `connect-src`.

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
        $this->tokenAlreadyUsed(), $this->tokenExpired() => 'hcaptcha::hcaptcha.expired',
        default => 'hcaptcha::hcaptcha.failed',
    };
}
```

`tokenAlreadyUsed()` matches `already-seen-response` and `invalid-or-already-seen-response` (plus the 1.x alias `token-already-used`); `tokenExpired()` matches `expired-input-response`; `tokenMalformed()` matches `invalid-input-response` and `token-too-long`.
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
}
```

The widget's outer `<div class="hcaptcha" wire:ignore>` stops Livewire's DOM morph from touching
the iframe hCaptcha owns; a morph mid-challenge kills the challenge. Re-rendering after a Livewire
DOM patch is instead handled by a `MutationObserver` in the bootstrap script
(`resources/js/bootstrap.js`), which renders any `[data-hcaptcha-explicit]` element that appears
anywhere in the document — a Livewire morph, an Alpine toggle, a modal, plain JavaScript — and
prunes a widget's entry from the registry once its element is removed. There is no hook list to
maintain: the observer watches the whole document, not specific Livewire lifecycle events.

**Reset is automatic, not something you call.** hCaptcha tokens are single-use, so after *any*
verification inside a Livewire component — accepted or rejected, even when a different field on the
same form failed — `Rules\HCaptcha` dispatches `core45HCaptcha:reset` with the validated field name,
and the bootstrap resets exactly that widget. Calling `$this->dispatch('core45HCaptcha:reset')`
yourself is no longer needed and, if you do it, changes the event's meaning: an event with a `field`
in its detail resets that one widget (the rule's own shape), an event with an `id` resets one widget
by DOM id, and an event with neither resets every widget on the page.

```js
window.addEventListener('core45HCaptcha:reset', (event) => {
    // event.detail === { field: 'data.h-captcha-response' } — from the validation rule
    // event.detail === { id: 'hcaptcha-page-1' }             — an id-scoped reset from your own code
    // event.detail === undefined (or {})                     — resets every widget
});
```

### JS entry points exposed by the bootstrap script

The global namespace object is `window.core45HCaptcha` (`HCaptchaManager::namespaceName()`), with:

| Member | Signature | Behaviour |
| --- | --- | --- |
| `widgets` | object | Map of DOM id → hCaptcha widget id |
| `render(el)` | function | Renders one container element if the API is ready and it is not already rendered |
| `renderAll()` | function | Renders every `[data-hcaptcha-explicit]` element not currently holding a live iframe; the `MutationObserver` calls this as elements are added, and it prunes widgets whose element has been removed |
| `reset(id?)` | function | Resets one widget by DOM id via `window.hcaptcha.reset()` and clears its response field; called with no `id`, resets every widget |
| `execute(id)` | function | Runs the challenge of an invisible widget; resolves with the token, which is also published to the widget's hidden input |
| `markReady()` | function | Called once by the `onload` callback; flips `apiReady` and calls `renderAll()` |
| `container(id)` | function | `document.getElementById(id)` helper |

The `onload` callback itself is a separate global, `window.core45HCaptchaOnLoad`
(`HCaptchaManager::callbackName()`), which hCaptcha's `api.js` invokes once the library is ready —
it exists only to call `markReady()` and must be defined before `api.js` loads, which is why the
bootstrap `<script>` block is emitted before the `api.js` `<script src>` tag.

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

When no usable sitekey resolves, the field renders no widget (a debug-only notice, same policy as
the Blade component) but stays in validation, so the form still fails closed. Widget ids are scoped
to the Livewire component, so two forms with the same state path on one page do not collide.

## Manual verification

```php
use Core45\HCaptcha\Facades\HCaptcha;

$result = HCaptcha::verify($request->input('h-captcha-response'), $request->ip());
```

`VerificationResult` fields: `success`, `accepted`, `hostname`, `challengeTs` (`?Carbon`), `score`,
`scoreReasons` (list), `errorCodes` (list), `credit`, `serviceUnavailable`, `rejectedBy`.

`success` is hCaptcha's raw verdict; **`accepted` is this package's final one**, and it is what
`passed()` returns. The two differ whenever a local check rejects a token hCaptcha itself accepted —
a hostname mismatch or a score over `max_score`. Filter on `accepted`, not `success`.

`VerificationResult::fromResponse()` sets `success` with `($payload['success'] ?? null) === true` —
an identity check, not a cast. `(bool) "false"`, `(bool) "error"`, and `(bool) -1` are all `true` in
PHP, so casting a malformed 200 response body could have turned a rejection into a pass in the one
control whose entire job is to fail closed.

Helpers: `passed()` / `failed()`, `hasErrorCode(string $code)`, `tokenAlreadyUsed()`,
`tokenExpired()`, `tokenMalformed()`, `tokenMissing()`, `isConfigurationError()`, `messageKey()`,
`toArray()` / `jsonSerialize()`.

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
`sitekey-secret-mismatch`, `bad-request`, `not-using-dummy-passcode`, `not-using-dummy-secret`.
These are logged at `error`
level (`HttpVerifier::report()`), since they mean the site owner misconfigured the package, not
that the visitor did anything wrong.

Full set of error codes this package interprets directly: `missing-input-response` (→
`tokenMissing()`), `already-seen-response` / `invalid-or-already-seen-response` / the 1.x alias
`token-already-used` (→ `tokenAlreadyUsed()`), `expired-input-response` (→ `tokenExpired()`),
`invalid-input-response` / `token-too-long` (→ `tokenMalformed()`), plus
the configuration-error set above and the two locally-appended reasons `hostname-mismatch` /
`score-too-high`. Any other code from hCaptcha's response (e.g. `invalid-user-ip`,
`internal-error`) is preserved in `errorCodes` but falls through to the generic
`hcaptcha::hcaptcha.failed` message.

## Audit trail

Enable: set `HCAPTCHA_LOGGING=true` and run `php artisan migrate`. **Publishing is not required** —
`logging.migrations` is `null` by default, which follows `logging.enabled`, so the package loads its
own migration once the audit trail is switched on and creates nothing in an application that never
asked for the table (`HCaptchaServiceProvider::shouldLoadMigrations()`). Set
`HCAPTCHA_LOGGING_MIGRATIONS=true` to keep loading it regardless — what an installation that already
has the table wants, so `migrate:status` keeps recognising it — or `false` plus
`php artisan vendor:publish --tag=hcaptcha-migrations` to own the migration outright.

`VerificationLogger::record()` is called after every verification attempt that actually reaches
`HttpVerifier::verify()` — including a missing-token short-circuit, which is recorded with
`token_hash: null`. A logging failure (e.g. a database outage) is caught and logged as a `warning`,
never allowed to fail the verification itself.

Stored columns (`hcaptcha_verifications` migration): `success` (indexed — hCaptcha's raw verdict),
`accepted` (indexed — the package's final verdict after the local hostname and score checks; this is
the column to filter on), `token_hash` (SHA-256,
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

`logging.log_oversized_token` (`HCAPTCHA_LOG_OVERSIZED_TOKEN`, default `false`) is the same trade for
a token longer than `max_token_length`: it is rejected before any HTTP call, so recording it is free
for the sender. The row carries no token hash.

**Why not the raw token:** by the time a token could be logged it has already been spent against
hCaptcha — a stored copy would have no verification value and would only be a liability if the
database were ever compromised. The SHA-256 hash is enough to spot a replayed token
(`HCaptchaVerification::scopeForToken()`), without being usable to forge or replay anything.

Model scopes: `scopeFailed()` (`where('accepted', false)` — the package's verdict, *not*
hCaptcha's raw `success`), `scopeRejectedLocally()` (`whereNotNull('rejected_by')` — passed hCaptcha
but failed the hostname or score check), `scopeForToken(string $tokenHash)`.

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

## Events

Every verification that reaches `HttpVerifier::verify()` dispatches
`Core45\HCaptcha\Events\VerificationCompleted` (`HttpVerifier.php:128`) — a `final readonly class`
carrying `VerificationResult $result`, `VerificationContext $context`, and `string $tokenHash` (the
SHA-256 hash; the raw token is never in the payload). It also exposes `passed(): bool`.

This is the hook for metrics, alerting, or a custom store **without** opting into the database audit
trail:

```php
Event::listen(VerificationCompleted::class, function (VerificationCompleted $event) {
    if (! $event->passed()) {
        Metrics::increment('hcaptcha.rejected', ['field' => $event->context->field]);
    }
});
```

A memoized verdict does not re-dispatch — the event fires once per real verification, not once per
caller.

## Swapping the verifier

Everything verifies through the `Core45\HCaptcha\Contracts\Verifier` interface, bound to
`HttpVerifier` as a **scoped** binding. The interface is two methods:

```php
public function verify(?string $token, ?string $clientIp = null, string|VerificationContext|null $scope = null): VerificationResult;
public function flush(): void;
```

Rebind `Verifier::class` to supply your own implementation — that is the supported extension point,
and the only correct alternative to calling `siteverify` directly. `flush()` clears the per-request
memo; the scoped binding already resets it between requests, so it matters only in a long-running
worker (Octane, a queue worker) that handles more than one logical request in one container
lifetime.

## Diagnostics

```bash
php artisan hcaptcha:doctor
```

Diagnoses the misconfigurations that otherwise surface as runtime verification failures. It makes no
network calls and never prints a secret, and it exits non-zero when it finds a problem, so it works
as a CI or deploy gate. It checks:

- **Global credentials** — `sitekey` and `secret` present and usable. Prints the sitekey (it is
  public anyway), confirms only that the secret is set. Warns when hCaptcha's public *test*
  credentials are in use outside `local`/`testing`.
- **Profiles** — that every profile in `hcaptcha.profiles` resolves to a usable pair. Warns on a
  profile that overrides one half and inherits the other, the usual source of
  `sitekey-secret-mismatch`.
- **Hostnames** — warns when `hostnames` is unset (the dashboard allowlist is then the only origin
  control), and when `hostnames_strict` is on (it can reject responses during hCaptcha busy periods).
- **Audit trail** — when logging is enabled, that the table exists; errors with a "run migrations"
  hint when it does not. Warns when IP, user agent, or URL storage is on, as a reminder that those
  need a lawful basis and a retention policy.

## Testing

### `HCaptcha::fake()` — the package's own test double

The shortest path: swap the verifier, no HTTP layer involved.

```php
use Core45\HCaptcha\Facades\HCaptcha;
use Core45\HCaptcha\Testing\FakeVerifier;

$hcaptcha = HCaptcha::fake();            // passes everything
$hcaptcha = HCaptcha::fake(false);       // rejects everything

$this->post('/contact', ['h-captcha-response' => FakeVerifier::TOKEN])
    ->assertSessionHasNoErrors();

$hcaptcha->assertVerifiedFor('h-captcha-response');
$hcaptcha->assertVerifiedTimes(1);
```

`HCaptcha::fake()` instance-binds `Verifier::class` to a `FakeVerifier` and forgets the resolved
manager, so the widget renders the package's fake partial (`resources/views/fake.blade.php`) instead
of loading hCaptcha's SDK — a browser test can then solve the captcha without a network call.
`FakeVerifier` deliberately does not extend `HttpVerifier`, so a faked test can never reach the real
endpoint.

Shaping the answer: `pass()`, `fail(string $errorCode = 'invalid-input-response')`,
`respondWith(Closure|VerificationResult $answer)`. A `null` or empty token always yields
`missingToken()` regardless of how the fake is configured, so the implicit-rule behaviour stays
honest.

Assertions: `assertVerified(?Closure $callback = null)`, `assertVerifiedFor(string $field)`,
`assertVerifiedTimes(int $times)`, `assertNothingVerified()`. The raw record list is available via
`verifications()`, each entry being `['token' => ..., 'clientIp' => ..., 'context' => ..., 'result' => ...]`.

Note that `FakeVerifier` does **not** memoize — `flush()` is a no-op and every call is recorded. That
is the point: `assertVerifiedTimes()` counts call sites, which is the opposite of what the HTTP-level
assertion below measures. To assert the *memoization contract* itself, use `Http::fake()` and count
HTTP calls.

### `Http::fake()` — for asserting the memoization contract

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

To test the failure path without mocking a bad response, fake a connection exception, or a non-2xx
status with a non-JSON body; a non-2xx response that still carries a `success` key is applied as a
verdict and never fails open. Assert against `hcaptcha.fail_open`.

If testing the same token against more than one guard on the *same* field (e.g. rule + middleware in
the same request), `Http::assertSentCount(1)` is the assertion that proves the memoization contract
holds — a second HTTP call means the shared-`Verifier` invariant was broken somewhere. Testing the
same token against two *different* fields should assert `Http::assertSentCount(2)` instead: the
verifier is memoized per `(token, scope)`, so different scopes are expected to hit hCaptcha
separately.

Browser tests in a consuming app can point `hcaptcha.script.url` at the package's own stub,
`resources/js/fake-api.js`, served by a route you register yourself; it implements `render`,
`execute`, `reset` and `getResponse` without touching the network and exposes `window.__fakeHCaptcha`
for assertions. This is exactly what the package's own Playwright suite (`tests/Browser`) uses.
