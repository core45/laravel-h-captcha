# Support policy

## Package versions

| Version | Status |
| --- | --- |
| 2.x | Actively developed — receives features, fixes, and security patches |
| 1.0.x | Security and critical bug fixes only |

Only the latest minor/patch release within a supported major version is guaranteed to receive
fixes. Users on an older 2.x release should upgrade to the latest 2.x before
reporting an issue.

## Supported PHP / Laravel / Livewire / Filament versions

Taken verbatim from `composer.json` — these ranges are not widened here:

- **PHP:** `^8.4`
- **Laravel** (`illuminate/contracts`, `illuminate/database`, `illuminate/http`,
  `illuminate/support`, `illuminate/validation`, `illuminate/view`): `^12.0|^13.0`
- **Livewire** (optional, via `require-dev`/`suggest`): `^3.6|^4.1`
- **Filament** (optional, via `require-dev`/`suggest`): `^5.7.6`

Livewire and Filament are not required dependencies — the package works without either installed.
Their version ranges above apply only when a consuming application uses the Livewire-aware
behaviour (automatic widget reset) or the Filament form field. Livewire `^3.6` is supported only
without Filament installed; Filament 5 requires Livewire 4 (`filament/support` pins
`livewire/livewire: ^4.1`), so an install with both must use Livewire 4.

## What "supported" means

Support means CI runs a resolvable combination of the above constraints and that combination
passes. Any PHP/Laravel/Livewire/Filament combination that Composer cannot resolve, or that isn't
exercised in CI, is **untested** — it may work, but there is no verification behind that and no
support commitment attached to it. If you hit an issue on a combination outside CI, please say so
in the report; reproducing it there is part of triage.

See the workflows under [`.github/workflows/`](../.github/workflows/) for the exact combinations
currently run.

## Semantic versioning policy

This package follows [Semantic Versioning](https://semver.org/). The public API — the surface a
breaking change (major version bump) applies to — is:

- The `Core45\HCaptcha\Contracts\Verifier` contract and its `verify()` signature.
- `Core45\HCaptcha\Support\VerificationResult` — its public properties/methods
  (`passed()`, `failed()`, `hasErrorCode()`, `tokenAlreadyUsed()`, `tokenExpired()`,
  `tokenMalformed()`, `tokenMissing()`, `isConfigurationError()`, `messageKey()`, `toArray()`,
  `jsonSerialize()`) and the documented meaning of `success` vs `accepted`.
- `Core45\HCaptcha\Support\VerificationContext` — its constructor arguments and the fields it
  carries (field, protected action, expected sitekey, credential profile).
- The `HCaptcha` facade (`Core45\HCaptcha\Facades\HCaptcha`) and the `HCaptchaManager` methods it
  exposes.
- Published config keys in `config/hcaptcha.php` (names, defaults, and env variable names).
- Public props/methods on the `<x-hcaptcha />` Blade component (`sitekey`, `profile`, `theme`,
  `size`, `locale`, `id`, `script`, `model`, `options`) and on the Filament
  `Core45\HCaptcha\Filament\Forms\Components\HCaptcha` field (`theme()`, `size()`, `locale()`,
  `sitekey()`, `profile()`).
- `Core45\HCaptcha\Events\VerificationCompleted` — its properties and when it is dispatched.
- `Core45\HCaptcha\Facades\HCaptcha::fake()` and the assertion methods on
  `Core45\HCaptcha\Testing\FakeVerifier`.
- The published views (`resources/views/*.blade.php`) and the bootstrap script's public JS API
  (`window.core45HCaptcha.render/renderAll/reset`, the `core45HCaptcha:reset` event) to the extent
  they are documented in the README.

A change to any of the above that isn't backward-compatible ships in a new major version and is
documented in an "Upgrading from X.y" section in the README, as was done for 2.0.0. Internal
implementation details not listed above (private/protected members, non-public classes, internal
JS helpers) may change in a minor or patch release.

## Deprecation approach

When a public API member is superseded, it is kept working, marked `@deprecated` with a pointer to
its replacement, and documented in the CHANGELOG under a "Deprecated" heading for at least one
minor release before removal in the next major version. Legacy aliases introduced in 2.0.0 — for
example `tokenAlreadyUsed()` still matching the old `token-already-used` code name alongside the
real `already-seen-response` — follow the same pattern: kept, not silently dropped.
