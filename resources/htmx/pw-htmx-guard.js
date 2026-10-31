// PW HTMX Guard
// Prevents HTMX v4 swaps from injecting a full HTML document into a partial target.
//
// HTMX v4 note: evt.detail.ctx.text = raw response text.

document.addEventListener('htmx:before:swap', function (evt) {
  try {
    var detail = evt.detail || {};
    var responseText = (detail.ctx && typeof detail.ctx.text === 'string') ? detail.ctx.text : '';

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
