<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @if ($isFaking())
        {{-- HCaptcha::fake() is bound: render a widget a test can solve rather
             than hCaptcha's SDK. Checked before the sitekey, because a test
             fakes the verifier precisely so it needs no real credentials. --}}
        @include('hcaptcha::fake', [
            'widgetId' => $getWidgetId(),
            'fieldName' => null,
            'stateField' => $getStatePath(),
            'wireModel' => $getStatePath(),
            'fakeToken' => \Core45\HCaptcha\Testing\FakeVerifier::TOKEN,
        ])
    @elseif (! $isConfigured())
        {{-- No site key. Render nothing rather than throwing; the rule still
             rejects the submission, so nothing gets past the field. --}}
        @php($logMisconfigured())
        @if (config('app.debug'))
            <div class="hcaptcha-misconfigured" role="alert">
                {{ __('hcaptcha::hcaptcha.label') }}: HCAPTCHA_SITEKEY is not set.
            </div>
        @endif
    @else
        @if ($shouldRenderScript())
            @once
                @include('hcaptcha::script', [
                    'scriptUrl' => $getScriptUrl(),
                    'bootstrap' => $getBootstrapScript(),
                    'namespaceName' => $getNamespaceName(),
                    'nonceAttribute' => $getNonceAttribute(),
                ])
            @endonce
        @endif

        {{-- wire:ignore keeps Livewire's morph out of the iframe hCaptcha owns.
             Without it, a re-render replaces the iframe and the challenge dies
             mid-interaction. The widget is re-rendered by the bootstrap script
             instead, which knows how to do it. --}}
        <div class="hcaptcha" wire:ignore>
            <div
                id="{{ $getWidgetId() }}"
                data-hcaptcha
                data-hcaptcha-explicit
                data-message-error="{{ __('hcaptcha::hcaptcha.widget_error') }}"
                data-message-pending="{{ __('hcaptcha::hcaptcha.widget_pending') }}"
                {!! $getWidgetAttributeString() !!}
            ></div>
        </div>

        {{-- The token, in a field we control, populated by the bootstrap script's
             callback. Unlike the standalone widget view this input deliberately has
             no `name`: a Filament field is always inside Livewire, so the state path
             binding is what carries the value, and a `name` would post a duplicate. --}}
        <input
            type="hidden"
            id="{{ $getWidgetId() }}-response"
            data-hcaptcha-field="{{ $getStatePath() }}"
            wire:model="{{ $getStatePath() }}"
        >

        {{-- Live region the bootstrap script writes pending/error feedback into. --}}
        <p id="{{ $getWidgetId() }}-status" class="hcaptcha-status" role="status" aria-live="polite" hidden></p>
    @endif
</x-dynamic-component>
