// PW HTMX Debug Helper
// Purpose: optionally log key HTMX lifecycle events to the console in debug mode.
// This script is a no-op unless window.__pwHtmxDebug === true.

(function () {
  function enabled() {
    return typeof window !== 'undefined' && window.__pwHtmxDebug === true;
  }

  function safeId(el) {
    try {
      if (!el) return null;
      if (typeof el === 'string') return el;
      if (el.id) return el.id;
      return null;
    } catch (e) {
      return null;
    }
  }

  function log(eventName, evt) {
    if (!enabled()) return;

    try {
      var detail = (evt && evt.detail) || {};
      var xhr = detail.xhr;

      console.debug('[PW HTMX]', eventName, {
        target: safeId(detail.target),
        requestConfig: {
          verb: detail.verb || null,
          path: detail.pathInfo || null,
          headers: detail.headers || null,
        },
        status: xhr ? xhr.status : null,
      });
    } catch (e) {
      // ignore
    }
  }

  document.addEventListener('htmx:beforeRequest', function (evt) {
    log('beforeRequest', evt);
  });

  document.addEventListener('htmx:afterRequest', function (evt) {
    log('afterRequest', evt);
  });

  document.addEventListener('htmx:responseError', function (evt) {
    log('responseError', evt);
  });
})();

