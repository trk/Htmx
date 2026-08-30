// PW HTMX Debug Helper
// Purpose: optionally log key HTMX lifecycle events to the console in debug mode.

(function () {
  function enabled() {
    return typeof window !== 'undefined' && window.__pwHtmxDebug === true;
  }

  function safeStatus(response) {
    try {
      return response && typeof response.status === 'number' ? response.status : null;
    } catch (e) {
      return null;
    }
  }

  function log(eventName, evt) {
    if (!enabled()) return;

    try {
      var detail = (evt && evt.detail) || {};
      var ctx = detail.ctx || {};
      var request = ctx.request || {};

      console.debug('[PW HTMX]', eventName, {
        target: ctx.target || null,
        requestConfig: {
          method: request.method || null,
          action: request.action || null,
          headers: request.headers || null
        },
        status: safeStatus(ctx.response)
      });
    } catch (e) {
      // ignore
    }
  }

  document.addEventListener('htmx:before:request', function (evt) {
    log('beforeRequest', evt);
  });

  document.addEventListener('htmx:after:request', function (evt) {
    log('afterRequest', evt);
  });

  document.addEventListener('htmx:response:error', function (evt) {
    log('responseError', evt);
  });
})();
