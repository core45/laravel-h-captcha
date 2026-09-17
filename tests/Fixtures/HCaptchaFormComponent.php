<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests\Fixtures;

use Core45\HCaptcha\Filament\Forms\Components\HCaptcha;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * Minimal Livewire component hosting the field under test, standing in for a
 * real Filament page/resource form.
 */
class HCaptchaFormComponent extends Component implements HasForms
{
    use InteractsWithForms;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                HCaptcha::make('h-captcha-response'),
            ])
            ->statePath('data');
    }

    public function save(): array
    {
        return $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
