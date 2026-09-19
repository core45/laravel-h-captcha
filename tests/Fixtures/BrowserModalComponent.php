<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests\Fixtures;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A Livewire component that mounts and unmounts a widget-carrying block on
 * demand, so browser tests can exercise the observer discovering a widget
 * added after the initial render and pruning it once it is removed again.
 */
#[Layout('hcaptcha-tests::layouts.app')]
class BrowserModalComponent extends Component
{
    public bool $open = false;

    public string $captcha = '';

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function render(): View
    {
        return view('hcaptcha-tests::modal');
    }
}
