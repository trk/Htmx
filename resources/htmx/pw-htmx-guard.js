// PW HTMX Guard
// Prevents HTMX swaps from injecting a full HTML document into a partial target.
//
// HTMX v4 note: evt.detail.elt = target element, evt.detail.serverResponse = raw response text.
// ctx.response (when present) is a fetch Response object, not an XHR.

document.addEventListener('htmx:before:swap', function (evt) {
  try {
    var detail = evt.detail || {};

    // HTMX v4 provides serverResponse as text on before:swap.
    var responseText = '';
    if (typeof detail.serverResponse === 'string') {
      responseText = detail.serverResponse;
    } else if (detail.fragment && typeof detail.fragment === 'string') {
      responseText = detail.fragment;
    } else if (detail.ctx && typeof detail.ctx.responseText === 'string') {
      responseText = detail.ctx.responseText;
    } else if (detail.xhr && detail.xhr.responseText) {
      responseText = detail.xhr.responseText;
    }

    if (!responseText) return;

    var txt = responseText.trim().toLowerCase();
    var looksLikeFullDoc =
      txt.indexOf('<!doctype html') !== -1 ||
      txt.indexOf('<html') !== -1 ||
      (txt.indexOf('<head') !== -1 && txt.indexOf('<body') !== -1);

    if (!looksLikeFullDoc) return;

    evt.preventDefault();

    console.error('[PW HTMX Guard] Full HTML document detected — swap prevented. Check endpoint URL, CSRF token, or redirect behavior.');

    var msg =
      (typeof window !== 'undefined' && window.__pwHtmxGuardMessage) ||
      'HTMX request did not return the expected fragment. Swap prevented. Check endpoint URL, CSRF token, or redirect.';

    if (typeof ProcessWire !== 'undefined' && ProcessWire && typeof ProcessWire.alert === 'function') {
      ProcessWire.alert(msg);
    } else {
      window.alert(msg);
    }
  } catch (e) {
    // defensive no-op
  }
});
