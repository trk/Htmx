// PW HTMX Guard
// Purpose: prevent HTMX swaps from injecting a full HTML document into an admin target.

document.addEventListener('htmx:before:swap', function (evt) {
  try {
    var detail = evt.detail || {};
    var ctx = detail.ctx || {};
    var response = typeof ctx.response === 'string' ? ctx.response : '';

    if (!response) return;

    var txt = response.trim().toLowerCase();
    var looksLikeFullDoc =
      txt.startsWith('<!doctype html') ||
      txt.startsWith('<html') ||
      (txt.indexOf('<head') !== -1 && txt.indexOf('<body') !== -1);

    if (!looksLikeFullDoc) return;

    evt.preventDefault();

    console.error('HTMX swap prevented (full HTML document received). Check endpointUrl/CSRF.');

    var msg =
      (typeof window !== 'undefined' && window.__pwHtmxGuardMessage) ||
      'HTMX request did not return the expected fragment. Swap prevented. Check endpointUrl/CSRF/redirect.';

    if (typeof ProcessWire !== 'undefined' && ProcessWire && typeof ProcessWire.alert === 'function') {
      ProcessWire.alert(msg);
    } else {
      alert(msg);
    }
  } catch (e) {
    // ignore
  }
});
