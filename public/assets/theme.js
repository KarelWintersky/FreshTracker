/* Runs synchronously in <head> so the correct theme is painted before app.js. */
(function () {
    'use strict';

    const KEY = 'ft-theme';
    const root = document.documentElement;

    const stored = window.localStorage ? window.localStorage.getItem(KEY) : null;
    if (stored === 'day' || stored === 'night') {
        root.dataset.theme = stored;
    }

    window.Theme = {
        current() {
            return root.dataset.theme === 'night' ? 'night' : 'day';
        },
        apply(theme) {
            const value = theme === 'night' ? 'night' : 'day';
            root.dataset.theme = value;
            try {
                window.localStorage.setItem(KEY, value);
            } catch (e) {
                /* private mode */
            }
        },
        toggle() {
            this.apply(this.current() === 'day' ? 'night' : 'day');
        },
        hasStoredChoice() {
            try {
                return window.localStorage.getItem(KEY) !== null;
            } catch (e) {
                return false;
            }
        },
    };
})();
