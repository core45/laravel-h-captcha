@if (! $available)
    {{-- No site key configured. Render nothing rather than throwing: a missing
         key is a deployment problem, not a reason to take the page down. The
         verifier still fails closed, so the form cannot be submitted past it. --}}
    @if (config('app.debug'))
        <div class="hcaptcha-misconfigured" role="alert">
            {{ __('hcaptcha::hcaptcha.label') }}: HCAPTCHA_SITEKEY is not set.
        </div>
    @endif
@else
    @if ($shouldRenderScript())
        @once
            @include('hcaptcha::script', [
                'scriptUrl' => $scriptUrl(),
                'bootstrap' => $bootstrapScript(),
                'namespaceName' => $namespaceName(),
            ])
        @endonce
    @endif

    {{-- wire:ignore keeps Livewire's morph out of the iframe hCaptcha owns.
         Without it, a re-render replaces the iframe and the challenge dies
         mid-interaction. The widget is re-rendered by the bootstrap script
         instead, which knows how to do it. --}}
    <div class="hcaptcha" wire:ignore>
        <div
            id="{{ $widgetId }}"
            data-hcaptcha
            data-hcaptcha-explicit
            data-message-error="{{ __('hcaptcha::hcaptcha.widget_error') }}"
            data-message-pending="{{ __('hcaptcha::hcaptcha.widget_pending') }}"
            {{ $attributeString() }}
        ></div>
    </div>

    {{-- The token, in a field we control, populated by the bootstrap script's
         callback. Deliberately outside the wire:ignore container so Livewire
         still tracks it. data-hcaptcha-field names the attribute the server
         validates, which is how a reset finds the widget it belongs to. --}}
    <input
        type="hidden"
        id="{{ $widgetId }}-response"
        name="{{ $fieldName() }}"
        data-hcaptcha-field="{{ $model ?? $fieldName() }}"
        @if ($model) wire:model="{{ $model }}" @endif
    >

    {{-- Live region the bootstrap script writes pending/error feedback into. --}}
    <p id="{{ $widgetId }}-status" class="hcaptcha-status" role="status" aria-live="polite" hidden></p>

    {{-- `$errors` is shared by the ShareErrorsFromSession middleware, so it is
         absent when this view is rendered outside the web group. Guard rather
         than assume, or the widget takes the page down instead of the captcha
         simply having no error to show. --}}
    @if (isset($errors) && $errors->has($fieldName()))
        <p class="hcaptcha-error" role="alert">{{ $errors->first($fieldName()) }}</p>
    @endif
@endif
