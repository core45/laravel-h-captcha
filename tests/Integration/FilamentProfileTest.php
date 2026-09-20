<?php

declare(strict_types=1);

use Core45\HCaptcha\Filament\Forms\Components\HCaptcha;
use Core45\HCaptcha\Tests\Fixtures\HCaptchaFormComponent;
use Filament\Schemas\Schema;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * The same host component as HCaptchaFormComponent, with the field bound to a
 * named credential profile instead of the global sitekey/secret pair.
 */
class HCaptchaFormWithProfileComponent extends HCaptchaFormComponent
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                HCaptcha::make('h-captcha-response')->profile('marketing'),
            ])
            ->statePath('data');
    }
}

beforeEach(function (): void {
    config()->set('hcaptcha.profiles', [
        'marketing' => [
            'sitekey' => '20000000-ffff-ffff-ffff-000000000002',
            'secret' => 'marketing-secret',
        ],
    ]);
});

function fakeFilamentProfileVerification(bool $success): void
{
    Http::fake([
        'api.hcaptcha.com/*' => Http::response([
            'success' => $success,
            'hostname' => 'localhost',
            'challenge_ts' => now()->toIso8601String(),
        ]),
    ]);
}

it('renders the profile sitekey on a Filament field bound to a profile', function (): void {
    fakeFilamentProfileVerification(success: true);

    Livewire::test(HCaptchaFormWithProfileComponent::class)
        ->assertSeeHtml('data-sitekey="20000000-ffff-ffff-ffff-000000000002"');
});

it('verifies the field against the profile secret', function (): void {
    fakeFilamentProfileVerification(success: true);

    Livewire::test(HCaptchaFormWithProfileComponent::class)
        ->fillForm(['h-captcha-response' => 'accepted-token'])
        ->call('save')
        ->assertHasNoFormErrors();

    Http::assertSent(fn (Request $request): bool => $request['sitekey'] === '20000000-ffff-ffff-ffff-000000000002'
        && $request['secret'] === 'marketing-secret');
});

it('fails validation when the profile-scoped token is rejected', function (): void {
    fakeFilamentProfileVerification(success: false);

    Livewire::test(HCaptchaFormWithProfileComponent::class)
        ->fillForm(['h-captcha-response' => 'rejected-token'])
        ->call('save')
        ->assertHasFormErrors(['h-captcha-response']);
});
