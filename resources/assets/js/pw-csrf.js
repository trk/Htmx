document.addEventListener('htmx:configRequest', function(evt) {
    if (evt.detail.verb !== "get") {
        var tokenName = null;
        var tokenValue = null;

        // Auto-detect CSRF info dynamically using ProcessWire's global JS config
        if (typeof ProcessWire !== "undefined" && ProcessWire.config && ProcessWire.config.csrf) {
            var keys = Object.keys(ProcessWire.config.csrf);
            if (keys.length > 0) {
                tokenName = keys[0];
                tokenValue = ProcessWire.config.csrf[tokenName];
            }
        }

        if (tokenName && tokenValue) {
            evt.detail.parameters[tokenName] = tokenValue;
            
            // Optional: Helps PW core know it's an AJAX request without relying strictly on API
            evt.detail.headers['X-Requested-With'] = 'XMLHttpRequest';
        }
    }
});
