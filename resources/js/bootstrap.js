/*
 * Bootstrap for hCaptcha's explicit render mode.
 *
 * Emitted by HCaptchaManager::bootstrapScript() with __NAMESPACE__ and
 * __CALLBACK__ substituted, before api.js: hCaptcha calls window[onload] as
 * soon as the script is ready, and the callback has to already exist.
 *
 * Widgets are discovered from the DOM through a MutationObserver rather than
 * from a list baked in at render time, so a widget inserted later -- by
 * Livewire, by Alpine, by a modal -- renders without extra wiring, and a
 * widget removed from the page is dropped from the registry.
 */
window.__NAMESPACE__ = window.__NAMESPACE__ || (function () {
    const RESET_EVENT = '__NAMESPACE__:reset';

    // DOM id -> hCaptcha widget id.
    const widgets = {};

    // DOM id -> Promise while execute() is running.
    const pending = {};

    let apiReady = false;

    const container = (id) => document.getElementById(id);

    const responseField = (id) => document.getElementById(id + '-response');

    const statusElement = (id) => document.getElementById(id + '-status');

    function setStatus(id, text) {
        const el = statusElement(id);

        if (! el) {
            return;
        }

        el.textContent = text || '';
        el.hidden = ! text;
    }

    // data-callback="app.onCaptcha" -> window.app.onCaptcha
    function resolveGlobal(path) {
        return String(path).split('.').reduce((scope, key) => (scope == null ? undefined : scope[key]), window);
    }

    function callHook(el, datasetKey, ...args) {
        const name = el.dataset[datasetKey];

        if (! name) {
            return;
        }

        const handler = resolveGlobal(name);

        if (typeof handler === 'function') {
            handler(...args);
        }
    }

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

        const id = el.id;

        let widgetId;

        try {
            widgetId = window.hcaptcha.render(el, {
                sitekey: el.dataset.sitekey,
                theme: el.dataset.theme || undefined,
                size: el.dataset.size || undefined,
                tabindex: el.dataset.tabindex || undefined,
                callback: (token) => {
                    publish(id, token);
                    setStatus(id, '');
                    callHook(el, 'callback', token);
                },
                'expired-callback': () => {
                    publish(id, '');
                    callHook(el, 'expiredCallback');
                },
                'chalexpired-callback': () => {
                    publish(id, '');
                    callHook(el, 'chalexpiredCallback');
                },
                'error-callback': (code) => {
                    publish(id, '');
                    setStatus(id, el.dataset.messageError);
                    callHook(el, 'errorCallback', code);
                },
                'open-callback': () => callHook(el, 'openCallback'),
                'close-callback': () => callHook(el, 'closeCallback'),
            });
        } catch (error) {
            // Mark the container attempted regardless of the failure, or the
            // next animation frame retries it forever.
            el.dataset.hcaptchaRendered = 'true';
            setStatus(id, el.dataset.messageError);

            return;
        }

        el.dataset.hcaptchaRendered = 'true';
        widgets[id] = widgetId;

        if (el.dataset.size === 'invisible') {
            bindInvisibleSubmit(el);
        }
    }

    // Forget widgets whose container left the document (a closed modal, a
    // Livewire branch that rendered away), or whose container is still in
    // the document but lost its rendered flag -- the container inside
    // wire:ignore survives a morph that removes and re-adds the whole
    // wire:ignore block in one batch, leaving a stale handle pointing at a
    // fresh, unrendered element. hCaptcha documents no remove(); call it
    // only when the SDK happens to provide one.
    function prune() {
        Object.keys(widgets).forEach((id) => {
            const el = container(id);

            if (el && document.contains(el) && el.dataset.hcaptchaRendered === 'true') {
                return;
            }

            if (window.hcaptcha && typeof window.hcaptcha.remove === 'function') {
                try {
                    window.hcaptcha.remove(widgets[id]);
                } catch (error) {
                    // The SDK may already have dropped it.
                }
            }

            delete widgets[id];
            delete pending[id];
        });
    }

    function renderAll() {
        prune();

        document
            .querySelectorAll('[data-hcaptcha-explicit]')
            .forEach((el) => {
                // A DOM patch can hand back a container that still carries
                // the rendered flag while its content is gone. Treat an empty
                // container as unrendered.
                if (el.dataset.hcaptchaRendered === 'true' && el.children.length > 0) {
                    return;
                }

                delete el.dataset.hcaptchaRendered;
                render(el);
            });
    }

    function reset(id) {
        // hCaptcha tokens are single-use. After a submit -- successful or
        // not -- the widget holds a spent token, so it has to be reset or
        // the next attempt fails with already-seen-response.
        if (id === undefined) {
            Object.keys(widgets).forEach(reset);

            return;
        }

        if (! (id in widgets)) {
            return;
        }

        try {
            window.hcaptcha.reset(widgets[id]);
        } catch (error) {
            // A widget the SDK no longer knows about has nothing to reset.
        }

        publish(id, '');
        setStatus(id, '');
    }

    // Reset every widget bound to a validated field, searching the element
    // that dispatched the event first (a Livewire component root) and the
    // whole document second.
    function resetField(field, root) {
        const selector = 'input[data-hcaptcha-field="' + CSS.escape(field) + '"]';
        let inputs = root && root.querySelectorAll ? root.querySelectorAll(selector) : [];

        if (inputs.length === 0) {
            inputs = document.querySelectorAll(selector);
        }

        if (inputs.length === 0) {
            reset();

            return;
        }

        inputs.forEach((input) => reset(input.id.replace(/-response$/, '')));
    }

    // Run the challenge for an invisible widget. Resolves with the token,
    // which is also published to the response field.
    function execute(id) {
        if (pending[id]) {
            return pending[id];
        }

        if (! (id in widgets) || ! window.hcaptcha) {
            return Promise.reject(new Error('hCaptcha widget [' + id + '] is not rendered.'));
        }

        const el = container(id);

        setStatus(id, el ? el.dataset.messagePending : '');

        pending[id] = Promise.resolve(window.hcaptcha.execute(widgets[id], { async: true }))
            .then((result) => {
                const token = result && typeof result === 'object' ? result.response : result;

                if (typeof token === 'string' && token !== '') {
                    publish(id, token);
                }

                setStatus(id, '');

                return token;
            })
            .catch((error) => {
                publish(id, '');
                setStatus(id, el ? el.dataset.messageError : '');

                throw error;
            })
            .finally(() => {
                delete pending[id];
            });

        return pending[id];
    }

    // An invisible widget produces its token on demand: intercept the
    // form's submit, execute, publish, then submit again with the token in
    // place. A second submit while a challenge is pending is dropped.
    function bindInvisibleSubmit(el) {
        const form = el.closest('form');

        if (! form || form.dataset.hcaptchaInvisible === el.id) {
            return;
        }

        form.dataset.hcaptchaInvisible = el.id;

        form.addEventListener('submit', (event) => {
            const field = responseField(el.id);

            if (! field || field.value !== '') {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            if (pending[el.id]) {
                return;
            }

            const submitter = event.submitter instanceof HTMLElement ? event.submitter : undefined;

            execute(el.id)
                .then(() => {
                    // A browser keeps the form's submission machinery locked
                    // for the rest of the task that dispatched this event, so
                    // calling requestSubmit() from a microtask continuation
                    // of that same task (as this .then() callback runs) is a
                    // silent no-op. Deferring to a new task via setTimeout
                    // lets the lock clear first.
                    setTimeout(() => {
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit(submitter);
                        } else {
                            form.submit();
                        }
                    }, 0);
                })
                .catch(() => {
                    // The status element already shows the error; the visitor
                    // can submit again.
                });
        }, { capture: true });
    }

    function markReady() {
        apiReady = true;
        renderAll();
    }

    // Discover widgets as they appear and forget them as they disappear,
    // whoever changes the DOM: Livewire's morph, Alpine, a modal library,
    // plain JavaScript. Observing <html> survives wire:navigate, which
    // replaces <body>.
    let scheduled = false;

    function schedule() {
        if (scheduled) {
            return;
        }

        scheduled = true;

        window.requestAnimationFrame(() => {
            scheduled = false;
            renderAll();
        });
    }

    const observer = new MutationObserver((records) => {
        for (const record of records) {
            if (record.addedNodes.length > 0 || record.removedNodes.length > 0) {
                schedule();

                return;
            }
        }
    });

    observer.observe(document.documentElement, { childList: true, subtree: true });

    document.addEventListener('livewire:navigated', () => renderAll());

    // Dispatched by Rules\HCaptcha through Livewire after a token was
    // verified (`detail.field`), or by application code with `detail.id`
    // or no detail at all.
    window.addEventListener(RESET_EVENT, (event) => {
        const detail = event.detail && typeof event.detail === 'object' ? event.detail : {};

        if (detail.id) {
            reset(detail.id);

            return;
        }

        if (detail.field) {
            resetField(detail.field, event.target instanceof Element ? event.target : document);

            return;
        }

        reset();
    });

    return { widgets, render, renderAll, reset, execute, markReady, container };
})();

window.__CALLBACK__ = function () {
    window.__NAMESPACE__.markReady();
};
