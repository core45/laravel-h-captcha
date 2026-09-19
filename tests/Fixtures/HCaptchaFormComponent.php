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

    public int $saved = 0;

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

    /**
     * @return array<string, mixed>
     */
    public function save(): array
    {
        $state = $this->form->getState();

        $this->saved++;

        return $state;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                {{ $this->form }}
                <button type="button" id="save-{{ $this->getId() }}" wire:click="save">Save</button>
                <p id="saved-{{ $this->getId() }}">Saved {{ $saved }} times</p>
            </div>
            BLADE;
    }
}
