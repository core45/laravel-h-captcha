<?php

declare(strict_types=1);

use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Tests\Fixtures\BladeRenderInsideLivewireComponent;
use Core45\HCaptcha\Tests\Fixtures\BrowserGuardedForm;
use Core45\HCaptcha\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $data
 */
function renderWidget(string $template = '<x-hcaptcha />', array $data = []): string
{
    return (string) Blade::render($template, $data);
}

it('renders the widget container and the tracked response input', function (): void {
    $html = renderWidget();

    expect($html)
        ->toContain('data-hcaptcha')
        ->toContain('data-sitekey="'.TestCase::TEST_SITEKEY.'"')
        ->toContain('wire:ignore')
        ->toContain('name="h-captcha-response"');
});

/*
 * Auto mode (hCaptcha's own `.h-captcha` class scan) was removed: it injects
 * its own response field, loses the token on any DOM patch, and bypasses the
 * hidden input this package tracks. Every widget must therefore be marked for
 * explicit render, and must not carry the auto-mode class.
 */
it('always marks the widget for explicit render and never uses auto mode', function (): void {
    $html = renderWidget();

    expect($html)
        ->toContain('data-hcaptcha-explicit')
        ->not->toContain('class="h-captcha"');
});

it('asks hCaptcha for explicit render in the script URL', function (): void {
    $html = renderWidget();

    expect($html)
        ->toContain('render=explicit')
        ->toContain('onload='.app(HCaptchaManager::class)->callbackName());
});

it('pairs the response input id with the container id', function (): void {
    $html = renderWidget('<x-hcaptcha id="fixed-widget" />');

    expect($html)
        ->toContain('id="fixed-widget"')
        ->toContain('id="fixed-widget-response"');
});

it('emits the bootstrap script only once for several widgets', function (): void {
    $html = renderWidget('<x-hcaptcha /><x-hcaptcha /><x-hcaptcha />');

    // Counted via data-sitekey, which appears exactly once per widget and
    // nowhere in the bootstrap script.
    expect(substr_count($html, 'js.hcaptcha.com'))->toBe(1)
        ->and(substr_count($html, 'data-sitekey='))->toBe(3);
});

it('omits the script when asked to', function (): void {
    $html = renderWidget('<x-hcaptcha :script="false" />');

    expect($html)
        ->not->toContain('js.hcaptcha.com')
        ->toContain('data-hcaptcha-explicit');
});

it('passes theme and size through as data attributes', function (): void {
    $html = renderWidget('<x-hcaptcha theme="dark" size="compact" />');

    expect($html)
        ->toContain('data-theme="dark"')
        ->toContain('data-size="compact"');
});

it('renders an invisible widget with the size the script keys its submit binding on', function (): void {
    expect(renderWidget('<x-hcaptcha size="invisible" />'))->toContain('data-size="invisible"');
});

it('lets an explicit theme override the configured default', function (): void {
    config()->set('hcaptcha.attributes', ['theme' => 'light']);

    expect(renderWidget('<x-hcaptcha theme="dark" />'))
        ->toContain('data-theme="dark"')
        ->not->toContain('data-theme="light"');
});

/*
 * Regression: the constructor used to build its overrides as
 * `[...$options, 'theme' => $theme]`, which overwrote an options-supplied theme
 * with null whenever the prop was unset, so the value was silently discarded
 * and the configured default won instead.
 */
it('honours a theme supplied through options when the prop is unset', function (): void {
    config()->set('hcaptcha.attributes', ['theme' => 'light']);

    $html = renderWidget('<x-hcaptcha :options="$options" />', [
        'options' => ['theme' => 'dark'],
    ]);

    expect($html)
        ->toContain('data-theme="dark"')
        ->not->toContain('data-theme="light"');
});

it('lets the theme prop win over the same key in options', function (): void {
    $html = renderWidget('<x-hcaptcha theme="dark" :options="$options" />', [
        'options' => ['theme' => 'light'],
    ]);

    expect($html)->toContain('data-theme="dark"');
});

it('passes arbitrary widget options through with a data prefix', function (): void {
    $html = renderWidget('<x-hcaptcha :options="[\'tabindex\' => 3]" />');

    expect($html)->toContain('data-tabindex="3"');
});

/*
 * The package this one replaces interpolated attribute values raw, which made
 * every widget attribute an injection point.
 */
it('escapes attribute values instead of letting them break out', function (): void {
    $html = renderWidget('<x-hcaptcha :options="$options" />', [
        'options' => ['theme' => '" onmouseover="alert(1)'],
    ]);

    expect($html)
        ->not->toContain('onmouseover="alert(1)')
        ->toContain('&quot; onmouseover=&quot;alert(1)');
});

it('resolves the widget language from the application locale at render time', function (): void {
    app()->setLocale('pl');

    expect(renderWidget())->toContain('hl=pl');
});

it('prefers an explicit locale over the application locale', function (): void {
    app()->setLocale('pl');

    expect(renderWidget('<x-hcaptcha locale="de" />'))->toContain('hl=de');
});

it('binds the response input to a Livewire property when a model is given', function (): void {
    $html = renderWidget('<x-hcaptcha model="captchaToken" />');

    expect($html)->toContain('wire:model="captchaToken"');
});

it('renders nothing usable when no site key is configured', function (): void {
    config()->set('hcaptcha.sitekey', null);
    config()->set('app.debug', false);

    $html = renderWidget();

    expect($html)
        ->not->toContain('data-hcaptcha')
        ->not->toContain('js.hcaptcha.com');
});

it('warns the developer about a missing site key while debugging', function (): void {
    config()->set('hcaptcha.sitekey', null);
    config()->set('app.debug', true);

    expect(renderWidget())->toContain('HCAPTCHA_SITEKEY is not set');
});

