{{--
    Bootstrap for hCaptcha's explicit render mode, from resources/js/bootstrap.js.

    Emitted once per request by the widget views. data-navigate-once keeps
    wire:navigate from re-running either tag when the next page carries the
    same ones, so the SDK loads once per visit. data-hcaptcha-bootstrap and
    data-hcaptcha-sdk mark each tag for the @script fallback below to find.
    $nonceAttribute carries the application's CSP nonce, if one is registered
    via HCaptchaManager::nonceUsing() or set through Vite::useCspNonce(); the
    fallback below reads it back off these tags for its clones.
--}}
<script{!! $nonceAttribute !!} data-navigate-once data-hcaptcha-bootstrap>{!! $bootstrap !!}</script>

<script src="{{ $scriptUrl }}" async defer data-navigate-once data-hcaptcha-sdk{!! $nonceAttribute !!}></script>

@if (isset($this))
    {{--
        Safety net for a widget whose first appearance is a plain Livewire
        component update rather than a full page load or a wire:navigate
        transition -- a modal opened after mount, for instance. A <script>
        tag delivered through Livewire's morph never runs: the DOM never
        executes a script parsed out of injected HTML. @script instead
        evaluates its content through Livewire's own effect pipeline, which
        runs regardless of how the markup arrived. It checks the namespace
        object first, so it never double-loads the SDK when the literal tags
        above already ran normally.

        `isset($this)` is the same condition Livewire's own @endassets uses
        to decide whether it is safe to store an effect against the current
        component: $this is bound only by Livewire's own compiled-view render
        stack, not by Livewire having a component mounted somewhere in the
        request, so this must match that stack precisely or @script fatals
        with "Using $this when not in object context".

        Rather than carry a second copy of the (10KB+) bootstrap source
        through wire:effects, this clones the literal tags above, which are
        already sitting inert in the morphed DOM. Cloning also carries over
        each tag's CSP nonce, which a script built from scratch would not
        inherit.

        The clone is deferred to the next animation frame: Livewire runs its
        effects (this script included) before it applies the morph that adds
        the literal tags to the DOM, so looking for them synchronously here
        always misses. One rAF is enough to land after the morph, the same
        margin bootstrap.js's own MutationObserver already relies on.
    --}}
    @script
    <script>
        if (! window.{{ $namespaceName }}) {
            requestAnimationFrame(function () {
                if (window.{{ $namespaceName }}) {
                    return;
                }

                var sourceInline = document.querySelector('script[data-hcaptcha-bootstrap]');

                if (sourceInline) {
                    var inlineTag = document.createElement('script');
                    inlineTag.textContent = sourceInline.textContent;

                    var inlineNonce = sourceInline.nonce || sourceInline.getAttribute('nonce');

                    if (inlineNonce) {
                        inlineTag.setAttribute('nonce', inlineNonce);
                        inlineTag.nonce = inlineNonce;
                    }

                    document.head.appendChild(inlineTag);
                }

                var sourceSdk = document.querySelector('script[data-hcaptcha-sdk]');

                if (sourceSdk) {
                    var sdkTag = document.createElement('script');
                    sdkTag.src = sourceSdk.src;
                    sdkTag.async = true;
                    sdkTag.defer = true;

                    var sdkNonce = sourceSdk.nonce || sourceSdk.getAttribute('nonce');

                    if (sdkNonce) {
                        sdkTag.setAttribute('nonce', sdkNonce);
                        sdkTag.nonce = sdkNonce;
                    }

                    document.head.appendChild(sdkTag);
                }
            });
        }
    </script>
    @endscript
@endif
