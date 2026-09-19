<?php

declare(strict_types=1);

use Core45\HCaptcha\Support\LivewireContext;
use Core45\HCaptcha\Tests\Fixtures\CaptchaGuardedComponent;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

it('reports no component outside a Livewire request', function (): void {
    expect(LivewireContext::component())->toBeNull()
        ->and(LivewireContext::componentId())->toBeNull()
        ->and(LivewireContext::action())->toBeNull();
});

it('reports the executing component while it is on Livewire\'s stack', function (): void {
    // Livewire::test() pops the component off the stack once the call
    // returns, so push the mounted instance the way HandleComponents does.
    $instance = Livewire::test(CaptchaGuardedComponent::class)->instance();

    HandleComponents::$componentStack[] = $instance;

    try {
        expect(LivewireContext::component())->toBe($instance)
            ->and(LivewireContext::componentId())->toBe($instance->getId())
            ->and(LivewireContext::action())->toBe(CaptchaGuardedComponent::class.'#'.$instance->getId());
    } finally {
        array_pop(HandleComponents::$componentStack);
    }
});
