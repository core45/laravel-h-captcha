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
- Always activate the `hcaptcha-development` skill when working with hCaptcha widgets, validation, middleware, Livewire captcha resets, the Filament hCaptcha field, or the verification audit trail.
