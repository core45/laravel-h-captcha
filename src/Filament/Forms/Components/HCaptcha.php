<?php

declare(strict_types=1);

namespace Core45\HCaptcha\Filament\Forms\Components;

use Closure;
use Core45\HCaptcha\HCaptchaManager;
use Core45\HCaptcha\Rules\HCaptcha as HCaptchaRule;
use Filament\Forms\Components\Field;
use Illuminate\Support\HtmlString;

/**
 * Renders the hCaptcha widget as a Filament form field.
 *
 * The token this field collects is single-use and spent by validation, so it
 * is never something a model should hold. `dehydrated(false)` is set
 * deliberately in `setUp()` -- see the comment there for why this does not
 * disable validation.
 */
class HCaptcha extends Field
{
    protected string $view = 'hcaptcha::filament.hcaptcha';

    protected string|Closure|null $theme = null;

    protected string|Closure|null $size = null;

    protected string|Closure|null $locale = null;

    protected string|Closure|null $sitekey = null;

    public static function make(?string $name = null): static
    {
        $name ??= config('hcaptcha.field', 'h-captcha-response');

        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A closure, so the rule sees the sitekey set after make(). The
        // sitekey the widget renders with and the one verification expects
        // must be the same key, or hCaptcha answers sitekey-secret-mismatch.
        $this->rule(fn (): HCaptchaRule => new HCaptchaRule(sitekey: $this->evaluate($this->sitekey)));

        // The rule itself is implicit (see Rules\HCaptcha), so it already
        // fires and reports hcaptcha::hcaptcha.missing on an empty or absent
        // token -- calling required() here would only race it with Laravel's
        // generic "required" message. markAsRequired() still gives the field
        // its asterisk without adding a second, competing validation rule.
        $this->markAsRequired();

        // Documented exception to the "never use dehydrated(false)" rule: an
        // hCaptcha token is single-use and must never be persisted onto the
        // model. dehydrated(false) only removes the field from the
        // dehydrated/saved state -- validation still runs against the raw
        // form state beforehand, so the token is still checked.
        $this->dehydrated(false);

        $this->label(__('hcaptcha::hcaptcha.label'));

        // The widget renders itself via JS and publishes its own token; there
        // is nothing here that should trigger a Livewire round trip as the
        // user types.
        $this->live(false);
    }

    public function theme(string|Closure|null $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function size(string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function locale(string|Closure|null $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function sitekey(string|Closure|null $sitekey): static
    {
        $this->sitekey = $sitekey;

        return $this;
    }

    public function getTheme(): ?string
    {
        return $this->evaluate($this->theme);
    }

    public function getSize(): ?string
    {
        return $this->evaluate($this->size);
    }

    public function getLocale(): ?string
    {
        return $this->manager()->locale($this->evaluate($this->locale));
    }

    public function getSitekey(): string
    {
        return $this->manager()->sitekey($this->evaluate($this->sitekey));
    }

    /**
     * Scoped to the Livewire component by the manager, so two forms with the
     * same state path on one page get distinct ids.
     */
    public function getWidgetId(): string
    {
        return $this->manager()->widgetId(key: $this->getStatePath());
    }

    /**
     * @return array<string, string>
     */
    public function getWidgetAttributes(): array
    {
        return $this->manager()->attributes(
            array_filter(
                [
                    'theme' => $this->getTheme(),
                    'size' => $this->getSize(),
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
            $this->evaluate($this->sitekey),
        );
    }

    public function getWidgetAttributeString(): HtmlString
    {
        return $this->manager()->attributeString($this->getWidgetAttributes());
    }

    public function getScriptUrl(): string
    {
        return $this->manager()->scriptUrl($this->getLocale());
    }

    public function getBootstrapScript(): HtmlString
    {
        return $this->manager()->bootstrapScript();
    }

    public function getNamespaceName(): string
    {
        return $this->manager()->namespaceName();
    }

    public function getNonceAttribute(): HtmlString
    {
        return $this->manager()->nonceAttribute();
    }

    public function shouldRenderScript(): bool
    {
        return $this->manager()->scriptEnabled();
    }

    /**
     * Whether a usable sitekey resolves for this field. When it does not the
     * view renders no widget -- the same policy as the Blade component -- but
     * the field stays in validation, so the form still fails closed.
     */
    public function isConfigured(): bool
    {
        return $this->manager()->configured($this->evaluate($this->sitekey));
    }

    /**
     * Called by the view once it has decided to degrade -- never by
     * `isConfigured()` itself, which stays a pure query so it can be checked
     * without side effects.
     */
    public function logMisconfigured(): void
    {
        $this->manager()->logMisconfigured();
    }

    protected function manager(): HCaptchaManager
    {
        return app(HCaptchaManager::class);
    }
}
