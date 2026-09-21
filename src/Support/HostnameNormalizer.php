<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

/**
 * Turns whatever an allowlist source hands over into a comparable list.
 *
 * Shared on purpose. The hostname check is an exact `in_array` against the
 * value hCaptcha reports, so a source that normalizes differently from the
 * verifier produces a silent mismatch: a domain row saved as " Example.test "
 * would never match `example.test`, and the only symptom is a rejected
 * submission that looks like a captcha failure.
 */
final class HostnameNormalizer
{
    /**
     * @return list<string> Lowercased, trimmed, non-empty hostnames.
     */
    public static function normalize(mixed $value): array
    {
        // A string may hold a comma-separated list, because it usually arrives
        // from HCAPTCHA_HOSTNAMES and an env var cannot carry an array. Without
        // splitting, "a.test,b.test" became one literal that no real hostname
        // could ever match.
        $hostnames = match (true) {
            is_array($value) => $value,
            is_string($value) => explode(',', $value),
            default => [$value],
        };

        return array_values(array_filter(
            array_map(
                static fn (string|int|float|bool $hostname): string => mb_strtolower(trim((string) $hostname)),
                array_filter($hostnames, is_scalar(...)),
            ),
            // Blank entries are scalars, so without this an empty string would
            // survive as a "restriction" that no real hostname can ever match.
            static fn (string $hostname): bool => $hostname !== '',
        ));
    }
}
