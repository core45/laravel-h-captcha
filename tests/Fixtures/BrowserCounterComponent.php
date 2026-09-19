<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests\Fixtures;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('hcaptcha-tests::layouts.app')]
class BrowserCounterComponent extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): View
    {
        return view('hcaptcha-tests::counter');
    }
}
