<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests\Fixtures;

use Core45\HCaptcha\Rules\HCaptcha;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A form guarded by the rule object, rendered with the real widget view so
 * browser tests can exercise the whole Livewire round trip. `size` selects
 * the widget size (`normal` or `invisible`); `label` distinguishes instances.
 */
#[Layout('hcaptcha-tests::layouts.app')]
class BrowserGuardedForm extends Component
{
    public string $name = '';

    public string $captcha = '';

    public string $size = 'normal';

    public string $label = 'form';

    public int $submissions = 0;

    public int $renders = 0;

    public function mount(string $size = 'normal', string $label = 'form'): void
    {
        $this->size = $size;
        $this->label = $label;
    }

    public function submit(): void
    {
        $this->validate([
            'name' => ['required'],
            'captcha' => [new HCaptcha],
        ]);

        $this->submissions++;
        $this->name = '';
    }

    public function render(): View
    {
        $this->renders++;

        return view('hcaptcha-tests::guarded-form');
    }
}
