/*
 * A stand-in for https://js.hcaptcha.com/1/api.js used by the package's own
 * browser tests, and available to a consuming application's browser tests by
 * pointing `hcaptcha.script.url` at this file served from a route of their
 * own. It implements the part of the hCaptcha JS API the bootstrap script
 * calls, never contacts the network, and produces tokens shaped
 * `fake-token-<widgetId>-<n>`.
 *
 * Inspect or steer it from a test through `window.__fakeHCaptcha`:
 *   loads          how many times this script executed (SDK reload detection)
 *   widgets        widgetId -> { el, params, token, resets }
 *   failNext       set to an hCaptcha error code to make the next solve fail
 *   emptyNext      set true to make the next solve resolve without a token,
 *                  as the real SDK can under some misconfigurations
 */
(function () {
    const currentScript = document.currentScript;
    const query = currentScript && currentScript.src
        ? new URL(currentScript.src, window.location.href).searchParams
        : new URLSearchParams();

    const state = window.__fakeHCaptcha = window.__fakeHCaptcha || {
        loads: 0,
        widgets: {},
        nextId: 1,
        tokens: 0,
        failNext: null,
        emptyNext: false,
    };

    state.loads += 1;

    function widget(widgetId) {
        const entry = state.widgets[widgetId];

        if (! entry) {
            throw new Error('Unknown fake hCaptcha widget [' + widgetId + '].');
        }

        return entry;
    }

    function solve(widgetId) {
        const entry = widget(widgetId);

        if (state.failNext) {
            const code = state.failNext;
            state.failNext = null;
            entry.token = '';

            if (typeof entry.params['error-callback'] === 'function') {
                entry.params['error-callback'](code);
            }

            return Promise.reject(code);
        }

        if (state.emptyNext) {
            state.emptyNext = false;
            entry.token = '';

            return Promise.resolve({ response: '', key: '' });
        }

        state.tokens += 1;
        entry.token = 'fake-token-' + widgetId + '-' + state.tokens;

        if (typeof entry.params.callback === 'function') {
            entry.params.callback(entry.token);
        }

        return Promise.resolve({ response: entry.token, key: 'fake-key-' + state.tokens });
    }

    window.hcaptcha = {
        render(container, params) {
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            const widgetId = 'fake-widget-' + state.nextId++;

            state.widgets[widgetId] = { el, params: params || {}, token: '', resets: 0 };

            const frame = document.createElement('iframe');
            frame.setAttribute('data-fake-hcaptcha', widgetId);
            frame.title = 'fake hCaptcha';
            frame.style.display = 'none';
            el.appendChild(frame);

            if ((params || {}).size !== 'invisible') {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'fake-hcaptcha-checkbox';
                button.textContent = 'I am human';
                button.setAttribute('data-fake-hcaptcha-checkbox', widgetId);
                button.addEventListener('click', () => {
                    solve(widgetId).catch(() => {});
                });
                el.appendChild(button);
            }

            return widgetId;
        },

        execute(widgetId, options) {
            const promise = solve(widgetId);

            if (options && options.async) {
                return promise;
            }

            promise.catch(() => {});

            return undefined;
        },

        reset(widgetId) {
            const entry = widget(widgetId);
            entry.token = '';
            entry.resets += 1;
        },

        getResponse(widgetId) {
            return widget(widgetId).token;
        },

        remove(widgetId) {
            const entry = state.widgets[widgetId];

            if (entry && entry.el) {
                entry.el.innerHTML = '';
            }

            delete state.widgets[widgetId];
        },
    };

    const onload = query.get('onload');

    if (onload && typeof window[onload] === 'function') {
        window[onload]();
    }
})();
