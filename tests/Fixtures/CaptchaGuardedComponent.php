<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests\Fixtures;

use Core45\HCaptcha\Rules\HCaptcha;
use Livewire\Component;

/**
 * A plain Livewire component protecting one action with the rule object. Two
 * instances of this component in one request stand in for two unrelated
 * forms that happen to use the same property name.
 */
class CaptchaGuardedComponent extends Component
{
    public string $captcha = '';

    public bool $submitted = false;

    public function submit(): void
    {
        $this->validate(['captcha' => [new HCaptcha]]);

        $this->submitted = true;
    }

    public function submitTwice(): void
    {
        $this->validate(['captcha' => [new HCaptcha]]);
        $this->validate(['captcha' => [new HCaptcha]]);

        $this->submitted = true;
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
