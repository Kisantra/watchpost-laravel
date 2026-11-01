/*!
 * Kisantra Watchpost, browser side.
 *
 * Keeps a short trail of what this tab did (pages, clicks, form submits,
 * requests that failed or changed something). When a request to this website
 * fails with a server error, it captures what the visitor was looking at and
 * sends both, through the website, to Watchpost, tagged with the request's
 * trace id so they land on the right error.
 */
(function () {
    'use strict';

    var script = document.currentScript;

    if (!script || window.__watchpostBrowser) {
        return;
    }

    window.__watchpostBrowser = true;

    var endpoint = script.getAttribute('data-endpoint');
    var limit = parseInt(script.getAttribute('data-breadcrumbs') || '10', 10);
    var screenshots = script.getAttribute('data-screenshots') === '1';
    var failedTrace = script.getAttribute('data-failed-trace');
    var STORAGE_KEY = 'watchpost:trail';
    var HEADER = 'X-Watchpost-Trace';
    var reporting = false;

    function trail() {
        try {
            return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function remember(type, label) {
        var items = trail();
        var last = items[items.length - 1];

        if (last && last.type === type && last.label === label) {
            return;
        }

        items.push({ type: type, label: String(label).slice(0, 200), at: new Date().toISOString() });

        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(items.slice(-limit)));
        } catch (e) {
            // Storage can be full or blocked; the trail is a nice-to-have.
        }
    }

    function parse(url) {
        try {
            return new URL(url, location.href);
        } catch (e) {
            return null;
        }
    }

    // Paths only: query strings can carry tokens and personal data.
    function pathOf(url) {
        var parsed = parse(url);

        if (!parsed) {
            return '';
        }

        return parsed.origin === location.origin ? parsed.pathname : parsed.host + parsed.pathname;
    }

    function tracked(url) {
        var parsed = parse(url);

        return !!parsed && parsed.origin === location.origin && parsed.pathname !== pathOf(endpoint);
    }

    function newTrace() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;

            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    function post(payload) {
        if (!endpoint || !window.fetch) {
            return;
        }

        try {
            window.fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload),
            }).catch(function () {});
        } catch (e) {
            // Monitoring must never break the page.
        }
    }

    function report(trace, url) {
        if (reporting || !endpoint) {
            return;
        }

        reporting = true;

        // Whatever the app adds after the failure (an error modal) is not what
        // the visitor was looking at, so it is left out of the picture.
        var before = document.body ? Array.prototype.slice.call(document.body.children) : [];
        var payload = {
            trace: trace,
            url: pathOf(url),
            page: location.pathname,
            breadcrumbs: trail(),
            viewport: window.innerWidth + 'x' + window.innerHeight,
            user_agent: navigator.userAgent,
            screenshot: null,
        };
        var deliver = function () {
            post(payload);
            reporting = false;
        };

        if (!screenshots || !window.modernScreenshot) {
            deliver();

            return;
        }

        window.modernScreenshot
            .domToJpeg(document.documentElement, {
                quality: 0.7,
                scale: 1,
                width: window.innerWidth,
                height: window.innerHeight,
                timeout: 4000,
                backgroundColor: window.getComputedStyle(document.body).backgroundColor,
                // Shift the page by the scroll position; fixed elements stay put.
                style: { marginTop: -window.scrollY + 'px', marginLeft: -window.scrollX + 'px' },
                filter: function (node) {
                    if (node.nodeType !== 1) {
                        return true;
                    }

                    if (node.hasAttribute('data-watchpost-hide')) {
                        return false;
                    }

                    return node.parentElement !== document.body || before.indexOf(node) !== -1;
                },
            })
            .then(function (image) {
                payload.screenshot = image;
                deliver();
            }, deliver);
    }

    function finished(method, url, status, trace) {
        method = String(method || 'GET').toUpperCase();

        if (status >= 400 || status === 0 || method !== 'GET') {
            remember('request', method + ' ' + pathOf(url) + ' ' + (status || 'gagal'));
        }

        if (status >= 500) {
            report(trace, url);
        }
    }

    // Pages, including the ones single-page apps switch to without reloading.
    function page() {
        remember('page', location.pathname);
    }

    page();

    ['pushState', 'replaceState'].forEach(function (name) {
        var original = history[name];

        history[name] = function () {
            var result = original.apply(this, arguments);
            page();

            return result;
        };
    });

    window.addEventListener('popstate', page);

    document.addEventListener(
        'click',
        function (event) {
            var target = event.target && event.target.closest
                ? event.target.closest('a, button, [role="button"], input[type="submit"], input[type="button"], summary')
                : null;

            if (!target) {
                return;
            }

            var text = target.getAttribute('aria-label') || target.innerText || target.value || target.getAttribute('title') || '';

            remember('click', text.replace(/\s+/g, ' ').trim().slice(0, 60) || target.tagName.toLowerCase());
        },
        true,
    );

    document.addEventListener(
        'submit',
        function (event) {
            remember('submit', pathOf(event.target.getAttribute('action') || location.href));
        },
        true,
    );

    // Same-origin requests carry a trace id the server attaches to any error they cause.
    if (window.fetch) {
        var originalFetch = window.fetch;

        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || String(input);

            if (!tracked(url)) {
                return originalFetch.apply(this, arguments);
            }

            var method = (init && init.method) || (input && input.method) || 'GET';
            var trace = newTrace();
            var options = Object.assign({}, init);
            var headers = new Headers(options.headers || (input instanceof Request ? input.headers : undefined));

            headers.set(HEADER, trace);
            options.headers = headers;

            return originalFetch.call(this, input, options).then(
                function (response) {
                    finished(method, url, response.status, trace);

                    return response;
                },
                function (error) {
                    finished(method, url, 0, trace);

                    throw error;
                },
            );
        };
    }

    var open = XMLHttpRequest.prototype.open;
    var send = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this.__watchpost = { method: method, url: String(url) };

        return open.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
        var info = this.__watchpost;
        var xhr = this;

        if (info && tracked(info.url)) {
            var trace = newTrace();

            try {
                xhr.setRequestHeader(HEADER, trace);
            } catch (e) {
                // Already sent or a forbidden header; carry on untraced.
            }

            // readystatechange fires before load/loadend, so this runs before
            // libraries like axios hand the failure to the app.
            xhr.addEventListener('readystatechange', function () {
                if (xhr.readyState === 4) {
                    finished(info.method, info.url, xhr.status, trace);
                }
            });
        }

        return send.apply(this, arguments);
    };

    // This page is itself the server's error page after a full page load:
    // the trail that led here is what there is to send.
    if (failedTrace) {
        post({
            trace: failedTrace,
            url: location.pathname,
            page: location.pathname,
            breadcrumbs: trail(),
            viewport: window.innerWidth + 'x' + window.innerHeight,
            user_agent: navigator.userAgent,
            screenshot: null,
        });
    }
})();
