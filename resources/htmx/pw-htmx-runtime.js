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

  // In HTMX v4, #handleTriggerHeader runs in the request `finally` block after `await this.swap(ctx)`
  // has already completed swap and settle. We schedule __pwHtmxAfterSwap immediately on the microtask queue
  // and __pwHtmxAfterSettle on the next animation frame / macrotask, while cleaning up any lifecycle
  // listener so no listener ever leaks across requests.
  function dispatchAfter(lifecycleEventName, eventMap, useNextFrame) {
    var fired = false;
    var timerId = null;

    function run() {
      if (fired) {
        return;
      }
      fired = true;
      document.removeEventListener(lifecycleEventName, run);
      if (timerId !== null) {
        window.clearTimeout(timerId);
      }
      dispatchEvents(eventMap);
    }

    document.addEventListener(lifecycleEventName, run, { once: true });

    if (useNextFrame && typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(function () {
        timerId = window.setTimeout(run, 0);
      });
    } else {
      timerId = window.setTimeout(run, 0);
    }
  }

  document.addEventListener('__pwHtmxAfterSwap', function (evt) {
    var eventMap = getDeferredEvents(evt);
    if (!eventMap) {
      return;
    }
    dispatchAfter('htmx:after:swap', eventMap, false);
  });

  document.addEventListener('__pwHtmxAfterSettle', function (evt) {
    var eventMap = getDeferredEvents(evt);
    if (!eventMap) {
      return;
    }
    dispatchAfter('htmx:after:settle', eventMap, true);
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
            if (!href || document.querySelector('link[href="' + href + '"]')) {
              return;
            }
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            (document.head || document.documentElement).appendChild(link);
          });
        }

        if (payload && Array.isArray(payload.scripts)) {
          payload.scripts.forEach(function (src) {
            if (!src || document.querySelector('script[src="' + src + '"]')) {
              return;
            }
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

    var initTarget = (root && root.nodeType === 1 && root !== document.body && root !== document.documentElement) ? root : null;
    if (initTarget && (foundConfig || (initTarget.querySelector && initTarget.querySelector('.Inputfield, .Inputfields')))) {
      if (window.Inputfields && typeof window.Inputfields.init === 'function') {
        try {
          window.Inputfields.init(initTarget);
        } catch (e) {}
      }
      if (window.jQuery) {
        try {
          if (typeof window.setupLanguageTabs === 'function') {
            var $initScope = window.jQuery(initTarget);
            $initScope.find('.langTabs').each(function () {
              var $lt = window.jQuery(this);
              var $ul = $lt.children('ul');
              if ($ul.length && $ul.attr('uk-tab') !== undefined) {
                $ul.addClass('uk-tab');
              }
              if (!$lt.children('.LanguageSupport.LanguageSupportCurrent').length || !$ul.find('li.uk-active').length) {
                $lt.removeClass('langTabsInit');
                $lt.find('a.langTabLink, a[data-lang]').off('mouseover mouseout click');
              }
            });
            window.setupLanguageTabs($initScope);
          }
          window.jQuery(initTarget).trigger('reloaded', ['htmx']);
        } catch (e) {}
      }
    }
  }

  function resolveSettleTarget(evt) {
    var detail = (evt && evt.detail) || {};
    if (detail.newContent && detail.newContent.length) {
      for (var i = 0; i < detail.newContent.length; i++) {
        if (detail.newContent[i] && detail.newContent[i].nodeType === 1 && detail.newContent[i].isConnected) {
          return detail.newContent[i];
        }
      }
    }
    if (detail.target && detail.target.nodeType === 1 && detail.target.isConnected) {
      return detail.target;
    }
    if (detail.task && detail.task.ctx && detail.task.ctx.target && detail.task.ctx.target.id) {
      var byId = document.getElementById(detail.task.ctx.target.id);
      if (byId) {
        return byId;
      }
    }
    if (evt && evt.target && evt.target !== document && evt.target.nodeType === 1 && evt.target.isConnected) {
      return evt.target;
    }
    return null;
  }

  document.addEventListener('htmx:after:settle', function (evt) {
    var target = resolveSettleTarget(evt);
    if (target) {
      syncPwHtmxInputfieldConfig(target);
    }
  });
})();
