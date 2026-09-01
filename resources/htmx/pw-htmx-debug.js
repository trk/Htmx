// PW HTMX Debug Helper
// Logs HTMX v4 lifecycle events to the console when window.__pwHtmxDebug === true.
//
// v4 note: ctx.request = fetch Request (not XHR.open config)
//          ctx.response = fetch Response (not XHR object) — use .status, .ok, .headers

(function () {
  function enabled() {
    return typeof window !== 'undefined' && window.__pwHtmxDebug === true;
  }

  function safeStatus(ctx) {
    try {
      if (!ctx) return null;
      if (ctx.statusCode) return ctx.statusCode;
      if (ctx.response && typeof ctx.response.status === 'number') return ctx.response.status;
      if (ctx.xhr && typeof ctx.xhr.status === 'number') return ctx.xhr.status;
    } catch (e) {}
    return null;
  }

  function safeUrl(ctx, request) {
    try {
      if (request && request.url) return request.url;
      if (ctx && ctx.path) return ctx.path;
      if (ctx && ctx.requestedPath) return ctx.requestedPath;
    } catch (e) {}
    return null;
  }

  function log(eventName, evt) {
    if (!enabled()) return;

    try {
      var detail = (evt && evt.detail) || {};
      var ctx = detail.ctx || {};
      var request = ctx.request || {};

      var info = {
        event: eventName,
        target: (detail.elt && detail.elt.id) ? '#' + detail.elt.id : (detail.target || ctx.target || null),
        request: {
          method: request.method || null,
          url: safeUrl(ctx, request),
          headers: request.headers ? (request.headers.entries ? Object.fromEntries(request.headers.entries()) : request.headers) : null
        },
        responseStatus: safeStatus(ctx)
      };

      if (detail.error) {
        info.error = String(detail.error);
      }
      if (detail.serverResponse && typeof detail.serverResponse === 'string' && detail.serverResponse.length < 500) {
        info.responsePreview = detail.serverResponse;
      }

      console.debug('[PW HTMX]', eventName, info);
    } catch (e) {
      // ignore
    }
  }

  // HTMX v4 colon-phase-action lifecycle events
  var events = [
    'htmx:before:request',
    'htmx:after:request',
    'htmx:before:swap',
    'htmx:after:swap',
    'htmx:after:settle',
    'htmx:config:request',
    'htmx:response:error',
    'htmx:history:restore'
  ];

  events.forEach(function (name) {
    document.addEventListener(name, function (evt) {
      log(name.replace(/^htmx:/, '').replace(/:/g, ''), evt);
    });
  });
})();
