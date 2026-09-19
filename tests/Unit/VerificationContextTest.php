<?php

declare(strict_types=1);

use Core45\HCaptcha\Support\VerificationContext;

it('scopes by field alone when no action is known', function (): void {
    expect(VerificationContext::forField('h-captcha-response')->scope())
        ->toBe('|h-captcha-response');
});

it('scopes by action and field when both are known', function (): void {
    $context = new VerificationContext(
        field: 'data.captcha',
        action: 'App\\Livewire\\Contact#abc123',
    );

    expect($context->scope())->toBe('App\\Livewire\\Contact#abc123|data.captcha');
});

it('has no scope when neither field nor action is known', function (): void {
    expect((new VerificationContext)->scope())->toBeNull();
});

it('treats an empty field as absent', function (): void {
    expect((new VerificationContext(field: ''))->scope())->toBeNull();
});

it('adopts a bare string as the field, for callers written against 1.x', function (): void {
    expect(VerificationContext::from('newsletter_form')->scope())->toBe('|newsletter_form');
});

it('passes an existing context through unchanged', function (): void {
    $context = new VerificationContext(field: 'a', action: 'b', sitekey: 'c');

    expect(VerificationContext::from($context))->toBe($context);
});

it('builds an empty context from null', function (): void {
    expect(VerificationContext::from(null)->scope())->toBeNull();
});
