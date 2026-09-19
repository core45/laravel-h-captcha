<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Tests\Fixtures;

use Core45\HCaptcha\Filament\Forms\Components\HCaptcha;
use Filament\Schemas\Schema;

/**
 * The same host component, with the field rendered for a second sitekey.
 */
class HCaptchaFormWithSitekeyComponent extends HCaptchaFormComponent
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                HCaptcha::make('h-captcha-response')
                    ->sitekey('20000000-ffff-ffff-ffff-000000000002'),
            ])
            ->statePath('data');
    }
}
