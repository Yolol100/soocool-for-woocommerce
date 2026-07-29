(function () {
  var config = window.sooCoolOrderActions || {};
  var actionMessages = config.messages || {};
  var bulkMessages = config.bulkMessages || {};
  var manualSyncMessages = config.manualSync || {};
  var confirmedSubmits = typeof WeakMap === 'function' ? new WeakMap() : null;

  function closest(element, selector) {
    return element && element.closest ? element.closest(selector) : null;
  }

  function eventButton(event) {
    return event && event.target ? closest(event.target, 'button, input[type="submit"], input[type="button"], a.button') : null;
  }

  function stopAction(event) {
    if (!event) {
      return false;
    }

    event.preventDefault();
    event.stopPropagation();
    event.returnValue = false;
    if (typeof event.stopImmediatePropagation === 'function') {
      event.stopImmediatePropagation();
    }
    return false;
  }

  function ask(message, event) {
    if (!message) {
      return true;
    }

    return window.confirm(message) ? true : stopAction(event);
  }

  function selectedBulkAction(form) {
    if (!form || !form.querySelector) {
      return '';
    }

    var select = form.querySelector('select[name="action"]');
    var action = select ? select.value : '';
    if (!action || action === '-1') {
      select = form.querySelector('select[name="action2"]');
      action = select ? select.value : '';
    }

    return action && action !== '-1' ? action : '';
  }

  function selectedOrderAction(scope) {
    var root = scope && scope.querySelector ? scope : document;
    var select = root.querySelector('select[name="wc_order_action"]');
    if (!select && root !== document) {
      select = document.querySelector('select[name="wc_order_action"]');
    }
    return select ? select.value : '';
  }

  function orderActionContext(event) {
    var button = eventButton(event);
    if (!button) {
      return null;
    }

    var form = button.form || closest(button, 'form');
    var action = selectedOrderAction(form || document);
    if (!actionMessages[action]) {
      return null;
    }

    return {
      form: form,
      action: action,
      message: actionMessages[action]
    };
  }

  function bulkActionContext(event) {
    var button = eventButton(event);
    var form = button ? (button.form || closest(button, 'form')) : event && event.target;
    if (!form || !form.querySelector || !form.querySelector('select[name="action"], select[name="action2"]')) {
      return null;
    }

    var action = selectedBulkAction(form);
    if (!bulkMessages[action]) {
      return null;
    }

    return {
      form: form,
      action: action,
      message: bulkMessages[action]
    };
  }

  function rememberSubmit(context) {
    if (confirmedSubmits && context.form) {
      confirmedSubmits.set(context.form, context.action);
    }
  }

  function confirmedSubmit(form, action) {
    if (!confirmedSubmits || !form) {
      return false;
    }

    if (confirmedSubmits.get(form) !== action) {
      return false;
    }

    confirmedSubmits.delete(form);
    return true;
  }

  function manualSyncFeedback(button, message, isError) {
    var group = closest(button, '.soocool-order-sync-action');
    var feedback = group && group.querySelector ? group.querySelector('.soocool-manual-sync-feedback') : null;
    if (!feedback) {
      return;
    }

    feedback.hidden = false;
    feedback.className = 'soocool-order-alert soocool-manual-sync-feedback' + (isError ? ' is-error' : '');
    feedback.textContent = message || '';
  }

  function manualSyncError(payload, response) {
    if (payload && payload.data && payload.data.message) {
      return String(payload.data.message);
    }
    if (response && response.status === 403) {
      return manualSyncMessages.forbidden || 'Je mag deze order niet synchroniseren.';
    }
    return manualSyncMessages.failed || 'SooCool-synchronisatie mislukt. Probeer opnieuw.';
  }

  function runManualSync(button, event) {
    if (!ask(button.getAttribute('data-soocool-confirm') || '', event)) {
      return false;
    }

    stopAction(event);
    if (button.getAttribute('aria-busy') === 'true') {
      return false;
    }

    var ajaxUrl = config.ajaxUrl || window.ajaxurl || '';
    var action = button.getAttribute('data-action') || '';
    var orderId = button.getAttribute('data-order-id') || '';
    var nonce = button.getAttribute('data-nonce') || '';
    if (!ajaxUrl || !action || !orderId || !nonce || typeof window.fetch !== 'function') {
      manualSyncFeedback(button, manualSyncMessages.failed || 'SooCool-synchronisatie kon niet worden gestart.', true);
      return false;
    }

    var originalLabel = button.textContent;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = manualSyncMessages.loading || 'Synchroniseren...';
    manualSyncFeedback(button, manualSyncMessages.working || 'De order wordt met SooCool gesynchroniseerd.', false);

    var body = new URLSearchParams();
    body.set('action', action);
    body.set('order_id', orderId);
    body.set('_ajax_nonce', nonce);

    window.fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (payload) {
        return { response: response, payload: payload };
      });
    }).then(function (result) {
      if (!result.response.ok || !result.payload || !result.payload.success) {
        throw new Error(manualSyncError(result.payload, result.response));
      }

      var notice = result.payload.data && result.payload.data.notice ? result.payload.data.notice : 'sync_success';
      var url = new URL(window.location.href);
      url.searchParams.set('soocool_notice', notice);
      window.location.assign(url.toString());
    }).catch(function (error) {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.textContent = originalLabel;
      manualSyncFeedback(button, error && error.message ? error.message : manualSyncMessages.failed, true);
    });

    return false;
  }

  function confirmClick(event) {
    var button = eventButton(event);
    if (button && button.hasAttribute('data-soocool-manual-sync')) {
      return runManualSync(button, event);
    }

    var context = orderActionContext(event) || bulkActionContext(event);
    if (!context) {
      return true;
    }

    if (!ask(context.message, event)) {
      return false;
    }

    rememberSubmit(context);
    return true;
  }

  function confirmSubmit(event) {
    var form = event && event.target;
    if (!form || !form.querySelector) {
      return true;
    }

    var orderAction = selectedOrderAction(form);
    if (actionMessages[orderAction]) {
      if (confirmedSubmit(form, orderAction)) {
        return true;
      }
      return ask(actionMessages[orderAction], event);
    }

    var bulkAction = selectedBulkAction(form);
    if (bulkMessages[bulkAction]) {
      if (confirmedSubmit(form, bulkAction)) {
        return true;
      }
      return ask(bulkMessages[bulkAction], event);
    }

    return true;
  }

  document.addEventListener('click', confirmClick, true);
  document.addEventListener('submit', confirmSubmit, true);
}());
