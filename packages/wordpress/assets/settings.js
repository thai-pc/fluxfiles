/* FluxFiles settings screen: tabs, unsaved-changes guard, copy buttons, connection test. */
(function () {
    'use strict';
    var cfg = window.FluxFilesSettings || { i18n: {} };
    var t = function (k, d) { return (cfg.i18n && cfg.i18n[k]) || d; };
    var root = document.querySelector('.ff-settings');
    if (!root) { return; }
    var form = document.getElementById('ff-settings-form');
    var tabs = Array.prototype.slice.call(root.querySelectorAll('.ff-tab'));
    var STORE = 'fluxfiles.settings.tab';

    // ---------- tabs ----------
    function select(key, focus) {
        var found = false;
        tabs.forEach(function (tab) {
            var on = tab.getAttribute('data-tab') === key;
            found = found || on;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            var panel = document.getElementById(tab.getAttribute('aria-controls'));
            if (panel) { panel.hidden = !on; }
            if (on && focus) { tab.focus(); }
        });
        if (!found && tabs[0]) { return select(tabs[0].getAttribute('data-tab'), focus); }
        try { sessionStorage.setItem(STORE, key); } catch (e) { /* storage blocked */ }
        if (history.replaceState) { history.replaceState(null, '', '#' + key); }
        // Save brings the admin back to the same tab (options.php keeps the fragment).
        var ref = form && form.querySelector('input[name="_wp_http_referer"]');
        if (ref) { ref.value = ref.value.replace(/#.*$/, '') + '#' + key; }
    }
    tabs.forEach(function (tab, i) {
        tab.addEventListener('click', function () { select(tab.getAttribute('data-tab')); });
        tab.addEventListener('keydown', function (e) {
            var n = null;
            if (e.key === 'ArrowRight') { n = tabs[(i + 1) % tabs.length]; }
            if (e.key === 'ArrowLeft') { n = tabs[(i - 1 + tabs.length) % tabs.length]; }
            if (e.key === 'Home') { n = tabs[0]; }
            if (e.key === 'End') { n = tabs[tabs.length - 1]; }
            if (n) { e.preventDefault(); select(n.getAttribute('data-tab'), true); }
        });
    });
    var initial = (location.hash || '').replace('#', '');
    if (!initial) { try { initial = sessionStorage.getItem(STORE) || ''; } catch (e) { initial = ''; } }
    // A failed save (e.g. a too-short secret) lands on the tab with the error.
    var err = document.querySelector('.settings-error.notice-error, .notice-error.settings-error');
    if (err && /secret/i.test(err.id || '')) { initial = 'general'; }
    select(initial || 'general');

    // ---------- unsaved changes ----------
    var dirtyEl = root.querySelector('.ff-dirty');
    var dirty = false;
    var snapshot = form ? new URLSearchParams(new FormData(form)).toString() : '';
    function markTabs() {
        tabs.forEach(function (tab) {
            var panel = document.getElementById(tab.getAttribute('aria-controls'));
            var changed = false;
            if (panel) {
                Array.prototype.forEach.call(panel.querySelectorAll('input,select,textarea'), function (el) {
                    if (el.type === 'hidden' || !el.name) { return; }
                    var now = (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value;
                    var was = (el.type === 'checkbox' || el.type === 'radio') ? el.defaultChecked
                        : (el.tagName === 'SELECT' ? (el.querySelector('option[selected]') || el.options[0] || {}).value : el.defaultValue);
                    if (now !== was) { changed = true; }
                });
            }
            var dot = tab.querySelector('.ff-tab-dot');
            if (changed && !dot) { dot = document.createElement('span'); dot.className = 'ff-tab-dot'; dot.setAttribute('aria-hidden', 'true'); tab.appendChild(dot); }
            if (!changed && dot) { dot.parentNode.removeChild(dot); }
        });
    }
    function onChange() {
        dirty = new URLSearchParams(new FormData(form)).toString() !== snapshot;
        if (dirtyEl) { dirtyEl.textContent = dirty ? t('unsaved', 'You have unsaved changes') : ''; }
        markTabs();
    }
    if (form) {
        form.addEventListener('input', onChange);
        form.addEventListener('change', onChange);
        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) {
            if (!dirty) { return; }
            e.preventDefault();
            e.returnValue = t('leave', '');
        });
    }

    // ---------- copy ----------
    root.addEventListener('click', function (e) {
        var b = e.target.closest('.ff-copy');
        if (!b) { return; }
        var text = b.getAttribute('data-copy');
        if (text === null) {
            var el = document.getElementById(b.getAttribute('data-copy-target'));
            text = el ? el.textContent : '';
        }
        var done = function () {
            var label = b.textContent;
            b.textContent = t('copied', 'Copied');
            setTimeout(function () { b.textContent = label; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () {});
        }
    });

    // ---------- connection test ----------
    root.addEventListener('click', function (e) {
        var b = e.target.closest('.ff-test-disk');
        if (!b) { return; }
        var out = b.parentNode.querySelector('.ff-test-result');
        var card = b.closest('.ff-card');
        var unsaved = false;
        if (card) {
            Array.prototype.forEach.call(card.querySelectorAll('input:not([type=hidden]),select'), function (el) {
                var now = el.type === 'checkbox' ? el.checked : el.value;
                var was = el.type === 'checkbox' ? el.defaultChecked
                    : (el.tagName === 'SELECT' ? (el.querySelector('option[selected]') || el.options[0] || {}).value : el.defaultValue);
                if (now !== was) { unsaved = true; }
            });
        }
        if (unsaved) { out.setAttribute('data-state', 'bad'); out.textContent = t('saveFirst', 'Save first.'); return; }
        b.disabled = true;
        out.removeAttribute('data-state');
        out.textContent = t('testing', 'Testing…');
        var body = new URLSearchParams({ action: 'fluxfiles_test_disk', _wpnonce: cfg.testNonce, disk: b.getAttribute('data-disk') });
        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                out.setAttribute('data-state', j && j.success ? 'ok' : 'bad');
                out.textContent = (j && j.data && j.data.message) || 'Error';
            })
            .catch(function () { out.setAttribute('data-state', 'bad'); out.textContent = 'Error'; })
            .then(function () { b.disabled = false; });
    });
})();
