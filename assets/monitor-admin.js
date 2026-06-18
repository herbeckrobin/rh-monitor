/*
 * rh-monitor, Admin-JS.
 *
 * Token-Generator fuer den Health-Endpoint: erzeugt einen zufaelligen Hex-Token
 * (crypto, 24 Byte = 48 Zeichen) und schreibt ihn ins Feld. Das Kopieren laeuft
 * ueber den generischen data-rhbp-copy-Mechanismus des Core.
 */
(function () {
    'use strict';

    function toHex(bytes) {
        var out = '';
        for (var i = 0; i < bytes.length; i++) {
            out += bytes[i].toString(16).padStart(2, '0');
        }
        return out;
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-rhmon-token-generate]');
        if (!btn) {
            return;
        }
        e.preventDefault();

        var wrap = btn.closest('.rhmon-token') || document;
        var input = wrap.querySelector('[data-rhmon-token]');
        if (!input) {
            return;
        }

        var crypto = window.crypto || window.msCrypto;
        if (!crypto || !crypto.getRandomValues) {
            return;
        }

        var bytes = new Uint8Array(24);
        crypto.getRandomValues(bytes);
        input.value = toHex(bytes);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
        input.select();
    });
})();
