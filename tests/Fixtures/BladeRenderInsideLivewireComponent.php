<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests\Fixtures;

use Illuminate\Support\Facades\Blade;
use Livewire\Component;

/**
 * Regression fixture: a Livewire component whose mount() and an action both
 * render the widget through Blade::render() rather than through its own
 * Blade view -- e.g. building a notification body or a mail preview from
 * inside a component. $this is bound by Livewire's own render-view stack,
 * not by a component merely being mounted, so this used to fatal with
 * "Using $this when not in object context" when the @script fallback in
 * hcaptcha::script guarded on the wrong stack.
 */
class BladeRenderInsideLivewireComponent extends Component
{
    public string $mountHtml = '';

    public string $actionHtml = '';

    public function mount(): void
    {
        $this->mountHtml = (string) Blade::render('<x-hcaptcha />');
    }

    public function renderFromAction(): void
    {
        $this->actionHtml = (string) Blade::render('<x-hcaptcha />');
    }

    public function render(): string
    {
        return '<div>rendered</div>';
    }
}
