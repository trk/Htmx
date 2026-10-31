// PW Admin CSRF helper for HTMX v4
// Reads ProcessWire.config.csrf and injects it into every non-GET HTMX request.
// Uses the HTMX v4 ctx.request object (request.body FormData/URLSearchParams + request.headers).

document.addEventListener('htmx:config:request', function (evt) {
  var ctx = evt.detail && evt.detail.ctx ? evt.detail.ctx : null;
  var request = ctx && ctx.request ? ctx.request : null;

  if (!request || !request.method || request.method.toLowerCase() === 'get') {
    return;
  }

  var tokenName = null;
  var tokenValue = null;

  if (typeof ProcessWire !== 'undefined' && ProcessWire.config && ProcessWire.config.csrf) {
    var keys = Object.keys(ProcessWire.config.csrf);
    if (keys.length > 0) {
      tokenName = keys[0];
      tokenValue = ProcessWire.config.csrf[tokenName];
    }
  }

  // Also look for ProcessWire's standard hidden CSRF input (`input._post_token`) or meta tags
  if (!tokenName || !tokenValue) {
    var postTokenInput = document.querySelector('input._post_token[name][value]');
    if (postTokenInput && postTokenInput.name && postTokenInput.value) {
      tokenName = postTokenInput.name;
      tokenValue = postTokenInput.value;
    }
  }

  if (!tokenName || !tokenValue) {
    var meta = document.querySelector('meta[name="csrf-token-name"]');
    var metaValue = document.querySelector('meta[name="csrf-token-value"]');
    if (meta && metaValue && meta.content && metaValue.content) {
      tokenName = meta.content;
      tokenValue = metaValue.content;
    }
  }

  if (!tokenName || !tokenValue) {
    return;
  }

  // 1) Mutate the HTMX v4 request body (FormData or URLSearchParams)
  if (request.body instanceof FormData || (typeof URLSearchParams !== 'undefined' && request.body instanceof URLSearchParams)) {
    try {
      request.body.set(tokenName, tokenValue);
    } catch (ignore) {}
  }

  // 2) Set X-Requested-With and ProcessWire's X-{tokenName} AJAX CSRF header
  if (request.headers && typeof request.headers.set === 'function') {
    try {
      request.headers.set('X-Requested-With', 'XMLHttpRequest');
      request.headers.set('X-' + tokenName, tokenValue);
    } catch (ignore) {}
  } else if (request.headers && typeof request.headers === 'object') {
    request.headers['X-Requested-With'] = 'XMLHttpRequest';
    request.headers['X-' + tokenName] = tokenValue;
  }
});
