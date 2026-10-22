/*
 * Ru'ya page navigation (back / forward).
 *
 * The breadcrumb is deterministic navigation: every ancestor crumb is a real
 * link to its canonical route. This control is a *secondary* convenience that
 * walks the browser's own history, so it never replaces a canonical link and
 * never appears inside a CRUD dialog.
 *
 * Availability is derived from the tab's own navigation trail, which is kept
 * in sessionStorage. A reload of the current page keeps its position in the
 * trail, a fresh link truncates the trail beyond the new page (that is what
 * "back" does), and a new destination extends it. Both buttons are disabled
 * while their direction is unavailable.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'ruya.nav.trail';
    const TRAIL_LIMIT = 50;

    const readTrail = function () {
        try {
            const raw = window.sessionStorage.getItem(STORAGE_KEY);
            const parsed = raw ? JSON.parse(raw) : null;
            if (parsed && Array.isArray(parsed.trail)) {
                return parsed;
            }
        } catch (error) {
            /* storage unavailable: fall back to "no history" */
        }
        return { trail: [], index: -1 };
    };

    const writeTrail = function (state) {
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (error) {
            /* nothing to do: the control simply stays disabled */
        }
    };

    const state = readTrail();
    const current = window.location.pathname + window.location.search;

    if (state.trail[state.index] === current) {
        /* reload of the current page: keep the position */
    } else {
        const known = state.trail.indexOf(current);
        if (known === -1) {
            state.trail.push(current);
            state.index = state.trail.length - 1;
        } else {
            /* the operator went back (or forward) to a page we already know */
            state.index = known;
        }
        if (state.trail.length > TRAIL_LIMIT) {
            const drop = state.trail.length - TRAIL_LIMIT;
            state.trail.splice(0, drop);
            state.index -= drop;
        }
        writeTrail(state);
    }

    const buttons = document.querySelectorAll('[data-ruya-nav]');
    if (!buttons.length) {
        return;
    }

    const refresh = function () {
        buttons.forEach(function (button) {
            const direction = button.getAttribute('data-ruya-nav');
            const available = direction === 'back'
                ? state.index > 0
                : state.index > -1 && state.index < state.trail.length - 1;

            button.disabled = !available;
            button.setAttribute('aria-disabled', available ? 'false' : 'true');
        });
    };

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) {
                return;
            }
            if (button.getAttribute('data-ruya-nav') === 'back') {
                window.history.back();
            } else {
                window.history.forward();
            }
        });
    });

    refresh();
})();
