// PW HTMX Guard
// Purpose: prevent HTMX swaps from injecting a full HTML document into an admin target.
// This usually indicates a wrong endpoint (404), login redirect, or CSRF problem.

document.addEventListener('htmx:beforeSwap', function (evt) {
  try {
    var detail = evt.detail || {};
    var xhr = detail.xhr;
    if (!xhr || typeof xhr.responseText !== 'string') return;

    var txt = xhr.responseText.trim().toLowerCase();

    // Full-document HTML signals
    var looksLikeFullDoc =
      txt.startsWith('<!doctype html') ||
      txt.startsWith('<html') ||
      (txt.indexOf('<head') !== -1 && txt.indexOf('<body') !== -1);

    if (!looksLikeFullDoc) return;

    // Cancel the swap
    evt.preventDefault();
    if (detail.shouldSwap !== undefined) detail.shouldSwap = false;

    // Admin notice message
    console.error('HTMX swap prevented (full HTML document received). Check endpointUrl/CSRF.');

    // PW admin notice (if available)
    if (typeof ProcessWire !== 'undefined' && ProcessWire && typeof ProcessWire.alert === 'function') {
      var msg =
        (typeof window !== 'undefined' && window.__pwHtmxGuardMessage) ||
        'HTMX request did not return the expected fragment. Swap prevented. Check endpointUrl/CSRF/redirect.';
      ProcessWire.alert(msg);
    } else {
      var msg2 =
        (typeof window !== 'undefined' && window.__pwHtmxGuardMessage) ||
        'HTMX request did not return the expected fragment. Swap prevented. Check endpointUrl/CSRF/redirect.';
      alert(msg2);
    }
  } catch (e) {
    // ignore
  }
});
