(function () {
  function getDefaultTarget() {
    return document.body || document.documentElement || document;
  }

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
      return getDefaultTarget();
    }

    try {
      return document.querySelector(detail.target) || getDefaultTarget();
    } catch (e) {
      return getDefaultTarget();
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

  // HTMX v4 emits the synthetic event names we put in HX-Trigger immediately when headers arrive.
  // We then replay the user's events at the correct lifecycle phase (after swap / settle).
  document.addEventListener('__pwHtmxAfterSwap', function (evt) {
    var eventMap = getDeferredEvents(evt);
    if (!eventMap) return;
    dispatchAfter('htmx:after:swap', eventMap, 0);
  });

  document.addEventListener('__pwHtmxAfterSettle', function (evt) {
    var eventMap = getDeferredEvents(evt);
    if (!eventMap) return;
    dispatchAfter('htmx:after:settle', eventMap, 50);
  });

  function syncPwHtmxInputfieldConfig(root) {
    var scope = (root && root.querySelectorAll) ? root : document;
    var configNodes = scope.querySelectorAll('script[data-pw-htmx-config]');
    var foundConfig = false;

    for (var i = 0; i < configNodes.length; i++) {
      var node = configNodes[i];
      foundConfig = true;
      try {
        var raw = node.textContent || node.innerText || '{}';
        var payload = JSON.parse(raw);

        if (payload && payload.jsConfig && typeof payload.jsConfig === 'object') {
          window.ProcessWire = window.ProcessWire || {};
          window.ProcessWire.config = window.ProcessWire.config || {};
          Object.keys(payload.jsConfig).forEach(function (key) {
            window.ProcessWire.config[key] = payload.jsConfig[key];
          });
        }

        if (payload && Array.isArray(payload.styles)) {
          payload.styles.forEach(function (href) {
            if (!href || document.querySelector('link[href="' + href + '"]')) return;
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            (document.head || document.documentElement).appendChild(link);
          });
        }

        if (payload && Array.isArray(payload.scripts)) {
          payload.scripts.forEach(function (src) {
            if (!src || document.querySelector('script[src="' + src + '"]')) return;
            var script = document.createElement('script');
            script.src = src;
            (document.head || document.documentElement).appendChild(script);
          });
        }
      } catch (e) {}

      if (node.parentNode) {
        node.parentNode.removeChild(node);
      }
    }

    var initTarget = (root && root.nodeType === 1) ? root : document.body;
    if (foundConfig || (initTarget && initTarget.querySelector && initTarget.querySelector('.Inputfield, .Inputfields'))) {
      if (window.Inputfields && typeof window.Inputfields.init === 'function') {
        try {
          window.Inputfields.init(initTarget);
        } catch (e) {}
      }
      if (window.jQuery && initTarget) {
        try {
          window.jQuery(initTarget).trigger('reloaded', ['htmx']);
        } catch (e) {}
      }
    }
  }

  document.addEventListener('htmx:after:settle', function (evt) {
    var detail = (evt && evt.detail) || {};
    var target = detail.target || (detail.ctx && detail.ctx.target) || evt.target || document;
    syncPwHtmxInputfieldConfig(target);
  });
})();
