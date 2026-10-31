// PW HTMX Debug Helper
// Logs HTMX v4 lifecycle events to the console when window.__pwHtmxDebug === true.
//
// HTMX v4 note: ctx.request = { action, method, form, submitter, body, headers }
//               ctx.response.raw = fetch Response — use .status, .ok, .headers
//               ctx.text = raw response text

(function () {
  function enabled() {
    return typeof window !== 'undefined' && window.__pwHtmxDebug === true;
  }

  function safeStatus(ctx) {
    try {
      if (!ctx) return null;
      if (ctx.statusCode) return ctx.statusCode;
      if (ctx.response && ctx.response.raw && typeof ctx.response.raw.status === 'number') {
        return ctx.response.raw.status;
      }
      if (ctx.response && typeof ctx.response.status === 'number') {
        return ctx.response.status;
      }
    } catch (e) {}
    return null;
  }

  function safeUrl(ctx, request) {
    try {
      if (request && typeof request.action === 'string') return request.action;
      if (ctx && ctx.response && ctx.response.raw && typeof ctx.response.raw.url === 'string') {
        return ctx.response.raw.url;
      }
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
        target: (ctx.target && ctx.target.id) ? '#' + ctx.target.id : (ctx.target || null),
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
      if (typeof ctx.text === 'string' && ctx.text.length < 500) {
        info.responsePreview = ctx.text;
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
    'htmx:before:history:restore'
  ];

  events.forEach(function (name) {
    document.addEventListener(name, function (evt) {
      log(name, evt);
    });
  });
})();
