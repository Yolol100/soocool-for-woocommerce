(function () {
  var config = window.sooCoolOrderActions || {};
  var actionMessages = config.messages || {};
  var bulkMessages = config.bulkMessages || {};
  var manualSyncMessages = config.manualSync || {};
  var dialogMessages = config.dialog || {};
  var confirmedClicks = typeof WeakSet === 'function' ? new WeakSet() : null;
  var activeDialog = null;
  var dialogSequence = 0;
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

  function restoreFocus(element) {
    if (element && typeof element.focus === 'function' && document.contains(element)) {
      element.focus();
    }
  }

  function focusableElements(frame) {
    if (!frame || !frame.querySelectorAll) {
      return [];
    }
    return Array.prototype.slice.call(frame.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
  }

  function trapFocus(event, frame) {
    if (!event || event.key !== 'Tab') {
      return;
    }
    var items = focusableElements(frame);
    if (!items.length) {
      event.preventDefault();
      return;
    }
    var first = items[0];
    var last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function requestConfirmation(message, trigger, options) {
    if (!message) {
      return Promise.resolve(true);
    }
    if (activeDialog && typeof activeDialog.close === 'function') {
      activeDialog.close(false);
    }

    options = options || {};
    dialogSequence += 1;
    var titleId = 'soocool-confirm-title-' + dialogSequence;
    var descriptionId = 'soocool-confirm-description-' + dialogSequence;
    var previousFocus = trigger && typeof trigger.focus === 'function' ? trigger : document.activeElement;
    var overlay = document.createElement('div');
    var frame = document.createElement('div');
    var header = document.createElement('div');
    var title = document.createElement('h2');
    var closeButton = document.createElement('button');
    var content = document.createElement('div');
    var body = document.createElement('div');
    var icon = document.createElement('span');
    var copy = document.createElement('div');
    var description = document.createElement('p');
    var actions = document.createElement('div');
    var cancelButton = document.createElement('button');
    var confirmButton = document.createElement('button');
    var settled = false;

    overlay.className = 'components-modal__screen-overlay soocool-confirm-overlay';
    frame.className = 'components-modal__frame soocool-confirm-modal' + (options.destructive ? ' is-destructive' : '');
    frame.setAttribute('role', 'dialog');
    frame.setAttribute('aria-modal', 'true');
    frame.setAttribute('aria-labelledby', titleId);
    frame.setAttribute('aria-describedby', descriptionId);
    header.className = 'components-modal__header';
    title.className = 'components-modal__header-heading';
    title.id = titleId;
    title.textContent = options.title || dialogMessages.title || 'SooCool';
    closeButton.type = 'button';
    closeButton.className = 'components-button is-tertiary soocool-confirm-close';
    closeButton.setAttribute('aria-label', dialogMessages.close || dialogMessages.cancel || 'Sluiten');
    closeButton.textContent = '×';
    content.className = 'components-modal__content';
    body.className = 'soocool-confirm-content';
    icon.className = 'soocool-confirm-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '!';
    copy.className = 'soocool-confirm-content__copy';
    description.className = 'soocool-confirm-copy';
    description.id = descriptionId;
    description.textContent = message;
    actions.className = 'soocool-modal-actions';
    cancelButton.type = 'button';
    cancelButton.className = 'button button-secondary components-button is-secondary soocool-confirm-cancel';
    cancelButton.textContent = dialogMessages.cancel || 'Annuleren';
    confirmButton.type = 'button';
    confirmButton.className = 'button button-primary components-button is-primary soocool-confirm-action' + (options.destructive ? ' soocool-danger-fill' : '');
    confirmButton.textContent = options.confirmLabel || dialogMessages.confirm || 'Doorgaan';

    function close(result) {
      if (settled) {
        return;
      }
      settled = true;
      document.removeEventListener('keydown', onKeyDown, true);
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
      document.body.classList.remove('soocool-modal-open');
      activeDialog = null;
      restoreFocus(previousFocus);
      resolvePromise(result);
    }

    function onKeyDown(event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        close(false);
        return;
      }
      trapFocus(event, frame);
    }

    var resolvePromise;
    var promise = new Promise(function (resolve) { resolvePromise = resolve; });
    activeDialog = { close: close };
    closeButton.addEventListener('click', function () { close(false); });
    cancelButton.addEventListener('click', function () { close(false); });
    confirmButton.addEventListener('click', function () { close(true); });
    overlay.addEventListener('mousedown', function (event) { if (event.target === overlay) { close(false); } });
    document.addEventListener('keydown', onKeyDown, true);

    header.appendChild(title);
    header.appendChild(closeButton);
    copy.appendChild(description);
    body.appendChild(icon);
    body.appendChild(copy);
    actions.appendChild(cancelButton);
    actions.appendChild(confirmButton);
    content.appendChild(body);
    content.appendChild(actions);
    frame.appendChild(header);
    frame.appendChild(content);
    overlay.appendChild(frame);
    document.body.appendChild(overlay);
    document.body.classList.add('soocool-modal-open');
    window.setTimeout(function () { cancelButton.focus(); }, 0);
    return promise;
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
    return select ? select.value : '';
  }

  function orderActionContext(event) {
    var button = eventButton(event);
    if (!button || !button.matches || !button.matches('#actions .wc-reload')) {
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
    if (!button || (button.id !== 'doaction' && button.id !== 'doaction2')) {
      return null;
    }

    var form = button.form || closest(button, 'form');
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

  function manualSyncFeedback(button, message, state) {
    var group = closest(button, '.soocool-order-sync-action');
    var feedback = group && group.querySelector ? group.querySelector('.soocool-manual-sync-feedback') : null;
    if (!feedback) {
      return;
    }

    var tone = state === 'error' ? 'error' : (state === 'success' ? 'success' : 'working');
    feedback.hidden = false;
    feedback.className = 'soocool-order-alert soocool-manual-sync-feedback is-' + tone;
    feedback.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    feedback.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
    feedback.setAttribute('aria-atomic', 'true');
    feedback.textContent = message || '';
  }

  function manualSyncError(payload, response) {
    if (payload && payload.data && payload.data.message) {
      return String(payload.data.message);
    }
    if (response && response.status === 403) {
      return manualSyncMessages.forbidden || manualSyncMessages.failed || '';
    }
    return manualSyncMessages.failed || '';
  }

  function performManualSync(button) {
    if (button.getAttribute('aria-busy') === 'true') {
      return false;
    }

    var ajaxUrl = config.ajaxUrl || window.ajaxurl || '';
    var action = button.getAttribute('data-action') || '';
    var orderId = button.getAttribute('data-order-id') || '';
    var nonce = button.getAttribute('data-nonce') || '';
    if (!ajaxUrl || !action || !orderId || !nonce || typeof window.fetch !== 'function') {
      manualSyncFeedback(button, manualSyncMessages.unavailable || manualSyncMessages.failed || '', 'error');
      return false;
    }

    var originalLabel = button.textContent;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = manualSyncMessages.loading || originalLabel;
    manualSyncFeedback(button, manualSyncMessages.working || '', 'working');

    var body = new URLSearchParams();
    body.set('action', action);
    body.set('order_id', orderId);
    body.set('_ajax_nonce', nonce);

    var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
    var timeout;
    var request = window.fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      return response.json().then(function (payload) {
        return { response: response, payload: payload, invalidJson: false };
      }).catch(function () {
        return { response: response, payload: null, invalidJson: true };
      });
    });
    var timeoutRequest = new Promise(function (resolve, reject) {
      timeout = window.setTimeout(function () {
        if (controller) {
          controller.abort();
        }
        var timeoutError = new Error('soocool_manual_sync_timeout');
        timeoutError.name = 'TimeoutError';
        reject(timeoutError);
      }, 45000);
    });

    Promise.race([request, timeoutRequest]).then(function (result) {
      if (result.invalidJson) {
        throw new Error(manualSyncMessages.invalidResponse || manualSyncMessages.failed || '');
      }
      if (!result.response.ok || !result.payload || typeof result.payload !== 'object' || !result.payload.success) {
        throw new Error(manualSyncError(result.payload, result.response));
      }

      window.clearTimeout(timeout);
      var notice = result.payload.data && result.payload.data.notice ? String(result.payload.data.notice) : 'sync_success';
      if (notice !== 'sync_success' && notice !== 'sync_existing') {
        notice = 'sync_success';
      }
      var message = result.payload.data && result.payload.data.message ? String(result.payload.data.message) : (manualSyncMessages.success || '');
      manualSyncFeedback(button, message, 'success');
      var url = new URL(window.location.href);
      url.searchParams.set('soocool_notice', notice);
      window.setTimeout(function () { window.location.assign(url.toString()); }, 350);
    }).catch(function (error) {
      window.clearTimeout(timeout);
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.textContent = originalLabel;
      var isTimeout = error && (error.name === 'AbortError' || error.name === 'TimeoutError');
      var message = isTimeout ? (manualSyncMessages.timeout || manualSyncMessages.failed || '') : (error && error.message ? error.message : (manualSyncMessages.failed || ''));
      manualSyncFeedback(button, message, 'error');
    });

    return false;
  }

  function resumeClick(button) {
    if (!button) {
      return;
    }
    if (button.form) {
      var form = button.form;
      if (typeof form.requestSubmit === 'function' && (button.type === 'submit' || button.getAttribute('type') === 'submit' || !button.getAttribute('type'))) {
        form.requestSubmit(button);
      } else if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else if (typeof form.submit === 'function') {
        form.submit();
      }
      return;
    }
    if (button.tagName && button.tagName.toLowerCase() === 'a' && button.href) {
      window.location.assign(button.href);
      return;
    }
    if (confirmedClicks && typeof button.click === 'function') {
      confirmedClicks.add(button);
      button.click();
    }
  }

  function confirmClick(event) {
    var button = eventButton(event);
    if (button && confirmedClicks && confirmedClicks.has(button)) {
      confirmedClicks.delete(button);
      return true;
    }
    if (button && button.hasAttribute('data-soocool-manual-sync')) {
      stopAction(event);
      requestConfirmation(button.getAttribute('data-soocool-confirm') || '', button).then(function (confirmed) {
        if (confirmed) {
          performManualSync(button);
        }
      });
      return false;
    }

    var context = orderActionContext(event) || bulkActionContext(event);
    if (!context) {
      return true;
    }

    stopAction(event);
    requestConfirmation(context.message, button, { destructive: context.action === 'soocool_cancel_at_soocool' }).then(function (confirmed) {
      if (!confirmed) {
        return;
      }
      rememberSubmit(context);
      resumeClick(button);
    });
    return false;
  }

  function confirmSubmit(event) {
    var form = event && event.target;
    if (!form || !form.querySelector) {
      return true;
    }

    var submitter = event && event.submitter ? event.submitter : null;
    if (!submitter && document.activeElement && document.activeElement.form === form) {
      submitter = document.activeElement;
    }
    if (submitter && submitter.hasAttribute && submitter.hasAttribute('formaction')) {
      return true;
    }

    var orderAction = selectedOrderAction(form);
    if (actionMessages[orderAction]) {
      if (confirmedSubmit(form, orderAction)) {
        return true;
      }
      stopAction(event);
      requestConfirmation(actionMessages[orderAction], document.activeElement, { destructive: orderAction === 'soocool_cancel_at_soocool' }).then(function (confirmed) {
        if (!confirmed) { return; }
        rememberSubmit({ form: form, action: orderAction });
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else if (typeof form.submit === 'function') { form.submit(); }
      });
      return false;
    }

    var bulkAction = selectedBulkAction(form);
    if (bulkMessages[bulkAction]) {
      if (confirmedSubmit(form, bulkAction)) {
        return true;
      }
      stopAction(event);
      requestConfirmation(bulkMessages[bulkAction], document.activeElement).then(function (confirmed) {
        if (!confirmed) { return; }
        rememberSubmit({ form: form, action: bulkAction });
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else if (typeof form.submit === 'function') { form.submit(); }
      });
      return false;
    }

    return true;
  }

  document.addEventListener('click', confirmClick, true);
  document.addEventListener('submit', confirmSubmit, true);
}());
