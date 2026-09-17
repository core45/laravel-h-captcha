{{--
    Bootstrap for hCaptcha's explicit render mode.

    This block must come before api.js: hCaptcha calls window[onload] as soon
    as the script is ready, and the callback has to already exist.

    Everything here is idempotent. Widgets are discovered from the DOM rather
    than from a list baked in at render time, which is what makes a widget
    inserted later -- by Livewire, by Alpine, by a modal -- work without any
    extra wiring.
--}}
<script>
    window.{{ $namespaceName }} = window.{{ $namespaceName }} || (function () {
        const widgets = {};
        let apiReady = false;

        const container = (id) => document.getElementById(id);

        const responseField = (id) => document.getElementById(id + '-response');

        function publish(id, token) {
            const field = responseField(id);

            if (! field) {
                return;
            }

            field.value = token;

            // Livewire and Alpine both bind on input events.
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function render(el) {
            if (! apiReady || ! el || el.dataset.hcaptchaRendered === 'true') {
                return;
            }

            // A DOM patch can hand back a container that still carries the
            // rendered flag while its iframe is gone. Treat an empty container
            // as unrendered.
            const id = el.id;

            const widgetId = window.hcaptcha.render(el, {
                sitekey: el.dataset.sitekey,
                theme: el.dataset.theme || undefined,
                size: el.dataset.size || undefined,
                callback: (token) => publish(id, token),
                'expired-callback': () => publish(id, ''),
                'chalexpired-callback': () => publish(id, ''),
                'error-callback': () => publish(id, ''),
            });

            el.dataset.hcaptchaRendered = 'true';
            widgets[id] = widgetId;
        }

        function renderAll() {
            document
                .querySelectorAll('[data-hcaptcha-explicit]')
                .forEach((el) => {
                    if (el.dataset.hcaptchaRendered === 'true' && el.querySelector('iframe')) {
                        return;
                    }

                    delete el.dataset.hcaptchaRendered;
                    render(el);
                });
        }

        function reset(id) {
            // hCaptcha tokens are single-use. After a submit -- successful or
            // not -- the widget holds a spent token, so it has to be reset or
            // the next attempt fails with token-already-used.
            if (id === undefined) {
                Object.keys(widgets).forEach(reset);

                return;
            }

            if (! (id in widgets)) {
                return;
            }

            window.hcaptcha.reset(widgets[id]);
            publish(id, '');
        }

        function markReady() {
            apiReady = true;
            renderAll();
        }

        document.addEventListener('livewire:init', () => {
            window.Livewire.hook('morphed', () => renderAll());
            window.Livewire.hook('morph.added', () => renderAll());
        });

        document.addEventListener('livewire:navigated', () => renderAll());

        window.addEventListener('{{ $namespaceName }}:reset', (event) => reset(event.detail?.id));

        return { widgets, render, renderAll, reset, markReady, container };
    })();

    window.{{ $callbackName }} = function () {
        window.{{ $namespaceName }}.markReady();
    };
</script>

<script src="{{ $scriptUrl }}" async defer></script>
