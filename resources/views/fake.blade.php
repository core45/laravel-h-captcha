{{-- The widget HCaptcha::fake() renders in place of the real one.

     No SDK, no iframe, no network: a test that boots the fake verifier can
     submit a protected form, and a browser test can click the button to
     publish the token the way a solved challenge would. The hidden input is
     filled from the start, so a plain form post passes without any JavaScript
     at all; the button exists for Livewire and Filament, where the server
     state is what matters and an input event is what updates it.

     This view is only ever reached when a FakeVerifier is bound, which only
     test code can do. --}}
<div class="hcaptcha hcaptcha-fake" data-hcaptcha-fake id="{{ $widgetId }}">
    <button
        type="button"
        data-fake-hcaptcha-checkbox
        aria-label="{{ __('hcaptcha::hcaptcha.label') }}"
        onclick="(function (button) {
            var input = document.getElementById(button.closest('[data-hcaptcha-fake]').id + '-response');
            input.value = @js($fakeToken);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
            button.setAttribute('data-solved', 'true');
        })(this)"
    >{{ __('hcaptcha::hcaptcha.label') }}</button>
</div>

<input
    type="hidden"
    id="{{ $widgetId }}-response"
    @if ($fieldName !== null) name="{{ $fieldName }}" @endif
    data-hcaptcha-field="{{ $stateField }}"
    value="{{ $fakeToken }}"
    @if ($wireModel !== null) wire:model="{{ $wireModel }}" @endif
>