it('logs a misconfiguration warning once per process, not once per degraded widget', function (): void {
    config()->set('hcaptcha.sitekey', null);
    config()->set('app.debug', false);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'no usable HCAPTCHA_SITEKEY'));

    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    renderWidget();
    renderWidget();
});

it('does not log the misconfiguration warning while the debug notice already shows it', function (): void {
    config()->set('hcaptcha.sitekey', null);
    config()->set('app.debug', true);

    Log::shouldReceive('warning')->never();

    renderWidget();
});

it('does not log anything when configured() is queried on its own', function (): void {
    config()->set('hcaptcha.sitekey', null);
    config()->set('app.debug', false);

    Log::shouldReceive('warning')->never();

    expect(app(HCaptchaManager::class)->configured())->toBeFalse();
});

it('renders with an explicit sitekey when no global sitekey is configured', function (): void {
    config()->set('hcaptcha.sitekey', null);

    $html = renderWidget('<x-hcaptcha sitekey="20000000-ffff-ffff-ffff-000000000002" />');

    expect($html)->toContain('data-sitekey="20000000-ffff-ffff-ffff-000000000002"');
});

it('degrades instead of throwing when the explicit sitekey is a placeholder', function (): void {
    config()->set('app.debug', true);

    $html = renderWidget('<x-hcaptcha sitekey="default_sitekey" />');

    expect($html)->toContain('hcaptcha-misconfigured')
        ->not->toContain('data-sitekey=');
});

it('gives each widget on a plain page a deterministic id in render order', function (): void {
    $html = renderWidget('<x-hcaptcha /><x-hcaptcha />');

    expect($html)
        ->toContain('id="hcaptcha-page-1"')
        ->toContain('id="hcaptcha-page-1-response"')
        ->toContain('id="hcaptcha-page-2"')
        ->not->toContain('id="hcaptcha-page-3"');
});

it('names the validated field on the response input', function (): void {
    expect(renderWidget())->toContain('data-hcaptcha-field="h-captcha-response"')
        ->and(renderWidget('<x-hcaptcha model="captchaToken" />'))->toContain('data-hcaptcha-field="captchaToken"');
});

it('no longer emits the dead data-hcaptcha-model attribute', function (): void {
    expect(renderWidget('<x-hcaptcha model="captchaToken" />'))->not->toContain('data-hcaptcha-model');
});

it('scopes generated ids to the Livewire component that renders them', function (): void {
    $component = Livewire::test(BrowserGuardedForm::class);
    $id = $component->instance()->getId();

    $component->assertSeeHtml('id="hcaptcha-'.$id.'-1"')
        ->assertSeeHtml('id="hcaptcha-'.$id.'-1-response"');

    // A re-render of the same component instance reproduces the same id.
    $component->call('$refresh')->assertSeeHtml('id="hcaptcha-'.$id.'-1"');
});

it('sanitises an explicit id into a safe DOM id', function (): void {
    expect(app(HCaptchaManager::class)->widgetId('contact form/captcha'))->toBe('contact-form-captcha');
});

it('emits the bootstrap with the namespace and callback substituted', function (): void {
    $html = renderWidget();

    expect($html)
        ->toContain('window.core45HCaptcha = window.core45HCaptcha ||')
        ->toContain('window.core45HCaptchaOnLoad = function')
        ->toContain('new MutationObserver(')
        ->not->toContain('__NAMESPACE__')
        ->not->toContain('__CALLBACK__');
});

it('marks both script tags to run once across wire:navigate', function (): void {
    expect(substr_count(renderWidget(), 'data-navigate-once'))->toBe(2);
});

it('renders a status element and the translated messages for the script', function (): void {
    $html = renderWidget();

    expect($html)
        ->toContain('id="hcaptcha-page-1-status"')
        ->toContain('role="status"')
        ->toContain('data-message-error="'.e(__('hcaptcha::hcaptcha.widget_error')).'"')
        ->toContain('data-message-pending="'.e(__('hcaptcha::hcaptcha.widget_pending')).'"');
});

it('exposes the reset event name the rule dispatches', function (): void {
    expect(HCaptchaManager::RESET_EVENT)->toBe(app(HCaptchaManager::class)->namespaceName().':reset');
});

/*
 * The @script fallback exists only to survive a widget rendered through a
 * Livewire component's own view. Guarded on the wrong condition, it either
 * never fires there (leaving the modal-reopen scenario broken) or fires
 * outside Livewire and fatals with "Using $this when not in object context".
 */
it('omits the @script fallback when rendered outside a Livewire component', function (): void {
    expect(renderWidget())->not->toContain('sourceInline');
});

it('includes the @script fallback when rendered inside a Livewire component', function (): void {
    $html = Livewire::test(BrowserGuardedForm::class)->html();

    expect($html)->toContain('sourceInline');
});

/*
 * Regression: LivewireContext::component() reads Livewire's mount/update
 * stack, which is set for the whole request once any component is mounted.
 * $this inside a compiled Blade view is bound by a separate, narrower stack
 * that Livewire's own render pipeline pushes onto -- only while it is
 * actually rendering that component's view. Guarding on the mount/update
 * stack let a component whose mount() or action calls Blade::render()
 * directly pass the guard with $this unbound, which fatals.
 */
it('renders the widget from inside a Livewire mount() or action without fataling', function (): void {
    $component = Livewire::test(BladeRenderInsideLivewireComponent::class);

    expect($component->get('mountHtml'))->toContain('data-hcaptcha');

    $component->call('renderFromAction');

    expect($component->get('actionHtml'))->toContain('data-hcaptcha');
});
