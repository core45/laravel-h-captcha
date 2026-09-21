<?php

declare(strict_types=1);

use Core45\HCaptcha\Support\HostnameNormalizer;

/*
 * The hostname check is an exact in_array() against the value hCaptcha reports,
 * so every allowlist source has to normalize identically. A source that skips
 * the trim or the lowercase produces a silent mismatch whose only symptom is a
 * rejected submission that looks like a failed captcha.
 */

it('splits a comma-separated string, because an env var cannot carry an array', function (): void {
    expect(HostnameNormalizer::normalize('a.test,b.test'))->toBe(['a.test', 'b.test']);
});

it('takes an array as it stands', function (): void {
    expect(HostnameNormalizer::normalize(['a.test', 'b.test']))->toBe(['a.test', 'b.test']);
});

it('lowercases and trims, so a hand-typed domain row still matches', function (): void {
    expect(HostnameNormalizer::normalize([' Example.TEST ', "b.test\n"]))->toBe(['example.test', 'b.test']);
});

it('drops blanks rather than keeping a restriction nothing can satisfy', function (): void {
    // An empty string is a scalar, so without the filter it would survive as an
    // allowlist entry that no real hostname can ever equal.
    expect(HostnameNormalizer::normalize(['a.test', '', '   ']))->toBe(['a.test']);
});

it('drops non-scalars instead of stringifying them', function (): void {
    expect(HostnameNormalizer::normalize(['a.test', null, ['nested'], new stdClass]))->toBe(['a.test']);
});

it('returns a list, not a map with holes', function (): void {
    // array_filter preserves keys; a caller doing $hostnames[0] on a filtered
    // array would otherwise read the wrong entry or none at all.
    expect(array_keys(HostnameNormalizer::normalize(['', 'a.test', '', 'b.test'])))->toBe([0, 1]);
});

it('normalizes a bare null to nothing at all', function (): void {
    expect(HostnameNormalizer::normalize(null))->toBe([]);
});
