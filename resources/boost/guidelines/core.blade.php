{{-- Laravel h-captcha Guidelines for AI Code Assistants --}}
{{-- Source: https://github.com/core45/laravel-h-captcha --}}
{{-- License: MIT | (c) core45 --}}

## hCaptcha

- `core45/laravel-h-captcha` provides hCaptcha for Laravel using the `Http` client (no Guzzle constraint), with a single memoizing verifier so a single-use token is never spent twice by the rule, the middleware, and the Filament field checking it independently. Memoization is keyed on `(token, scope)`, not just the token, so it never lets a captcha solved for one field silently pass for another.
- Key features: the `<x-hcaptcha />` Blade component, which always renders in hCaptcha's explicit mode (there is no auto mode), the `Core45\HCaptcha\Rules\HCaptcha` validation rule (and `hcaptcha`/`captcha` string rules), the `hcaptcha` route middleware alias, a `Core45\HCaptcha\Filament\Forms\Components\HCaptcha` Filament field, and an optional database audit trail with a `hcaptcha:prune` command.
- Never pair `new \Core45\HCaptcha\Rules\HCaptcha` with a `required` rule — the rule is implicit and already reports the correct "please complete the captcha" message; adding `required` lets Laravel's generic message win instead.
- Verify tokens via the `HCaptcha` facade (`Core45\HCaptcha\Facades\HCaptcha::verify($token, $ip)`), never by calling hCaptcha's `siteverify` endpoint directly.
- `hostnames` defaults to the host of `APP_URL` and should stay enabled — a public sitekey embedded on an attacker's page can produce a genuine `success: true` token from an unrelated hostname, and the hostname check is the only thing that catches it. Also restrict the sitekey's hostnames in the hCaptcha dashboard.
- The `hcaptcha` middleware verifies every request it sees (no GET/HEAD/OPTIONS allowlist) — put it on the state-changing route only, never on a group that also serves the form's GET.
- The package does not rate limit. Pair the `hcaptcha` middleware with Laravel's `throttle` middleware on guarded routes.
- For a multi-site install, name each sitekey/secret pair under `hcaptcha.profiles` and select it with the `profile` prop, `new Rules\HCaptcha(profile: '...')`, `hcaptcha:field,sitekey,profile`, or `->profile()` on the Filament field. An unknown profile throws rather than falling back to the global pair, and the profile is part of the verifier's memo key. Never let the request choose its own profile.
- Test captcha-guarded forms with `Core45\HCaptcha\Facades\HCaptcha::fake()` and its `assertVerifiedFor()` / `assertVerifiedTimes()` assertions; fall back to `Http::fake()` only when the assertion is the memoization contract itself.
- Query the audit trail on the `accepted` column, not `success` — `success` is hCaptcha's raw verdict, `accepted` is the package's verdict after the local hostname and score checks.
- Run `php artisan hcaptcha:doctor` to diagnose credentials, profile half-overrides, hostname policy, and the audit table; it makes no network calls and exits non-zero on a problem.
- Always activate the `hcaptcha-development` skill when working with hCaptcha widgets, validation, middleware, Livewire captcha resets, credential profiles, the Filament hCaptcha field, or the verification audit trail.
