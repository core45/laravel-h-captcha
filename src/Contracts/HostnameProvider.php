<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Contracts;

/**
 * Supplies the hostnames a solved token is allowed to have been solved on.
 *
 * The default implementation reads `hcaptcha.hostnames`, which is fine for a
 * single-domain site. An application whose domains live in a database -- a
 * multi-tenant platform where a new site appears without a deploy -- binds its
 * own implementation instead, so adding a domain takes effect immediately and
 * no environment variable has to be edited.
 *
 * Implementations are resolved once per verification, never cached across
 * requests. A queue worker that resolved this at boot would hold a stale
 * allowlist until it restarted.
 *
 * Returning an empty array does NOT disable the hostname check. The verifier
 * falls back to `hcaptcha.hostnames`, and if that is also empty the request is
 * rejected unless `hcaptcha.hostnames_required` has been turned off. An empty
 * return means "I have nothing to add", never "let everything through" -- the
 * hostname check is the only thing standing between a public sitekey and a
 * token solved on somebody else's page.
 */
interface HostnameProvider
{
    /**
     * Hostnames permitted for this request. Case and surrounding whitespace do
     * not matter; the verifier normalizes what it receives.
     *
     * @return list<string>
     */
    public function hostnames(): array;
}
