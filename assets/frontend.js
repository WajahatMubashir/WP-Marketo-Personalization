(function () {
    function getApiRoot() {
        if (window.wpApiSettings && window.wpApiSettings.root) {
            return window.wpApiSettings.root;
        }

        var apiLink = document.querySelector('link[rel="https://api.w.org/"]');
        if (apiLink && apiLink.href) {
            return apiLink.href;
        }

        return '/wp-json/';
    }

    function normalizeSegmentValue(value) {
        if (!value) {
            return 'default';
        }
        return String(value)
            .toLowerCase()
            .trim()
            .replace(/\s+/g, '_')
            .replace(/-/g, '_')
            .replace(/[^a-z0-9\-_]/g, '');
    }

    function isProbablyModalTrigger(el) {
        if (!el) return false;
        var attrs = ['data-kb-popup', 'data-kadence-popup', 'data-toggle', 'data-bs-toggle', 'aria-controls'];
        for (var i = 0; i < attrs.length; i++) {
            if (el.hasAttribute && el.hasAttribute(attrs[i])) {
                return true;
            }
        }
        return false;
    }

    function findCtaLabel(el) {
        if (!el) return null;

        // Kadence button inner text span is preferred to keep structure intact.
        var label =
            el.querySelector('.kt-btn-inner-text') ||
            el.querySelector('a span') ||
            el.querySelector('a') ||
            null;

        if (label) return label;

        // Fallback: allow targeting arbitrary elements (headings, paragraphs, etc.).
        return el;
    }

    function findCtaLink(el) {
        if (!el) return null;
        if (el.tagName === 'A') {
            return el;
        }
        return el.querySelector('a');
    }

    function applyCta(selector, config, options) {
        options = options || {};
        if (!config) return;

        var nodes = document.querySelectorAll(selector);
        if (!nodes.length) return;

        nodes.forEach(function (el) {
            if (config.text) {
                var label = findCtaLabel(el);
                if (label) {
                    label.textContent = config.text;
                }
            }

            if (config.url) {
                // Always store so other scripts (like modal openers) can read it.
                el.setAttribute('data-mp-href', config.url);

                var link = findCtaLink(el);
                if (link) {
                    link.setAttribute('data-mp-href', config.url);
                    if (options.updateHref && !isProbablyModalTrigger(link)) {
                        link.setAttribute('href', config.url);
                    }
                }
            }
        });
    }

    function applyAllCtas(ctas) {
        if (!ctas) return;

        Object.keys(ctas).forEach(function (key) {
            var config = ctas[key] || {};
            var selectors = Array.isArray(config.selectors) ? config.selectors.slice(0) : [];

            var updateHref = true;
            if (typeof config.update_href !== 'undefined') {
                updateHref = !!config.update_href;
            } else if (typeof config.updateHref !== 'undefined') {
                updateHref = !!config.updateHref;
            }

            selectors.forEach(function (selector) {
                applyCta(selector, config, { updateHref: updateHref });
            });
        });
    }

    function getFromCtasByKey(ctas, key) {
        if (!ctas || !key) return null;
        var parts = String(key).split('.');
        if (parts.length !== 2) return null;
        var group = parts[0];
        var field = parts[1];
        if (!ctas[group]) return null;
        return ctas[group][field] || null;
    }

    function applyShortcodePlaceholders(ctas) {
        var nodes = document.querySelectorAll('[data-mp-key]');
        if (!nodes.length) return;

        nodes.forEach(function (el) {
            var key = el.getAttribute('data-mp-key');
            var value = getFromCtasByKey(ctas, key);
            if (!value) return;

            if (key.endsWith('.url')) {
                // If the placeholder is inside an <a>, update its href; otherwise just output the URL.
                if (el.tagName === 'A') {
                    el.setAttribute('href', value);
                    return;
                }
            }

            el.textContent = value;
        });
    }

    function applySegmentDisplay(segment) {
        var slug = normalizeSegmentValue(segment);
        var nodes = document.querySelectorAll('.mp-dynamic [data-segment]');
        if (!nodes.length) return;

        nodes.forEach(function (el) {
            var seg = normalizeSegmentValue(el.getAttribute('data-segment'));
            var shouldShow = slug ? seg === slug : seg === 'default';

            if (shouldShow) {
                el.classList.add('is-active');
            } else {
                el.classList.remove('is-active');
            }
        });
    }

    function setSegment(segment) {
        var value = normalizeSegmentValue(segment);
        document.documentElement.dataset.webSegment = value;
        applySegmentDisplay(value);
    }

    function fetchPersonalization() {
        var root = getApiRoot();
        if (!root) return;

        // Ensure no double slashes when root already ends with /.
        root = root.replace(/\/+$/, '');

        var endpoint = root + '/tcp/v1/personalization';

        fetch(endpoint, { credentials: 'include' })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('Request failed');
                }
                return r.json();
            })
            .then(function (data) {
                if (!data) return;

                if (data.segment) {
                    setSegment(data.segment);
                }

                if (data.ctas) {
                    applyAllCtas(data.ctas);
                    applyShortcodePlaceholders(data.ctas);
                }
            })
            .catch(function () {
                // Silent fail: we fallback to default content if API is unavailable.
            });
    }

    function boot() {
        // Apply any segment already set via cookie/PHP before the API call returns.
        if (document.documentElement.dataset && document.documentElement.dataset.webSegment) {
            applySegmentDisplay(document.documentElement.dataset.webSegment);
        } else {
            applySegmentDisplay('default');
        }

        fetchPersonalization();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
