<?php

declare(strict_types=1);

namespace Core45\HCaptcha\View\Components;

use Core45\HCaptcha\HCaptchaManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

/**
 * The widget.
 *
 *     <x-hcaptcha />
 *     <x-hcaptcha theme="dark" size="compact" />
 *     <x-hcaptcha model="captchaToken" />   {{-- Livewire --}}
 *
 * Renders in hCaptcha's *explicit* mode by default. Auto mode scans the DOM
 * once, on page load, which is exactly wrong for Livewire: after a DOM patch
 * the container is new and unrendered, and the visitor is left staring at an
 * empty box. Explicit mode plus the bootstrap script lets the widget be
 * (re)rendered whenever it appears.
 */
class HCaptcha extends Component
{
    public string $widgetId;

    /**
     * @var array<string, string>
     */
    public array $widgetAttributes = [];

    public bool $available;

    public function __construct(
        protected HCaptchaManager $manager,
        public ?string $sitekey = null,
        public ?string $theme = null,
        public ?string $size = null,
        public ?string $locale = null,
        public ?string $id = null,
        public bool $script = true,
        /** Livewire property to write the token into, e.g. `captchaToken`. */
        public ?string $model = null,
        /**
         * Extra `data-*` widget options, passed through verbatim.
         *
         * @var array<string, string|int|float|bool|null>
         */
        public array $options = [],
    ) {
        $this->available = $manager->configured($sitekey);
        $this->widgetId = $manager->widgetId($id);

        if ($this->available) {
            $this->widgetAttributes = $manager->attributes($this->overrides(), $sitekey);
        }
    }

    /**
     * Widget options, with the dedicated `theme` and `size` props winning over
     * the same keys in `options`.
     *
     * Written as a merge rather than `[...$options, 'theme' => $theme]`: that
     * form overwrites an `options['theme']` with null whenever the prop is
     * unset, which silently discarded the caller's value.
     *
     * @return array<string, string|int|float|bool>
     */
    protected function overrides(): array
    {
        $overrides = array_filter(
            $this->options,
            static fn (mixed $value): bool => $value !== null,
        );

        foreach (['theme' => $this->theme, 'size' => $this->size] as $key => $value) {
            if ($value !== null) {
                $overrides[$key] = $value;
            }
        }

        return $overrides;
    }

    public function fieldName(): string
    {
        return $this->manager->fieldName();
    }

    public function attributeString(): HtmlString
    {
        return $this->manager->attributeString($this->widgetAttributes);
    }

    public function scriptUrl(): string
    {
        return $this->manager->scriptUrl($this->locale);
    }

    public function shouldRenderScript(): bool
    {
        return $this->script && $this->manager->scriptEnabled();
    }

    public function bootstrapScript(): HtmlString
    {
        return $this->manager->bootstrapScript();
    }

    public function namespaceName(): string
    {
        return $this->manager->namespaceName();
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'hcaptcha::widget';

        return view($view);
    }
}
