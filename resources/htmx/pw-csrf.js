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

  if (!tokenName || !tokenValue) {
    return;
  }

  if (request.body instanceof FormData) {
    request.body.set(tokenName, tokenValue);
  }

  request.headers = request.headers || {};
  request.headers['X-Requested-With'] = 'XMLHttpRequest';
});
