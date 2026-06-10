(function(wp){
  var el = wp.element.createElement;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var __ = wp.i18n.__;
  var apiFetch = wp.apiFetch;
  var c = wp.components;

  apiFetch.use(apiFetch.createNonceMiddleware((window.sooCoolAdmin && window.sooCoolAdmin.nonce) || ''));

  function cleanPayload(settings){
    var payload = Object.assign({}, settings || {});
    delete payload.api_key_masked;
    delete payload.api_key_present;
    delete payload.api_key_source;
    delete payload.api_key_length;
    delete payload.api_key_first4;
    delete payload.api_key_last4;
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
  function getLogs(){ return api('/soocool/v1/logs'); }
  function clearLogs(){ return api('/soocool/v1/logs', 'DELETE'); }

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
  function Note(props){ return el('div', { className: 'soocool-note' }, props.children); }
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
        el(c.ToggleControl, { label: __('Create pickup task before delivery', 'soocool-for-woocommerce'), help: __('Enable this when SooCool collects packages from you. The order will then be sent with both a pickup task and a delivery task.', 'soocool-for-woocommerce'), checked: !!settings.enable_pickup, onChange: function(v){ upd('enable_pickup', v); } }),
        el(c.TextControl, { label: __('WooCommerce order reference prefix', 'soocool-for-woocommerce'), help: __('Optional prefix added before the WooCommerce order number, for example TEST-.', 'soocool-for-woocommerce'), value: settings.order_reference_prefix || '', onChange: function(v){ upd('order_reference_prefix', v); } })
      )),
      el('div', { className: 'soocool-card-grid' },
        el(Card, null,
          el('h3', null, __('Pickup location', 'soocool-for-woocommerce')),
          el('div', { className: 'soocool-field-grid two' },
            el(c.TextControl, { label: __('Pickup company', 'soocool-for-woocommerce'), value: settings.pickup_company || '', onChange: function(v){ upd('pickup_company', v); } }),
            el(c.TextControl, { label: __('Pickup contact name', 'soocool-for-woocommerce'), value: settings.pickup_contact_name || '', onChange: function(v){ upd('pickup_contact_name', v); } }),
            el(c.TextControl, { type: 'email', label: __('Pickup email', 'soocool-for-woocommerce'), value: settings.pickup_email || '', onChange: function(v){ upd('pickup_email', v); } }),
            el(c.TextControl, { label: __('Pickup phone', 'soocool-for-woocommerce'), value: settings.pickup_phone || '', onChange: function(v){ upd('pickup_phone', v); } }),
            el(c.TextControl, { label: __('Pickup street', 'soocool-for-woocommerce'), value: settings.pickup_street || '', onChange: function(v){ upd('pickup_street', v); } }),
            el(c.TextControl, { label: __('Pickup house number', 'soocool-for-woocommerce'), value: settings.pickup_house_number || '', onChange: function(v){ upd('pickup_house_number', v); } }),
            el(c.TextControl, { label: __('Pickup postal code', 'soocool-for-woocommerce'), value: settings.pickup_postal_code || '', onChange: function(v){ upd('pickup_postal_code', v); } }),
            el(c.TextControl, { label: __('Pickup city', 'soocool-for-woocommerce'), value: settings.pickup_city || '', onChange: function(v){ upd('pickup_city', v); } }),
            el(c.TextControl, { label: __('Pickup country code', 'soocool-for-woocommerce'), value: settings.pickup_country || 'NL', onChange: function(v){ upd('pickup_country', v); } })
          )
        ),
        el(Card, null,
          el('h3', null, __('Scheduling & goods', 'soocool-for-woocommerce')),
          el('div', { className: 'soocool-field-grid two' },
            el(c.TextControl, { type: 'number', min: 0, max: 30, label: __('Pickup date offset in days', 'soocool-for-woocommerce'), value: String(settings.pickup_days_offset == null ? 0 : settings.pickup_days_offset), onChange: function(v){ upd('pickup_days_offset', Number(v)); } }),
            el(c.TextControl, { type: 'number', min: 0, max: 30, label: __('Delivery date offset in days', 'soocool-for-woocommerce'), value: String(settings.delivery_days_offset == null ? 1 : settings.delivery_days_offset), onChange: function(v){ upd('delivery_days_offset', Number(v)); } }),
            el(c.TextControl, { type: 'time', label: __('Pickup window starts', 'soocool-for-woocommerce'), value: settings.pickup_time_from || '', onChange: function(v){ upd('pickup_time_from', v); } }),
            el(c.TextControl, { type: 'time', label: __('Pickup window ends', 'soocool-for-woocommerce'), value: settings.pickup_time_to || '', onChange: function(v){ upd('pickup_time_to', v); } }),
            el('div', { className: 'soocool-fixed-window' },
              el('span', { className: 'soocool-fixed-window__label' }, __('Delivery time window', 'soocool-for-woocommerce')),
              el('strong', null, '08:00–18:00'),
              el('span', null, __('Required by SooCool for delivery tasks and always used in the order payload.', 'soocool-for-woocommerce'))
            )
          ),
          el('div', { className: 'soocool-field-grid two' },
            el(c.TextControl, { label: __('Fallback goods description', 'soocool-for-woocommerce'), value: settings.goods_description_fallback || '', onChange: function(v){ upd('goods_description_fallback', v); } }),
            el(c.SelectControl, { label: __('Temperature requirement', 'soocool-for-woocommerce'), value: settings.temperature_regime || 'cooled', options: [{ label: __('Cooled', 'soocool-for-woocommerce'), value: 'cooled' }, { label: __('Frozen', 'soocool-for-woocommerce'), value: 'frozen' }, { label: __('Ambient', 'soocool-for-woocommerce'), value: 'ambient' }], onChange: function(v){ upd('temperature_regime', v); } })
          ),
          el(c.TextControl, { type: 'url', label: __('SooCool webhook URL', 'soocool-for-woocommerce'), help: __('Optional callback URL sent with the SooCool order. Leave empty when SooCool does not require it.', 'soocool-for-woocommerce'), value: settings.webhook_url || '', onChange: function(v){ upd('webhook_url', v); } })
        )
      ),
      el(Note, null, __('When SooCool collects packages from you, keep pickup enabled. SooCool receives two tasks: one pickup task with the pickup window above and one delivery task with the fixed 08:00–18:00 delivery window.', 'soocool-for-woocommerce')),
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
    var loadingState = useState(true);
    var loading = loadingState[0];
    var setLoading = loadingState[1];
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    var errorState = useState('');
    var errorMessage = errorState[0];
    var setErrorMessage = errorState[1];
    function refresh(){ setBusy(true); setErrorMessage(''); getLogs().then(function(next){ setLogs(next); if (!loading) { emitToast(__('Logs refreshed.', 'soocool-for-woocommerce'), 'success'); } }).catch(function(){ var message = __('Could not load logs.', 'soocool-for-woocommerce'); setErrorMessage(message); emitToast(message, 'error'); }).finally(function(){ setBusy(false); setLoading(false); }); }
    function clear(){ if (busy) { return; } setBusy(true); setErrorMessage(''); clearLogs().then(function(){ setLogs([]); emitToast(__('Logs cleared.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ var message = __('Could not clear logs.', 'soocool-for-woocommerce'); setErrorMessage(message); emitToast(message, 'error'); }).finally(function(){ setBusy(false); }); }
    useEffect(function(){ refresh(); }, []);
    return el(FieldGroup, { title: __('Activity logs', 'soocool-for-woocommerce'), badge: __('Sanitized', 'soocool-for-woocommerce'), description: __('Recent sanitized SooCool API activity. Secrets and full payload bodies are not stored.', 'soocool-for-woocommerce') },
      loading ? el(Loading, { message: __('Loading logs…', 'soocool-for-woocommerce') }) : null,
      errorMessage ? el(ErrorNotice, { message: errorMessage }) : null,
      el(Note, null, __('Use these logs for troubleshooting only. Use WooCommerce order notes and the SooCool portal for final operational checks.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: refresh }, __('Refresh', 'soocool-for-woocommerce')), el(c.Button, { variant: 'secondary', className: 'soocool-danger-action', disabled: busy || !logs.length, onClick: clear }, __('Clear logs', 'soocool-for-woocommerce'))),
      el(LogsTable, { logs: logs })
    );
  }

  var tabs = [
    { name: 'connection', title: __('API connection', 'soocool-for-woocommerce'), className: 'soocool-tab' },
    { name: 'mapping', title: __('Pickup & delivery', 'soocool-for-woocommerce'), className: 'soocool-tab' },
    { name: 'automation', title: __('Automation', 'soocool-for-woocommerce'), className: 'soocool-tab' },
    { name: 'labels', title: __('Shipping labels', 'soocool-for-woocommerce'), className: 'soocool-tab' },
    { name: 'logs', title: __('Activity logs', 'soocool-for-woocommerce'), className: 'soocool-tab' }
  ];
  function App(){ return el('main', { className: 'soocool-shell', 'aria-label': __('SooCool for WooCommerce settings', 'soocool-for-woocommerce') }, el(ToastHost), el('section', { className: 'soocool-panel', 'aria-label': __('SooCool settings', 'soocool-for-woocommerce') }, el(c.TabPanel, { className: 'soocool-tabs', tabs: tabs }, function(tab){ if (tab.name === 'connection') { return el(ConnectionScreen); } if (tab.name === 'mapping') { return el(MappingScreen); } if (tab.name === 'automation') { return el(AutomationScreen); } if (tab.name === 'labels') { return el(LabelsScreen); } return el(LogsScreen); }))); }
  var root = document.getElementById('soocool-admin-app');
  if (root) { wp.element.createRoot(root).render(el(App)); }
})(window.wp);
