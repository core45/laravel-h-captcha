# Content Security Policy

How to feed hCaptcha's required directives, and nonces, into your CSP.

← back to the [README](../README.md)

hCaptcha needs a few directives allowed. `HCaptchaManager::cspDirectives()` (also `HCaptcha::cspDirectives()` on the facade) returns them, so you can feed them into whatever builds your policy header rather than retyping the origins:

```php
HCaptchaManager::cspDirectives();
// [
//     'script-src'  => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
//     'frame-src'   => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
//     'style-src'   => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
//     'connect-src' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
// ]
```

The wildcard subdomain is deliberate: hCaptcha rotates its asset subdomain by region and over time, so pinning a specific one will eventually break.

If your `script-src` also uses a nonce (no `'unsafe-inline'`), register a resolver so the widget's two `<script>` tags carry it:

```php
use Core45\HCaptcha\HCaptchaManager;

HCaptchaManager::nonceUsing(fn (): ?string => request()->attributes->get('csp-nonce'));
```

With no resolver registered, the package falls back to Laravel's own Vite nonce (`Vite::useCspNonce()`) if one was set for the request, and otherwise emits no `nonce` attribute at all.

**Limitation for widgets rendered inside a Livewire update.** A widget whose first appearance on the page is a plain Livewire component update — a modal opened after mount, for instance — is picked up by a `@script` fallback, because a `<script>` tag delivered through Livewire's DOM patch never executes. Livewire evaluates the content of `@script` through a function constructor, and no nonce can authorise that. Under a strict policy without `'unsafe-eval'`, that fallback cannot run, and the widget will not render in that case. To support that case your directive needs to read:

```
script-src 'self' 'nonce-<your-nonce>' 'unsafe-eval'
```

Adding `'unsafe-eval'` is a real, material weakening of the policy: it re-enables `eval()`, `Function()` and similar dynamic evaluation for the *entire page*, not narrowly for this package's fallback. Decide with that cost in view, not as a box to tick. This requirement comes from Livewire itself, not from hCaptcha — this package's own scripts never need `'unsafe-eval'`, so a page that never renders the widget for the first time inside a Livewire update (full loads and `wire:navigate` transitions only) does not need it either. Note this is scoped to this package: a Livewire and Alpine page in general commonly needs `'unsafe-eval'` regardless, because Alpine's own expression evaluation also requires it unless you build Alpine's CSP-compatible variant.
