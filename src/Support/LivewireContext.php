<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Support;

use Livewire\Component;
use Livewire\LivewireManager;

/**
 * The Livewire component whose request is being handled, if any.
 *
 * Livewire keeps a stack of the components it is mounting or updating;
 * outside a Livewire request the stack is empty. Everything here degrades to
 * null when Livewire is not installed, so the package never requires it.
 */
final class LivewireContext
{
    public static function component(): ?Component
    {
        if (! class_exists(LivewireManager::class) || ! app()->bound(LivewireManager::class)) {
            return null;
        }

        $component = app(LivewireManager::class)->current();

        return $component instanceof Component ? $component : null;
    }

    public static function componentId(): ?string
    {
        return self::component()?->getId();
    }

    /**
     * A label identifying the component instance, used as the verification
     * action so two components validating the same property in one batched
     * request cannot share a verdict.
     */
    public static function action(): ?string
    {
        $component = self::component();

        return $component === null ? null : $component::class.'#'.$component->getId();
    }
}
