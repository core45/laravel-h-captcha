<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

use Core45\HCaptcha\Contracts\HostnameProvider;
use Illuminate\Contracts\Config\Repository;

/**
 * The default provider: reads `hcaptcha.hostnames`, which normally arrives from
 * HCAPTCHA_HOSTNAMES or falls back to the host of APP_URL.
 *
 * This is the whole allowlist for a single-domain site. Applications that hold
 * their domains elsewhere bind their own {@see HostnameProvider} instead.
 */
final class ConfigHostnameProvider implements HostnameProvider
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return list<string>
     */
    public function hostnames(): array
    {
        return HostnameNormalizer::normalize($this->config->get('hcaptcha.hostnames'));
    }
}
