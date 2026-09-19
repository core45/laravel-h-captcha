@php
    $namespaceName = app(\Core45\HCaptcha\HCaptchaManager::class)->namespaceName();
@endphp
{{--
    Bootstrap for hCaptcha's explicit render mode, from resources/js/bootstrap.js.

    Emitted once per request by the widget views. data-navigate-once keeps
    wire:navigate from re-running either tag when the next page carries the
    same ones, so the SDK loads once per visit.
--}}
<script data-navigate-once>{!! $bootstrap !!}</script>

<script src="{{ $scriptUrl }}" async defer data-navigate-once></script>

@if (\Core45\HCaptcha\Support\LivewireContext::component())
    {{--
        Safety net for a widget whose first appearance is a plain Livewire
        component update rather than a full page load or a wire:navigate
        transition -- a modal opened after mount, for instance. A <script>
        tag delivered through Livewire's morph never runs: the DOM never
        executes a script parsed out of injected HTML. @script instead
        evaluates its content through Livewire's own effect pipeline, which
        runs regardless of how the markup arrived. It checks the namespace
        object first, so it never double-loads the SDK when the literal tags
        above already ran normally. Only usable while an actual Livewire
        component is rendering -- @script needs Livewire's own render cycle
        to attach the script to, and fatals outside of one.
    --}}
    @script
    <script>
        if (! window.{{ $namespaceName }}) {
            var core45HCaptchaInline = document.createElement('script');
            core45HCaptchaInline.textContent = @json((string) $bootstrap);
            document.head.appendChild(core45HCaptchaInline);

            var core45HCaptchaSdk = document.createElement('script');
            core45HCaptchaSdk.src = @json($scriptUrl);
            core45HCaptchaSdk.async = true;
            core45HCaptchaSdk.defer = true;
            document.head.appendChild(core45HCaptchaSdk);
        }
    </script>
    @endscript
@endif
