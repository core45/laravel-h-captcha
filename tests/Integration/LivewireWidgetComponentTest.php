<?php

declare(strict_types=1);

use Core45\HCaptcha\Tests\Fixtures\BladeRenderInsideLivewireComponent;
use Core45\HCaptcha\Tests\Fixtures\BrowserGuardedForm;
use Livewire\Livewire;

it('scopes generated ids to the Livewire component that renders them', function (): void {
    $component = Livewire::test(BrowserGuardedForm::class);
    $id = $component->instance()->getId();

    $component->assertSeeHtml('id="hcaptcha-'.$id.'-1"')
        ->assertSeeHtml('id="hcaptcha-'.$id.'-1-response"');

    // A re-render of the same component instance reproduces the same id.
    $component->call('$refresh')->assertSeeHtml('id="hcaptcha-'.$id.'-1"');
});

/*
 * The @script fallback exists only to survive a widget rendered through a
 * Livewire component's own view. Guarded on the wrong condition, it either
 * never fires there (leaving the modal-reopen scenario broken) or fires
 * outside Livewire and fatals with "Using $this when not in object context".
 */
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
