# Security Policy

## Reporting a vulnerability

Please **do not** open a public GitHub issue for a suspected security vulnerability.

No security contact email is documented anywhere in this repository, so report privately through
GitHub's private security advisories: open the
[Security tab](https://github.com/core45/laravel-h-captcha/security) on this repository and use
**"Report a vulnerability"**. That creates a private advisory visible only to you and the
maintainers, where you can describe the issue, share a proof of concept, and coordinate a fix and
disclosure timeline before anything is made public.

Please include, where relevant:

- The package version (and Laravel/Livewire/Filament versions) affected.
- Your relevant `config/hcaptcha.php` values, with the `secret` and `sitekey` redacted.
- Steps to reproduce, or a minimal test case.
- The `VerificationResult::errorCodes()` / `rejectedBy` values observed, if applicable.

## Scope

**In scope:**

- The verifier (`Core45\HCaptcha\Support\HttpVerifier`) and the `Verifier` contract.
- The validation rule (`Core45\HCaptcha\Rules\HCaptcha`).
- The route middleware (`Core45\HCaptcha\Http\Middleware\VerifyHCaptcha`).
- The widget bootstrap (`resources/js/bootstrap.js`, the Blade component, and the Filament field),
  including how it renders sitekeys, generates widget ids, and wires up tokens.
- The audit trail (`VerificationLogger`, the `hcaptcha_verifications` migration, and the
  `HCaptchaVerification` model), including what it stores and how it's pruned.

**Out of scope:**

- hCaptcha's own service, API, and dashboard. Report those directly to hCaptcha.
- An application's own Content Security Policy, rate limiting, or throttling configuration. The
  package documents nonce support and hostname/score policy, but the application is responsible
  for configuring and enabling them.
- Vulnerabilities that require an already-compromised secret key, an already-solved captcha
  token, or physical/administrative access to the consuming application.

## Response time

As a goal, not a guarantee: an initial acknowledgement of a private advisory within 5 business
days, and a triage/severity assessment within 14 days. This is a best-effort target from a small
maintainer team, not a contractual SLA.

## Supported versions

See [docs/support-policy.md](docs/support-policy.md) for which versions currently receive fixes.

| Version | Status |
| --- | --- |
| 2.x | Actively developed — receives features, fixes, and security patches |
| 1.0.x | Supported for security fixes |

## Data handling

This package never logs or stores a raw hCaptcha token. Only a SHA-256 hash of the token
(`hash('sha256', $token)`) is written to the optional audit trail, which is enough to correlate a
replay attempt without being able to replay the token itself. The package never emits a secret
key, sitekey misconfiguration details beyond an error code, or any other credential to logs,
events, or the audit table.
