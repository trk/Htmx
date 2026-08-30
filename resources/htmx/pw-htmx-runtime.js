(function () {
  function getDeferredEvents(evt) {
    var detail = (evt && evt.detail) || {};

    if (detail && detail.events && typeof detail.events === 'object') {
      return detail.events;
    }

    if (detail && detail.value && detail.value.events && typeof detail.value.events === 'object') {
      return detail.value.events;
    }

    return null;
  }

  function resolveTarget(detail) {
    if (!detail || typeof detail !== 'object' || !detail.target || typeof detail.target !== 'string') {
      return document.body;
    }

    try {
      return document.querySelector(detail.target) || document.body;
    } catch (e) {
      return document.body;
    }
  }

  function normalizeDetail(detail) {
    return detail && typeof detail === 'object' ? detail : { value: detail };
  }

  function dispatchEvents(eventMap) {
    Object.keys(eventMap).forEach(function (name) {
      var payload = normalizeDetail(eventMap[name]);
      var target = resolveTarget(payload);
      target.dispatchEvent(new CustomEvent(name, {
        bubbles: true,
        detail: payload
      }));
    });
  }

  function dispatchAfter(lifecycleEventName, eventMap, fallbackDelay) {
    var fired = false;

    function run() {
      if (fired) return;
      fired = true;
      dispatchEvents(eventMap);
    }

    document.addEventListener(lifecycleEventName, run, { once: true });
    window.setTimeout(run, fallbackDelay);
  }

  document.body.addEventListener('__pwHtmxAfterSwap', function (evt) {
    var eventMap = getDeferredEvents(evt);
    if (!eventMap) return;
    dispatchAfter('htmx:after:swap', eventMap, 0);
  });

  document.body.addEventListener('__pwHtmxAfterSettle', function (evt) {
    var eventMap = getDeferredEvents(evt);
    if (!eventMap) return;
    dispatchAfter('htmx:after:settle', eventMap, 20);
  });
})();
