(function(wp){
  var root = document.getElementById('soocool-admin-app');
  if (!root || !wp || !wp.element || !wp.i18n || !wp.apiFetch || !wp.components) {
    return;
  }

  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var __ = wp.i18n.__;
  var apiFetch = wp.apiFetch;
  var c = wp.components;

  if (apiFetch.createNonceMiddleware) {
    apiFetch.use(apiFetch.createNonceMiddleware((window.sooCoolAdmin && window.sooCoolAdmin.nonce) || ''));
  }

  function cleanPayload(settings){
    var payload = Object.assign({}, settings || {});
    delete payload.api_key_masked;
    delete payload.api_key_present;
    delete payload.api_key_source;
    delete payload.api_key_length;
    delete payload.api_key_status;
    var keyValue = payload.api_key == null ? '' : String(payload.api_key).trim();
    var uuidMatch = keyValue.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
    if (uuidMatch) {
      payload.api_key = uuidMatch[0].toLowerCase();
    } else if (!keyValue || keyValue.indexOf('***') !== -1 || keyValue.indexOf('•') !== -1) {
      delete payload.api_key;
    }
    return payload;
  }
  function api(path, method, data){
    var args = { path: path };
    if (method) { args.method = method; }
    if (typeof data !== 'undefined') { args.data = data; }
    return apiFetch(args);
  }
  function getSettings(){ return api('/soocool/v1/settings'); }
  function saveSettings(settings){ return api('/soocool/v1/settings', 'POST', cleanPayload(settings)); }
  function testConnection(){ return api('/soocool/v1/connection/test', 'POST'); }
  function runManualTest(data){ return api('/soocool/v1/manual-test/order', 'POST', data); }
  function getLogs(limit, offset){ return api('/soocool/v1/logs?limit=' + encodeURIComponent(limit || 50) + '&offset=' + encodeURIComponent(offset || 0)); }
  function clearLogs(){ return api('/soocool/v1/logs', 'DELETE'); }
  function getWebhookSecret(){ return api('/soocool/v1/webhook/secret'); }
  function regenWebhookSecret(){ return api('/soocool/v1/webhook/secret', 'POST'); }
  function resyncFailed(){ return api('/soocool/v1/maintenance/resync-failed', 'POST'); }
  function copyText(text){
    var value = String(text || '');
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(value);
      }
    } catch (e) {}
    return new Promise(function(resolve, reject){
      var textarea = document.createElement('textarea');
      textarea.value = value;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'fixed';
      textarea.style.top = '-1000px';
      textarea.style.left = '-1000px';
      document.body.appendChild(textarea);
      textarea.focus();
      textarea.select();
      try {
        if (document.execCommand && document.execCommand('copy')) {
          resolve();
        } else {
          reject(new Error('clipboard'));
        }
      } catch (e) {
        reject(e);
      } finally {
        document.body.removeChild(textarea);
      }
    });
  }

  function Loading(props){ return el('div', { className: 'soocool-inline-status', role: 'status', 'aria-live': 'polite' }, el(c.Spinner), el('span', null, props && props.message ? props.message : __('Loading settings...', 'soocool-for-woocommerce'))); }
  function ErrorNotice(props){ return el(c.Notice, { status: 'error', isDismissible: false }, props.message); }
  function FieldGroup(props){
    return el('section', { className: 'soocool-card' },
      el('div', { className: 'soocool-card-header' },
        el('div', null, el('h2', null, props.title), props.description ? el('p', { className: 'soocool-muted' }, props.description) : null),
        props.badge ? el('span', { className: 'soocool-pill is-subtle' }, props.badge) : null
      ),
      el('div', { className: 'soocool-fields' }, props.children)
    );
  }
  function Card(props){ return el('div', { className: 'soocool-settings-card' + (props.soft ? ' is-soft' : '') }, props.children); }
  function SaveButton(props){ return el(c.Button, { variant: 'primary', isBusy: props.isSaving, disabled: props.isSaving, onClick: props.onClick, className: 'soocool-primary-action' }, props.isSaving ? __('Saving...', 'soocool-for-woocommerce') : (props.children || __('Save settings', 'soocool-for-woocommerce'))); }
  function Status(props){ return el('div', { className: 'soocool-status is-' + props.tone, role: 'status' }, el('span', { 'aria-hidden': true }, props.tone === 'success' ? '✓' : props.tone === 'error' ? '!' : '•'), el('span', null, props.message)); }
  function Note(props){ return el('div', { className: props.className ? 'soocool-note ' + props.className : 'soocool-note' }, props.children); }
  function WebhookCard(props){
    var settings = props.settings || {};
    var secretState = useState('');
    var secret = secretState[0];
    var setSecret = secretState[1];
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    var url = settings.effective_webhook_url || settings.generated_webhook_url || '';
    var header = settings.webhook_header_name || 'X-SooCool-Webhook-Token';
    var timestampHeader = settings.webhook_timestamp_header_name || 'X-SooCool-Webhook-Timestamp';
    var signatureHeader = settings.webhook_signature_header_name || 'X-SooCool-Webhook-Signature';
    var eventIdHeader = settings.webhook_event_id_header_name || 'X-SooCool-Webhook-Id';
    function copy(value, label){ copyText(value).then(function(){ emitToast(label, 'success'); }).catch(function(){ emitToast(__('Copy failed; select and copy manually.', 'soocool-for-woocommerce'), 'error'); }); }
    function reveal(){ if (busy) { return; } setBusy(true); getWebhookSecret().then(function(r){ setSecret(r && r.secret ? r.secret : ''); }).catch(function(){ emitToast(__('Could not load the webhook token.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); }); }
    function regenerate(){ if (busy) { return; } if (!window.confirm(__('Generate a new webhook token? The current token stops working until SooCool is updated with the new one.', 'soocool-for-woocommerce'))) { return; } setBusy(true); regenWebhookSecret().then(function(r){ setSecret(r && r.secret ? r.secret : ''); emitToast(__('New webhook token generated. Update it in SooCool now.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ emitToast(__('Could not regenerate the webhook token.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); }); }
    return el(Card, null,
      el('h3', null, __('Webhook (track & trace callbacks)', 'soocool-for-woocommerce')),
      el('p', { className: 'soocool-field-help' }, __('Configure SooCool to call this URL and send the token plus HMAC signature headers. Signature input is the timestamp, a dot and the raw body, keyed with the webhook token. Query-token URLs are available only when the explicit fallback filter is enabled.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-field-grid two' },
        el(c.TextControl, { label: __('Webhook URL', 'soocool-for-woocommerce'), value: url, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Token header', 'soocool-for-woocommerce'), value: header, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Timestamp header', 'soocool-for-woocommerce'), value: timestampHeader, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Signature header', 'soocool-for-woocommerce'), value: signatureHeader, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Optional event ID header', 'soocool-for-woocommerce'), value: eventIdHeader, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Signature formula', 'soocool-for-woocommerce'), value: 'hash_hmac(sha256, timestamp + "." + raw_body, webhook_token)', readOnly: true, onChange: function(){} })
      ),
      el('div', { className: 'soocool-actions' },
        el(c.Button, { variant: 'secondary', disabled: !url, onClick: function(){ copy(url, __('Webhook URL copied.', 'soocool-for-woocommerce')); } }, __('Copy URL', 'soocool-for-woocommerce')),
        el(c.Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: reveal }, secret ? __('Refresh token', 'soocool-for-woocommerce') : __('Show token', 'soocool-for-woocommerce')),
        el(c.Button, { variant: 'link', isDestructive: true, disabled: busy, onClick: regenerate }, __('Regenerate token', 'soocool-for-woocommerce'))
      ),
      secret ? el('div', { className: 'soocool-field-grid two' },
        el(c.TextControl, { label: __('Webhook token', 'soocool-for-woocommerce'), value: secret, readOnly: true, onChange: function(){} }),
        el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'secondary', onClick: function(){ copy(secret, __('Webhook token copied.', 'soocool-for-woocommerce')); } }, __('Copy token', 'soocool-for-woocommerce')))
      ) : null
    );
  }
  function ResyncButton(){
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    function run(){ if (busy) { return; } setBusy(true); resyncFailed().then(function(r){ emitToast(r && r.message ? r.message : __('Resync started.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ emitToast(__('Could not start the resync.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); }); }
    return el(c.Button, { variant: 'secondary', className: 'soocool-danger-fill', isBusy: busy, disabled: busy, onClick: run }, __('Resync failed orders', 'soocool-for-woocommerce'));
  }
  var emitToast = function(){};
  function ToastHost(){
    var toastState = useState(null);
    var toast = toastState[0];
    var setToast = toastState[1];
    var leftState = useState(null);
    var toastLeft = leftState[0];
    var setToastLeft = leftState[1];
    useEffect(function(){
      function updateToastPosition(){
        var panel = document.querySelector('.soocool-panel') || document.querySelector('.soocool-shell');
        if (!panel || !panel.getBoundingClientRect) {
          setToastLeft(null);
          return;
        }
        var rect = panel.getBoundingClientRect();
        if (!rect || !rect.width) {
          setToastLeft(null);
          return;
        }
        setToastLeft(Math.round(rect.left + (rect.width / 2)));
      }
      updateToastPosition();
      window.addEventListener('resize', updateToastPosition);
      window.addEventListener('scroll', updateToastPosition, true);
      return function(){
        window.removeEventListener('resize', updateToastPosition);
        window.removeEventListener('scroll', updateToastPosition, true);
      };
    }, []);
    useEffect(function(){
      emitToast = function(message, tone){
        var panel = document.querySelector('.soocool-panel') || document.querySelector('.soocool-shell');
        if (panel && panel.getBoundingClientRect) {
          var rect = panel.getBoundingClientRect();
          if (rect && rect.width) { setToastLeft(Math.round(rect.left + (rect.width / 2))); }
        }
        setToast({ message: message, tone: tone || 'success', id: Date.now() });
      };
      return function(){ emitToast = function(){}; };
    }, []);
    useEffect(function(){
      if (!toast) { return; }
      var timer = setTimeout(function(){ setToast(null); }, 3500);
      return function(){ clearTimeout(timer); };
    }, [toast && toast.id]);
    if (!toast) { return null; }
    return el('div', { className: 'soocool-toast is-' + toast.tone, role: 'status', 'aria-live': 'polite', style: { left: toastLeft ? toastLeft + 'px' : '50%' } }, toast.message);
  }

  function useSettings(loadError){
    var state = useState({});
    var settings = state[0];
    var setSettings = state[1];
    var loadingState = useState(true);
    var loading = loadingState[0];
    var setLoading = loadingState[1];
    var savingState = useState(false);
    var saving = savingState[0];
    var setSaving = savingState[1];
    var savedState = useState(false);
    var saved = savedState[0];
    var setSaved = savedState[1];
    var errorState = useState('');
    var errorMessage = errorState[0];
    var setErrorMessage = errorState[1];
    useEffect(function(){ getSettings().then(setSettings).catch(function(){ setErrorMessage(loadError); }).finally(function(){ setLoading(false); }); }, []);
    function save(failMessage, successMessage){
      if (saving) { return; }
      setSaving(true); setSaved(false); setErrorMessage('');
      saveSettings(settings).then(function(next){ setSettings(next); setSaved(true); emitToast(successMessage || __('Settings saved.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ setErrorMessage(failMessage); emitToast(failMessage, 'error'); }).finally(function(){ setSaving(false); });
    }
    return { settings: settings, setSettings: setSettings, loading: loading, saving: saving, saved: saved, errorMessage: errorMessage, save: save };
  }

  function ConnectionScreen(){
    var s = useSettings(__('Could not load SooCool settings.', 'soocool-for-woocommerce'));
    var testingState = useState(false);
    var testing = testingState[0];
    var setTesting = testingState[1];
    var statusState = useState(null);
    var status = statusState[0];
    var setStatus = statusState[1];
    var settings = s.settings;
    var setSettings = s.setSettings;
    useEffect(function(){
      if (s.loading) { return; }
      if (settings.api_key_status === 'invalid_masked_or_corrupt') {
        emitToast(__('The saved API key is invalid or still contains a masked value. Paste the real SooCool API key and save again.', 'soocool-for-woocommerce'), 'error');
      }
    }, [s.loading, settings.api_key_status]);
    function upd(key, value){ var next = Object.assign({}, settings); next[key] = value; setSettings(next); }
    function ping(){
      if (testing) { return; }
      setTesting(true);
      setStatus({ message: __('Saving settings before testing…', 'soocool-for-woocommerce'), tone: 'neutral' });
      saveSettings(settings)
        .then(function(next){
          setSettings(next);
          setStatus({ message: __('Testing connection…', 'soocool-for-woocommerce'), tone: 'neutral' });
          return testConnection();
        })
        .then(function(result){
          var message = result && result.message ? result.message : __('Connection successful.', 'soocool-for-woocommerce');
          var tone = 'success';
          setStatus({ message: message, tone: tone });
          emitToast(message, tone);
        })
        .catch(function(error){
          var message = error && error.message ? error.message : __('Connection failed. Check API key and base URL.', 'soocool-for-woocommerce');
          setStatus({ message: message, tone: 'error' });
          emitToast(message, 'error');
        })
        .finally(function(){ setTesting(false); });
    }
    return el(FieldGroup, { title: __('API connection', 'soocool-for-woocommerce'), badge: __('Required', 'soocool-for-woocommerce'), description: __('Connect WooCommerce to the correct SooCool API environment before sending orders.', 'soocool-for-woocommerce') },
      s.loading ? el(Loading) : null,
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el(Card, null,
        el('div', { className: 'soocool-field-grid two' },
          el(c.SelectControl, { label: __('SooCool environment', 'soocool-for-woocommerce'), value: settings.environment || 'test', options: [{ label: 'Test', value: 'test' }, { label: 'Production', value: 'production' }], onChange: function(v){ upd('environment', v); } }),
          el(c.TextControl, { type: 'password', label: __('SooCool API key', 'soocool-for-woocommerce'), help: settings.api_key_present ? __('An API key is saved and shown as dots. Click the field to clear the dots, then paste a new key only when you want to replace it.', 'soocool-for-woocommerce') : __('Paste the X-API-Key value for the selected SooCool environment.', 'soocool-for-woocommerce'), value: settings.api_key || settings.api_key_masked || '', onFocus: function(){ if (settings.api_key_masked && settings.api_key === settings.api_key_masked) { upd('api_key', ''); } }, onClick: function(){ if (settings.api_key_masked && settings.api_key === settings.api_key_masked) { upd('api_key', ''); } }, onChange: function(v){ upd('api_key', v); } }),
          el(c.TextControl, { type: 'url', label: __('SooCool test API URL', 'soocool-for-woocommerce'), help: __('Only official SooCool API hosts are accepted by default.', 'soocool-for-woocommerce'), value: settings.test_base_url || '', onChange: function(v){ upd('test_base_url', v); } }),
          el(c.TextControl, { type: 'url', label: __('SooCool production API URL', 'soocool-for-woocommerce'), help: __('Use the official production API host unless SooCool provides a different approved host.', 'soocool-for-woocommerce'), value: settings.production_base_url || '', onChange: function(v){ upd('production_base_url', v); } })
        ),
        null
      ),
      status ? el(Status, { tone: status.tone, message: status.message }) : null,
      el('div', { className: 'soocool-actions' }, el(SaveButton, { isSaving: s.saving, onClick: function(){ s.save(__('Could not save settings. Check the entered values.', 'soocool-for-woocommerce'), __('API settings saved.', 'soocool-for-woocommerce')); } }), el(c.Button, { variant: 'secondary', isBusy: testing, disabled: s.saving || testing || s.loading, onClick: ping }, __('Test connection', 'soocool-for-woocommerce')), settings.environment === 'test' ? el(c.Button, { variant: 'link', href: 'https://orders-test.soocool.nl:8443/#/authenticate/login', target: '_blank', rel: 'noreferrer noopener' }, __('Open SooCool test portal', 'soocool-for-woocommerce')) : null),
    );
  }

  function MappingScreen(){
    var s = useSettings(__('Could not load mapping settings.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    function upd(key, value){ var next = Object.assign({}, settings); next[key] = value; setSettings(next); }
    return el(FieldGroup, { title: __('Pickup & delivery', 'soocool-for-woocommerce'), badge: __('Orders', 'soocool-for-woocommerce'), description: __('Configure pickup details, delivery scheduling and fallback goods details used for SooCool orders.', 'soocool-for-woocommerce') },
      s.loading ? el(Loading) : null,
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el(Card, { soft: true }, el('div', { className: 'soocool-compact-row' },
        el(c.ToggleControl, { label: __('Create pickup task before delivery', 'soocool-for-woocommerce'), help: __('Only enable this when pickup tasks are agreed with SooCool. The API documentation says pickup tasks should only be used in consultation.', 'soocool-for-woocommerce'), checked: !!settings.enable_pickup, onChange: function(v){ upd('enable_pickup', v); } }),
        el(c.TextControl, { label: __('WooCommerce order reference prefix', 'soocool-for-woocommerce'), help: __('Optional prefix added before the WooCommerce order number, for example TEST-.', 'soocool-for-woocommerce'), value: settings.order_reference_prefix || '', onChange: function(v){ upd('order_reference_prefix', v); } })
      )),
      el('div', { className: 'soocool-mapping-split' },
        el('div', { className: 'soocool-mapping-column soocool-mapping-column-left' },
          el(Card, null,
            el('h3', null, __('Pickup location', 'soocool-for-woocommerce')),
            el('div', { className: 'soocool-field-grid two' },
              el(c.TextControl, { label: __('Pickup company', 'soocool-for-woocommerce'), value: settings.pickup_company || '', onChange: function(v){ upd('pickup_company', v); } }),
              el(c.TextControl, { label: __('Pickup contact name', 'soocool-for-woocommerce'), value: settings.pickup_contact_name || '', onChange: function(v){ upd('pickup_contact_name', v); } }),
              el(c.TextControl, { type: 'email', label: __('Pickup email', 'soocool-for-woocommerce'), value: settings.pickup_email || '', onChange: function(v){ upd('pickup_email', v); } }),
              el(c.TextControl, { label: __('Pickup phone/mobile', 'soocool-for-woocommerce'), value: settings.pickup_phone || '', onChange: function(v){ upd('pickup_phone', v); } }),
              el(c.TextControl, { label: __('Pickup street', 'soocool-for-woocommerce'), value: settings.pickup_street || '', onChange: function(v){ upd('pickup_street', v); } }),
              el(c.TextControl, { label: __('Pickup house number', 'soocool-for-woocommerce'), value: settings.pickup_house_number || '', onChange: function(v){ upd('pickup_house_number', v); } }),
              el(c.TextControl, { label: __('Pickup postal code', 'soocool-for-woocommerce'), value: settings.pickup_postal_code || '', onChange: function(v){ upd('pickup_postal_code', v); } }),
              el(c.TextControl, { label: __('Pickup city', 'soocool-for-woocommerce'), value: settings.pickup_city || '', onChange: function(v){ upd('pickup_city', v); } }),
              el(c.TextControl, { className: 'soocool-field-full', label: __('Pickup country code', 'soocool-for-woocommerce'), value: settings.pickup_country || 'NL', onChange: function(v){ upd('pickup_country', v); } })
            )
          ),
          el(WebhookCard, { settings: settings })
        ),
        el('div', { className: 'soocool-mapping-column soocool-mapping-column-right' },
          el(Card, null,
            el('h3', null, __('Scheduling & goods', 'soocool-for-woocommerce')),
            el('div', { className: 'soocool-field-grid two' },
              el(c.TextControl, { type: 'number', min: 0, max: 30, label: __('Pickup date offset in days', 'soocool-for-woocommerce'), value: String(settings.pickup_days_offset == null ? 1 : settings.pickup_days_offset), onChange: function(v){ upd('pickup_days_offset', Number(v)); } }),
              el(c.TextControl, { type: 'number', min: 0, max: 30, label: __('Delivery date offset in days', 'soocool-for-woocommerce'), value: String(settings.delivery_days_offset == null ? 2 : settings.delivery_days_offset), onChange: function(v){ upd('delivery_days_offset', Number(v)); } }),
              el(c.TextControl, { type: 'time', label: __('Pickup window starts', 'soocool-for-woocommerce'), value: settings.pickup_time_from || '', onChange: function(v){ upd('pickup_time_from', v); } }),
              el(c.TextControl, { type: 'time', label: __('Pickup window ends', 'soocool-for-woocommerce'), value: settings.pickup_time_to || '', onChange: function(v){ upd('pickup_time_to', v); } }),
              el(c.TextControl, { type: 'time', label: __('Delivery window starts', 'soocool-for-woocommerce'), help: __('SooCool requires delivery tasks to use exactly 08:00-18:00 for this connection.', 'soocool-for-woocommerce'), value: '08:00', disabled: true, onChange: function(){} }),
              el(c.TextControl, { type: 'time', label: __('Delivery window ends', 'soocool-for-woocommerce'), help: __('SooCool requires delivery tasks to use exactly 08:00-18:00 for this connection.', 'soocool-for-woocommerce'), value: '18:00', disabled: true, onChange: function(){} })
            ),
            el('div', { className: 'soocool-field-grid two' },
              el(c.TextControl, { label: __('Fallback goods contents', 'soocool-for-woocommerce'), value: settings.goods_description_fallback || '', onChange: function(v){ upd('goods_description_fallback', v); } }),
              el(c.TextControl, { label: __('SooCool packagingType', 'soocool-for-woocommerce'), help: __('Default: box. Change this when SooCool expects a different packagingType value.', 'soocool-for-woocommerce'), value: settings.packaging_type || 'box', onChange: function(v){ upd('packaging_type', v); } }),
              el(c.SelectControl, { label: __('Transport requirement', 'soocool-for-woocommerce'), help: __('Sent as goods[].transportRequirements. Default: cooled.', 'soocool-for-woocommerce'), value: settings.temperature_regime || 'cooled', options: [{ label: __('Cooled', 'soocool-for-woocommerce'), value: 'cooled' }, { label: __('Frozen', 'soocool-for-woocommerce'), value: 'frozen' }, { label: __('Ambient', 'soocool-for-woocommerce'), value: 'ambient' }], onChange: function(v){ upd('temperature_regime', v); } }),
              el(c.TextControl, { type: 'number', min: 1, label: __('Package width', 'soocool-for-woocommerce'), help: __('Sent as goods[].dimensions.width.', 'soocool-for-woocommerce'), value: String(settings.package_width == null ? 60 : settings.package_width), onChange: function(v){ upd('package_width', Number(v)); } }),
              el(c.TextControl, { type: 'number', min: 1, label: __('Package depth', 'soocool-for-woocommerce'), help: __('Sent as goods[].dimensions.depth.', 'soocool-for-woocommerce'), value: String(settings.package_depth == null ? 40 : settings.package_depth), onChange: function(v){ upd('package_depth', Number(v)); } }),
              el(c.TextControl, { type: 'number', min: 1, label: __('Package height', 'soocool-for-woocommerce'), help: __('Sent as goods[].dimensions.height.', 'soocool-for-woocommerce'), value: String(settings.package_height == null ? 11 : settings.package_height), onChange: function(v){ upd('package_height', Number(v)); } }),
              el(c.TextControl, { className: 'soocool-field-full', type: 'number', min: 1, label: __('Package weight', 'soocool-for-woocommerce'), help: __('Sent as goods[].weight.', 'soocool-for-woocommerce'), value: String(settings.package_weight == null ? 1600 : settings.package_weight), onChange: function(v){ upd('package_weight', Number(v)); } })
            ),
            el(c.TextControl, { type: 'url', label: __('SooCool webhook URL', 'soocool-for-woocommerce'), help: __('Optional callback URL sent with the SooCool order. Leave empty to use the plugin receiver. Header token plus HMAC signature authentication is required by default; legacy fallbacks require explicit filters.', 'soocool-for-woocommerce'), value: settings.webhook_url || '', onChange: function(v){ upd('webhook_url', v); } })
          )
        )
      ),
      el(Note, null, __('Pickup is optional. Keep it disabled unless SooCool has agreed that your account should send pickup tasks. Delivery-only orders still include the required delivery task and goods.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions' }, el(SaveButton, { isSaving: s.saving, onClick: function(){ s.save(__('Could not save mapping settings. Check required pickup and delivery fields.', 'soocool-for-woocommerce'), __('Pickup & delivery settings saved.', 'soocool-for-woocommerce')); } }, __('Save pickup & delivery', 'soocool-for-woocommerce')))
    );
  }

  function AutomationScreen(){
    var s = useSettings(__('Could not load automation settings.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    function upd(key, value){ var next = Object.assign({}, settings); next[key] = value; setSettings(next); }
    return el(FieldGroup, { title: __('Automation', 'soocool-for-woocommerce'), badge: __('Optional', 'soocool-for-woocommerce'), description: __('Automatic sending is off by default. Keep manual sync available during staging.', 'soocool-for-woocommerce') },
      s.loading ? el(Loading) : null,
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el(Card, null,
        el('div', { className: 'soocool-field-grid two' },
          el(c.ToggleControl, { label: __('Automatically send eligible orders to SooCool', 'soocool-for-woocommerce'), help: __('Automatically submits matching WooCommerce orders when they reach the selected status.', 'soocool-for-woocommerce'), checked: !!settings.auto_submit_enabled, onChange: function(v){ upd('auto_submit_enabled', v); } }),
          el(c.ToggleControl, { label: __('Allow manual resubmission of synced orders', 'soocool-for-woocommerce'), help: __('Keep disabled unless SooCool asks for a replacement order or a staging retest.', 'soocool-for-woocommerce'), checked: !!settings.allow_resubmit, onChange: function(v){ upd('allow_resubmit', v); } }),
          el(c.SelectControl, { label: __('Order status for automatic sending', 'soocool-for-woocommerce'), value: settings.auto_submit_status || 'processing', options: [{ label: __('Processing', 'soocool-for-woocommerce'), value: 'processing' }, { label: __('Completed', 'soocool-for-woocommerce'), value: 'completed' }, { label: __('On hold', 'soocool-for-woocommerce'), value: 'on-hold' }], onChange: function(v){ upd('auto_submit_status', v); } }),
          el(c.TextControl, { type: 'number', min: 20, max: 500, label: __('Log retention limit', 'soocool-for-woocommerce'), value: String(settings.log_retention == null ? 100 : settings.log_retention), onChange: function(v){ upd('log_retention', Number(v)); } })
        )
      ),
      el(Card, { soft: true },
        el('h3', null, __('Maintenance', 'soocool-for-woocommerce')),
        el('p', { className: 'soocool-field-help' }, __('Re-send every order whose last SooCool sync failed. Large batches run in the background via Action Scheduler.', 'soocool-for-woocommerce')),
        el('div', { className: 'soocool-actions' }, el(ResyncButton))
      ),
      el(Note, null, __('Manual sending remains available from the WooCommerce order screen.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions' }, el(SaveButton, { isSaving: s.saving, onClick: function(){ s.save(__('Could not save automation settings.', 'soocool-for-woocommerce'), __('Automation settings saved.', 'soocool-for-woocommerce')); } }, __('Save automation', 'soocool-for-woocommerce')))
    );
  }

  function LabelsScreen(){
    var s = useSettings(__('Could not load label settings.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    return el(FieldGroup, { title: __('Shipping labels', 'soocool-for-woocommerce'), badge: __('PDF', 'soocool-for-woocommerce'), description: __('Choose the default PDF format used when downloading SooCool shipping labels from an order.', 'soocool-for-woocommerce') },
      s.loading ? el(Loading) : null,
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el(Card, null, el('div', { className: 'soocool-field-grid two' }, el(c.SelectControl, { label: __('Default label format', 'soocool-for-woocommerce'), value: settings.label_output || 'a6', options: [{ label: __('A6 single label', 'soocool-for-woocommerce'), value: 'a6' }, { label: __('Collated A4 sheet', 'soocool-for-woocommerce'), value: 'collated_a4' }], onChange: function(v){ setSettings(Object.assign({}, settings, { label_output: v })); } }))),
      el('div', { className: 'soocool-actions' }, el(SaveButton, { isSaving: s.saving, onClick: function(){ s.save(__('Could not save label settings.', 'soocool-for-woocommerce'), __('Shipping label settings saved.', 'soocool-for-woocommerce')); } }, __('Save shipping label settings', 'soocool-for-woocommerce')))
    );
  }


  function JsonCard(props){
    var value = typeof props.value === 'undefined' ? null : props.value;
    return el(Card, null,
      el('h3', null, props.title),
      el('pre', null, JSON.stringify(value, null, 2))
    );
  }

  function ApiTestScreen(){
    var s = useSettings(__('Could not load API-test settings.', 'soocool-for-woocommerce'));
    var modeState = useState('real');
    var mode = modeState[0];
    var setMode = modeState[1];
    var orderIdState = useState('');
    var orderId = orderIdState[0];
    var setOrderId = orderIdState[1];
    var resultState = useState(null);
    var result = resultState[0];
    var setResult = resultState[1];
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    var errorState = useState('');
    var errorMessage = errorState[0];
    var setErrorMessage = errorState[1];
    var settings = s.settings || {};

    if (s.loading) {
      return el(FieldGroup, { title: __('SooCool API-Test', 'soocool-for-woocommerce'), badge: __('API-Test', 'soocool-for-woocommerce'), description: __('Kies één test: stuur een echte WooCommerce order of gebruik een veilige testorder. De plugin bouwt dezelfde SooCool orderpayload als de normale order-sync.', 'soocool-for-woocommerce') },
        el(Loading, { message: __('Loading API-test settings…', 'soocool-for-woocommerce') })
      );
    }

    if (s.errorMessage) {
      return el(FieldGroup, { title: __('SooCool API-Test', 'soocool-for-woocommerce'), badge: __('API-Test', 'soocool-for-woocommerce'), description: __('Kies één test: stuur een echte WooCommerce order of gebruik een veilige testorder. De plugin bouwt dezelfde SooCool orderpayload als de normale order-sync.', 'soocool-for-woocommerce') },
        el(ErrorNotice, { message: s.errorMessage })
      );
    }

    function submit(){
      if (busy) { return; }
      if (mode === 'real' && !window.confirm(__('This sends the selected WooCommerce order to the active SooCool environment and can create or update a real SooCool order. Continue?', 'soocool-for-woocommerce'))) { return; }
      if (mode === 'real' && settings.environment === 'production' && !window.confirm(__('Production environment is active. Only continue when this is an intentional production order test.', 'soocool-for-woocommerce'))) { return; }
      setBusy(true);
      setErrorMessage('');
      setResult(null);
      runManualTest({ test_mode: mode, woocommerce_order_id: Number(orderId || 0) }).then(function(response){
        setResult(response || {});
        if (response && response.success) {
          emitToast(__('API-test completed.', 'soocool-for-woocommerce'), 'success');
        } else {
          emitToast(__('API-test returned an error. Review the result details.', 'soocool-for-woocommerce'), 'error');
        }
      }).catch(function(){
        var message = __('Could not run the API-test.', 'soocool-for-woocommerce');
        setErrorMessage(message);
        emitToast(message, 'error');
      }).finally(function(){ setBusy(false); });
    }

    function reset(){
      setResult(null);
      setErrorMessage('');
      setMode('real');
      setOrderId('');
    }

    return el(FieldGroup, { title: __('SooCool API-Test', 'soocool-for-woocommerce'), badge: __('API-Test', 'soocool-for-woocommerce'), description: __('Kies één test: stuur een echte WooCommerce order of gebruik een veilige testorder. De plugin bouwt dezelfde SooCool orderpayload als de normale order-sync.', 'soocool-for-woocommerce') },
      errorMessage ? el(ErrorNotice, { message: errorMessage }) : null,
      el(Note, { className: 'soocool-manual-environment-note' },
        el('strong', null, __('Actieve SooCool-omgeving:', 'soocool-for-woocommerce')), ' ',
        el('span', null, settings.environment || __('Niet ingesteld', 'soocool-for-woocommerce')),
        (settings.effective_base_url || settings.api_base_url) ? el('span', { className: 'soocool-env-url' }, settings.effective_base_url || settings.api_base_url) : null
      ),
      result ? el('div', { className: 'soocool-status ' + (result.success ? 'is-success' : 'is-error') + ' soocool-manual-result', role: 'status' },
        el('div', null,
          el('strong', null, result.success ? __('Resultaat: testorder verstuurd naar SooCool', 'soocool-for-woocommerce') : __('Resultaat: testorder niet verstuurd', 'soocool-for-woocommerce')),
          typeof result.status !== 'undefined' ? el('span', null, 'HTTP status: ' + String(result.status)) : null,
          result.message ? el('p', null, el('strong', null, __('Details:', 'soocool-for-woocommerce')), ' ', result.message) : null,
          result.mode ? el('span', null, __('Testmodus:', 'soocool-for-woocommerce') + ' ' + String(result.mode)) : null,
          result.environment ? el('span', null, __('Omgeving:', 'soocool-for-woocommerce') + ' ' + String(result.environment)) : null,
          result.api_base_url ? el('span', null, __('API URL:', 'soocool-for-woocommerce') + ' ' + String(result.api_base_url)) : null,
          result.order_reference ? el('span', null, __('Orderreferentie:', 'soocool-for-woocommerce') + ' ' + String(result.order_reference)) : null,
          result.soocool_order_id ? el('span', null, __('SooCool order-ID:', 'soocool-for-woocommerce') + ' ' + String(result.soocool_order_id)) : null,
          result.portal_dates && result.portal_dates.length ? el('span', null, __('Controleer portaldatum:', 'soocool-for-woocommerce') + ' ' + result.portal_dates.join(', ')) : null
        ),
        el('p', { className: 'soocool-next-step' }, result.success ? __('Volgende stap: zoek in de juiste SooCool portal op de getoonde orderreferentie of op de getoonde pickup-/deliverydatum. Production-orders staan niet in de testportal.', 'soocool-for-woocommerce') : __('Volgende stap: controleer de foutdetails en pas orderdata, API-key, timeWindow of payload aan voordat je opnieuw test.', 'soocool-for-woocommerce'))
      ) : el(Card, null,
        el('h3', null, __('Welke order wil je testen?', 'soocool-for-woocommerce')),
        el('p', { className: 'soocool-field-help' }, __('Gebruik bij voorkeur staging. Een echte WooCommerce order wordt naar de actieve SooCool omgeving gestuurd en kan daar een echte order aanmaken of bijwerken.', 'soocool-for-woocommerce')),
        el('div', { className: 'soocool-test-choice-list', role: 'radiogroup', 'aria-label': __('Type API-test', 'soocool-for-woocommerce') },
          el('label', { className: 'soocool-test-choice', htmlFor: 'soocool_test_mode_real' },
            el('input', { type: 'radio', name: 'test_mode', id: 'soocool_test_mode_real', value: 'real', checked: mode === 'real', onChange: function(){ setMode('real'); } }),
            el('span', null, el('strong', null, __('Echte WooCommerce order', 'soocool-for-woocommerce')), __('Vul hieronder een WooCommerce order-ID in. Dit is de beste stagingtest.', 'soocool-for-woocommerce'))
          ),
          el('label', { className: 'soocool-test-choice', htmlFor: 'soocool_test_mode_dummy' },
            el('input', { type: 'radio', name: 'test_mode', id: 'soocool_test_mode_dummy', value: 'dummy', checked: mode === 'dummy', onChange: function(){ setMode('dummy'); } }),
            el('span', null, el('strong', null, __('Testorder', 'soocool-for-woocommerce')), __('Gebruikt een niet-opgeslagen dummy WooCommerce order. Er wordt geen order in WordPress aangemaakt.', 'soocool-for-woocommerce'))
          )
        ),
        el('div', { className: 'soocool-field-grid two soocool-real-order-fields' },
          el(c.TextControl, { type: 'number', min: 1, label: __('WooCommerce order-ID', 'soocool-for-woocommerce'), help: __('Alleen nodig bij “Echte WooCommerce order”. De plugin haalt deze order op via wc_get_order() en bouwt de normale SooCool payload.', 'soocool-for-woocommerce'), value: orderId, disabled: mode !== 'real', onChange: function(v){ setOrderId(v); } })
        ),
        el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'primary', className: 'soocool-manual-submit soocool-primary-action', isBusy: busy, disabled: busy || (mode === 'real' && !Number(orderId || 0)), onClick: submit }, busy ? __('API-test running…', 'soocool-for-woocommerce') : __('Start API-test naar SooCool', 'soocool-for-woocommerce')))
      ),
      result && result.errors && result.errors.length ? el(Card, null, el('h3', null, __('SooCool errors', 'soocool-for-woocommerce')), el('ul', { className: 'soocool-manual-errors' }, result.errors.map(function(error, index){ return el('li', { key: index }, String(error)); }))) : null,
      result && typeof result.payload !== 'undefined' ? el(JsonCard, { title: __('Verzonden payload', 'soocool-for-woocommerce'), value: result.payload }) : null,
      result && typeof result.body !== 'undefined' ? el(JsonCard, { title: __('SooCool response', 'soocool-for-woocommerce'), value: result.body }) : null,
      result ? el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'primary', className: 'soocool-primary-action', onClick: reset }, __('Nieuwe API-test starten', 'soocool-for-woocommerce'))) : null
    );
  }

  function LogsTable(props){
    return el('div', { className: 'soocool-table-wrap' }, el('table', { className: 'widefat striped soocool-logs', 'aria-label': __('SooCool activity logs', 'soocool-for-woocommerce') },
      el('thead', null, el('tr', null, el('th', { scope: 'col' }, __('Time', 'soocool-for-woocommerce')), el('th', { scope: 'col' }, __('Level', 'soocool-for-woocommerce')), el('th', { scope: 'col' }, __('Message', 'soocool-for-woocommerce')), el('th', { scope: 'col' }, __('Details', 'soocool-for-woocommerce')))),
      el('tbody', null, props.logs.length ? props.logs.map(function(log, index){ return el('tr', { key: String(log.created_at) + index }, el('td', null, log.created_at), el('td', null, el('span', { className: 'soocool-log-level is-' + log.level }, log.level)), el('td', null, log.message), el('td', null, el('code', null, JSON.stringify(log.context)))); }) : el('tr', null, el('td', { colSpan: 4 }, __('No logs yet.', 'soocool-for-woocommerce'))))
    ));
  }

  function LogsScreen(){
    var logsState = useState([]);
    var logs = logsState[0];
    var setLogs = logsState[1];
    var loadingState = useState(false);
    var loading = loadingState[0];
    var setLoading = loadingState[1];
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    var errorState = useState('');
    var errorMessage = errorState[0];
    var setErrorMessage = errorState[1];
    var loadedState = useState(false);
    var loaded = loadedState[0];
    var setLoaded = loadedState[1];
    var hasMoreState = useState(false);
    var hasMore = hasMoreState[0];
    var setHasMore = hasMoreState[1];
    var totalState = useState(0);
    var total = totalState[0];
    var setTotal = totalState[1];
    var pageSize = 50;
    function normalize(response){
      if (Array.isArray(response)) {
        return { items: response, total: response.length, has_more: false };
      }
      response = response || {};
      return { items: Array.isArray(response.items) ? response.items : [], total: Number(response.total || 0), has_more: !!response.has_more };
    }
    function refresh(){
      setBusy(true);
      setLoading(!loaded);
      setErrorMessage('');
      getLogs(pageSize, 0).then(function(response){
        var next = normalize(response);
        setLogs(next.items);
        setTotal(next.total);
        setHasMore(next.has_more);
        if (loaded) { emitToast(__('Logs refreshed.', 'soocool-for-woocommerce'), 'success'); }
      }).catch(function(){
        var message = __('Could not load logs.', 'soocool-for-woocommerce');
        setErrorMessage(message);
        emitToast(message, 'error');
      }).finally(function(){
        setBusy(false);
        setLoading(false);
        setLoaded(true);
      });
    }
    function loadMore(){
      if (busy || !hasMore) { return; }
      setBusy(true);
      setErrorMessage('');
      getLogs(pageSize, logs.length).then(function(response){
        var next = normalize(response);
        setLogs(logs.concat(next.items));
        setTotal(next.total);
        setHasMore(next.has_more);
      }).catch(function(){
        var message = __('Could not load more logs.', 'soocool-for-woocommerce');
        setErrorMessage(message);
        emitToast(message, 'error');
      }).finally(function(){ setBusy(false); });
    }
    function clear(){ if (busy) { return; } setBusy(true); setErrorMessage(''); clearLogs().then(function(){ setLogs([]); setTotal(0); setHasMore(false); emitToast(__('Logs cleared.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ var message = __('Could not clear logs.', 'soocool-for-woocommerce'); setErrorMessage(message); emitToast(message, 'error'); }).finally(function(){ setBusy(false); }); }
    useEffect(function(){ refresh(); }, []);
    return el(FieldGroup, { title: __('Activity logs', 'soocool-for-woocommerce'), badge: __('Sanitized', 'soocool-for-woocommerce'), description: __('Recent sanitized SooCool API activity. Secrets and full payload bodies are not stored.', 'soocool-for-woocommerce') },
      errorMessage ? el(ErrorNotice, { message: errorMessage }) : null,
      el(Note, null, __('Use these logs for troubleshooting only. Use WooCommerce order notes and the SooCool portal for final operational checks.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'secondary', isBusy: busy && loaded, disabled: busy, onClick: refresh }, __('Refresh', 'soocool-for-woocommerce')), el(c.Button, { variant: 'secondary', className: 'soocool-danger-action', disabled: busy || !logs.length, onClick: clear }, __('Clear logs', 'soocool-for-woocommerce'))),
      loading ? el(Loading, { message: __('Loading the latest 50 logs…', 'soocool-for-woocommerce') }) : null,
      !loading ? el(LogsTable, { logs: logs }) : null,
      !loading && (total > 0 || hasMore) ? el('div', { className: 'soocool-log-footer' },
        el('p', { className: 'soocool-field-help soocool-log-count' }, String(logs.length) + ' / ' + String(total || logs.length) + ' ' + __('logs shown.', 'soocool-for-woocommerce')),
        hasMore ? el(c.Button, { variant: 'primary', className: 'soocool-primary-action soocool-load-more', isBusy: busy, disabled: busy, onClick: loadMore }, __('Load more', 'soocool-for-woocommerce')) : null
      ) : null
    );
  }

  var tabs = [
    { name: 'connection', title: __('API connection', 'soocool-for-woocommerce') },
    { name: 'mapping', title: __('Pickup & delivery', 'soocool-for-woocommerce') },
    { name: 'automation', title: __('Automation', 'soocool-for-woocommerce') },
    { name: 'labels', title: __('Shipping labels', 'soocool-for-woocommerce') },
    { name: 'api_test', title: __('API-Test', 'soocool-for-woocommerce') },
    { name: 'logs', title: __('Activity logs', 'soocool-for-woocommerce') }
  ];
  function activeFromHash(){ var hash = (window.location.hash || '').replace('#', ''); return ['connection', 'mapping', 'automation', 'labels', 'api_test', 'logs'].indexOf(hash) !== -1 ? hash : 'connection'; }
  function renderTabContent(active){ if (active === 'mapping') { return el(MappingScreen); } if (active === 'automation') { return el(AutomationScreen); } if (active === 'labels') { return el(LabelsScreen); } if (active === 'api_test') { return el(ApiTestScreen); } if (active === 'logs') { return el(LogsScreen); } return el(ConnectionScreen); }
  function App(){
    var activeState = useState(activeFromHash());
    var active = activeState[0];
    var setActive = activeState[1];
    useEffect(function(){
      function onHashChange(){ setActive(activeFromHash()); }
      window.addEventListener('hashchange', onHashChange);
      return function(){ window.removeEventListener('hashchange', onHashChange); };
    }, []);
    function selectTab(name){ setActive(name); if (name === 'connection') { window.history.replaceState(null, '', window.location.pathname + window.location.search); } else { window.history.replaceState(null, '', '#' + name); } }
    return el('main', { className: 'soocool-shell', 'aria-label': __('SooCool for WooCommerce settings', 'soocool-for-woocommerce') },
      el(ToastHost),
      el('section', { className: 'soocool-panel soocool-tabs', 'aria-label': __('SooCool settings', 'soocool-for-woocommerce') },
        el('div', { className: 'components-tab-panel__tabs', role: 'tablist', 'aria-label': __('SooCool settings sections', 'soocool-for-woocommerce') },
          tabs.map(function(tab){
            var selected = active === tab.name;
            return el(c.Button, { key: tab.name, role: 'tab', id: 'soocool-tab-' + tab.name, 'aria-selected': selected, 'aria-controls': 'soocool-panel-' + tab.name, tabIndex: selected ? 0 : -1, className: 'soocool-tab' + (selected ? ' is-active' : ''), onClick: function(){ selectTab(tab.name); }, onKeyDown: function(event){
              var index = tabs.findIndex(function(item){ return item.name === tab.name; });
              var nextIndex = index;
              if (event.key === 'ArrowRight') { nextIndex = (index + 1) % tabs.length; }
              if (event.key === 'ArrowLeft') { nextIndex = (index - 1 + tabs.length) % tabs.length; }
              if (event.key === 'Home') { nextIndex = 0; }
              if (event.key === 'End') { nextIndex = tabs.length - 1; }
              if (nextIndex !== index) { event.preventDefault(); selectTab(tabs[nextIndex].name); setTimeout(function(){ var next = document.getElementById('soocool-tab-' + tabs[nextIndex].name); if (next && next.focus) { next.focus(); } }, 0); }
            } }, tab.title);
          })
        ),
        el('div', { className: 'components-tab-panel__tab-content', role: 'tabpanel', id: 'soocool-panel-' + active, 'aria-labelledby': 'soocool-tab-' + active }, renderTabContent(active))
      )
    );
  }
  wp.element.createRoot(root).render(el(App));
})(window.wp);
