(function(wp){
  var root = document.getElementById('soocool-admin-app');
  if (!root || !wp || !wp.element || !wp.i18n || !wp.apiFetch || !wp.components) {
    return;
  }

  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
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


  var soocoolWeekdayOptions = [
    { label: __('Maandag', 'soocool-for-woocommerce'), value: 'monday' },
    { label: __('Dinsdag', 'soocool-for-woocommerce'), value: 'tuesday' },
    { label: __('Woensdag', 'soocool-for-woocommerce'), value: 'wednesday' },
    { label: __('Donderdag', 'soocool-for-woocommerce'), value: 'thursday' },
    { label: __('Vrijdag', 'soocool-for-woocommerce'), value: 'friday' },
    { label: __('Zaterdag', 'soocool-for-woocommerce'), value: 'saturday' },
    { label: __('Zondag', 'soocool-for-woocommerce'), value: 'sunday' }
  ];
  function defaultDeliveryRules(){
    return [
      { enabled: true, delivery_weekday: 'monday', cutoff_weekday: 'saturday', cutoff_time: '13:00' },
      { enabled: true, delivery_weekday: 'thursday', cutoff_weekday: 'wednesday', cutoff_time: '13:00' },
      { enabled: true, delivery_weekday: 'saturday', cutoff_weekday: 'friday', cutoff_time: '13:00' }
    ];
  }
  function normalizedDeliveryRules(settings){
    var rules = settings && Array.isArray(settings.checkout_delivery_rules) ? settings.checkout_delivery_rules : defaultDeliveryRules();
    if (!rules.length) { rules = defaultDeliveryRules(); }
    return rules.map(function(rule){
      return Object.assign({ enabled: true, delivery_weekday: 'monday', cutoff_weekday: 'saturday', cutoff_time: '13:00' }, rule || {});
    });
  }

  function defaultDeliveryTimeSlots(){
    var weekdays = soocoolWeekdayOptions.map(function(item){ return item.value; });
    return [
      { enabled: true, label: '', time_from: '07:00', time_to: '11:00', cutoff_time: '07:00', weekdays: weekdays, sort_order: 10 },
      { enabled: true, label: '', time_from: '08:00', time_to: '12:00', cutoff_time: '08:00', weekdays: weekdays, sort_order: 20 },
      { enabled: true, label: '', time_from: '09:00', time_to: '13:00', cutoff_time: '09:00', weekdays: weekdays, sort_order: 30 },
      { enabled: true, label: '', time_from: '14:00', time_to: '18:00', cutoff_time: '14:00', weekdays: weekdays, sort_order: 40 },
      { enabled: true, label: '', time_from: '15:00', time_to: '19:00', cutoff_time: '15:00', weekdays: weekdays, sort_order: 50 },
      { enabled: true, label: '', time_from: '16:00', time_to: '20:00', cutoff_time: '16:00', weekdays: weekdays, sort_order: 60 },
      { enabled: true, label: '', time_from: '17:00', time_to: '21:00', cutoff_time: '17:00', weekdays: weekdays, sort_order: 70 },
      { enabled: true, label: '', time_from: '18:00', time_to: '22:00', cutoff_time: '18:00', weekdays: weekdays, sort_order: 80 }
    ];
  }
  function normalizedDeliveryTimeSlots(settings){
    var slots = settings && Array.isArray(settings.checkout_delivery_time_slots) ? settings.checkout_delivery_time_slots : defaultDeliveryTimeSlots();
    if (!slots.length) { slots = defaultDeliveryTimeSlots(); }
    return slots.map(function(slot, index){
      var defaults = defaultDeliveryTimeSlots()[index] || defaultDeliveryTimeSlots()[0];
      var next = Object.assign({}, defaults, slot || {});
      if (!Array.isArray(next.weekdays) || !next.weekdays.length) {
        next.weekdays = soocoolWeekdayOptions.map(function(item){ return item.value; });
      }
      if (next.sort_order == null) { next.sort_order = (index + 1) * 10; }
      return next;
    });
  }

  function Loading(props){ return el('div', { className: 'soocool-inline-status', role: 'status', 'aria-live': 'polite' }, el(c.Spinner), el('span', null, props && props.message ? props.message : __('Instellingen laden...', 'soocool-for-woocommerce'))); }
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
  function SaveButton(props){ return el(c.Button, { variant: 'primary', isBusy: props.isSaving, disabled: props.isSaving, onClick: props.onClick, className: 'soocool-primary-action' }, props.isSaving ? __('Opslaan...', 'soocool-for-woocommerce') : (props.children || __('Instellingen opslaan', 'soocool-for-woocommerce'))); }
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
    function copy(value, label){ copyText(value).then(function(){ emitToast(label, 'success'); }).catch(function(){ emitToast(__('Kopiëren mislukt; selecteer en kopieer handmatig.', 'soocool-for-woocommerce'), 'error'); }); }
    function reveal(){ if (busy) { return; } setBusy(true); getWebhookSecret().then(function(r){ setSecret(r && r.secret ? r.secret : ''); }).catch(function(){ emitToast(__('Kon de webhook-token niet laden.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); }); }
    function regenerate(){ if (busy) { return; } if (!window.confirm(__('Nieuwe webhook-token genereren? De huidige token werkt niet meer totdat SooCool is bijgewerkt met de nieuwe token.', 'soocool-for-woocommerce'))) { return; } setBusy(true); regenWebhookSecret().then(function(r){ setSecret(r && r.secret ? r.secret : ''); emitToast(__('Nieuwe webhook-token gegenereerd. Werk deze nu bij in SooCool.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ emitToast(__('Kon de webhook-token niet opnieuw genereren.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); }); }
    return el(Card, null,
      el('h3', null, __('Webhook (track & trace-terugkoppelingen)', 'soocool-for-woocommerce')),
      el('p', { className: 'soocool-field-help' }, __('Configureer SooCool om deze URL aan te roepen en de token plus HMAC-signature headers mee te sturen. De signature bestaat uit de timestamp, een punt en de ruwe body, ondertekend met de webhook-token. Query-token URL’s zijn alleen beschikbaar wanneer de expliciete fallback-filter is ingeschakeld.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-field-grid two' },
        el(c.TextControl, { label: __('Webhook-URL', 'soocool-for-woocommerce'), value: url, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Token-header', 'soocool-for-woocommerce'), value: header, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Timestamp-header', 'soocool-for-woocommerce'), value: timestampHeader, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Signature-header', 'soocool-for-woocommerce'), value: signatureHeader, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Optionele event-ID-header', 'soocool-for-woocommerce'), value: eventIdHeader, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Signature-formule', 'soocool-for-woocommerce'), value: 'hash_hmac(sha256, timestamp + "." + raw_body, webhook_token)', readOnly: true, onChange: function(){} })
      ),
      el('div', { className: 'soocool-actions' },
        el(c.Button, { variant: 'secondary', disabled: !url, onClick: function(){ copy(url, __('Webhook-URL gekopieerd.', 'soocool-for-woocommerce')); } }, __('URL kopiëren', 'soocool-for-woocommerce')),
        el(c.Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: reveal }, secret ? __('Token vernieuwen', 'soocool-for-woocommerce') : __('Token tonen', 'soocool-for-woocommerce')),
        el(c.Button, { variant: 'link', isDestructive: true, disabled: busy, onClick: regenerate }, __('Token opnieuw genereren', 'soocool-for-woocommerce'))
      ),
      secret ? el('div', { className: 'soocool-field-grid two' },
        el(c.TextControl, { label: __('Webhook-token', 'soocool-for-woocommerce'), value: secret, readOnly: true, onChange: function(){} }),
        el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'secondary', onClick: function(){ copy(secret, __('Webhook-token gekopieerd.', 'soocool-for-woocommerce')); } }, __('Token kopiëren', 'soocool-for-woocommerce')))
      ) : null
    );
  }
  function ResyncButton(){
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    function run(){ if (busy) { return; } if (!window.confirm(__('Dit probeert alle eerder mislukte orders opnieuw te synchroniseren. Doorgaan?', 'soocool-for-woocommerce'))) { return; } setBusy(true); resyncFailed().then(function(r){ emitToast(r && r.message ? r.message : __('Hersynchronisatie gestart.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ emitToast(__('Kon de hersynchronisatie niet starten.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); }); }
    return el(c.Button, { variant: 'secondary', className: 'soocool-danger-fill', isBusy: busy, disabled: busy, onClick: run }, __('Mislukte orders opnieuw synchroniseren', 'soocool-for-woocommerce'));
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
      saveSettings(settings).then(function(next){ setSettings(next); setSaved(true); emitToast(successMessage || __('Instellingen opgeslagen.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ setErrorMessage(failMessage); emitToast(failMessage, 'error'); }).finally(function(){ setSaving(false); });
    }
    return { settings: settings, setSettings: setSettings, loading: loading, saving: saving, saved: saved, errorMessage: errorMessage, save: save };
  }

  function ConnectionScreen(){
    var s = useSettings(__('Kon de SooCool-instellingen niet laden.', 'soocool-for-woocommerce'));
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
        emitToast(__('De opgeslagen API-key is ongeldig of bevat nog een gemaskeerde waarde. Plak de echte SooCool API-key en sla opnieuw op.', 'soocool-for-woocommerce'), 'error');
      }
    }, [s.loading, settings.api_key_status]);
    function upd(key, value){ var next = Object.assign({}, settings); next[key] = value; setSettings(next); }
    function ping(){
      if (testing) { return; }
      setTesting(true);
      setStatus({ message: __('Instellingen opslaan vóór het testen…', 'soocool-for-woocommerce'), tone: 'neutral' });
      saveSettings(settings)
        .then(function(next){
          setSettings(next);
          setStatus({ message: __('Verbinding testen…', 'soocool-for-woocommerce'), tone: 'neutral' });
          return testConnection();
        })
        .then(function(result){
          var message = result && result.message ? result.message : __('Verbinding succesvol.', 'soocool-for-woocommerce');
          var tone = 'success';
          setStatus({ message: message, tone: tone });
          emitToast(message, tone);
        })
        .catch(function(error){
          var message = error && error.message ? error.message : __('Verbinding mislukt. Controleer de API-key en basis-URL.', 'soocool-for-woocommerce');
          setStatus({ message: message, tone: 'error' });
          emitToast(message, 'error');
        })
        .finally(function(){ setTesting(false); });
    }
    return el(FieldGroup, { title: __('API-koppeling', 'soocool-for-woocommerce'), badge: __('Verplicht', 'soocool-for-woocommerce'), description: __('Koppel WooCommerce aan de juiste SooCool API-omgeving voordat orders worden verstuurd.', 'soocool-for-woocommerce') },
      s.loading ? el(Loading) : null,
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el(Card, null,
        el('div', { className: 'soocool-field-grid two' },
          el(c.SelectControl, { label: __('SooCool-omgeving', 'soocool-for-woocommerce'), value: settings.environment || 'test', options: [{ label: 'Test', value: 'test' }, { label: __('Productie', 'soocool-for-woocommerce'), value: 'production' }], onChange: function(v){ upd('environment', v); } }),
          el(c.TextControl, { type: 'password', label: __('SooCool API-key', 'soocool-for-woocommerce'), help: settings.api_key_present ? __('Er is een API-key opgeslagen en als bolletjes weergegeven. Klik in het veld om de bolletjes te wissen en plak alleen een nieuwe key wanneer je deze wilt vervangen.', 'soocool-for-woocommerce') : __('Plak de X-API-Key waarde voor de gekozen SooCool-omgeving.', 'soocool-for-woocommerce'), value: settings.api_key || settings.api_key_masked || '', onFocus: function(){ if (settings.api_key_masked && settings.api_key === settings.api_key_masked) { upd('api_key', ''); } }, onClick: function(){ if (settings.api_key_masked && settings.api_key === settings.api_key_masked) { upd('api_key', ''); } }, onChange: function(v){ upd('api_key', v); } }),
          el(c.TextControl, { type: 'url', label: __('SooCool test-API-URL', 'soocool-for-woocommerce'), help: __('Standaard worden alleen officiële SooCool API-hosts geaccepteerd.', 'soocool-for-woocommerce'), value: settings.test_base_url || '', onChange: function(v){ upd('test_base_url', v); } }),
          el(c.TextControl, { type: 'url', label: __('SooCool productie-API-URL', 'soocool-for-woocommerce'), help: __('Gebruik de officiële productie-API-host, tenzij SooCool een andere goedgekeurde host aanlevert.', 'soocool-for-woocommerce'), value: settings.production_base_url || '', onChange: function(v){ upd('production_base_url', v); } })
        ),
        null
      ),
      status ? el(Status, { tone: status.tone, message: status.message }) : null,
      el('div', { className: 'soocool-actions' }, el(SaveButton, { isSaving: s.saving, onClick: function(){ s.save(__('Kon de instellingen niet opslaan. Controleer de ingevulde waarden.', 'soocool-for-woocommerce'), __('API-instellingen opgeslagen.', 'soocool-for-woocommerce')); } }), el(c.Button, { variant: 'secondary', isBusy: testing, disabled: s.saving || testing || s.loading, onClick: ping }, __('Verbinding testen', 'soocool-for-woocommerce')), settings.environment === 'test' ? el(c.Button, { variant: 'link', href: 'https://orders-test.soocool.nl:8443/#/authenticate/login', target: '_blank', rel: 'noreferrer noopener' }, __('SooCool testportaal openen', 'soocool-for-woocommerce')) : null),
    );
  }

  function MappingScreen(){
    var s = useSettings(__('Kon de koppeling-instellingen niet laden.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    function upd(key, value){ var next = Object.assign({}, settings); next[key] = value; setSettings(next); }
    return el(FieldGroup, { title: __('Ophalen & bezorgen', 'soocool-for-woocommerce'), badge: __('Orders', 'soocool-for-woocommerce'), description: __('Configureer ophaalgegevens, bezorgplanning en fallback-goederengegevens voor SooCool-orders.', 'soocool-for-woocommerce') },
      s.loading ? el(Loading) : null,
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el(Card, { soft: true }, el('div', { className: 'soocool-compact-row' },
        el(c.ToggleControl, { label: __('Ophaaltaak aanmaken vóór bezorging', 'soocool-for-woocommerce'), help: __('Schakel dit alleen in wanneer ophaaltaken met SooCool zijn afgesproken. Volgens de API-documentatie mogen ophaaltaken alleen in overleg worden gebruikt.', 'soocool-for-woocommerce'), checked: !!settings.enable_pickup, onChange: function(v){ upd('enable_pickup', v); } }),
        el(c.TextControl, { label: __('Prefix voor WooCommerce orderreferentie', 'soocool-for-woocommerce'), help: __('Optionele prefix vóór het WooCommerce ordernummer, bijvoorbeeld TEST-.', 'soocool-for-woocommerce'), value: settings.order_reference_prefix || '', onChange: function(v){ upd('order_reference_prefix', v); } })
      )),
      el('div', { className: 'soocool-mapping-split' },
        el('div', { className: 'soocool-mapping-column soocool-mapping-column-left' },
          el(Card, null,
            el('h3', null, __('Ophaallocatie', 'soocool-for-woocommerce')),
            el('div', { className: 'soocool-field-grid two' },
              el(c.TextControl, { label: __('Ophaalbedrijf', 'soocool-for-woocommerce'), value: settings.pickup_company || '', onChange: function(v){ upd('pickup_company', v); } }),
              el(c.TextControl, { label: __('Contactpersoon ophaaladres', 'soocool-for-woocommerce'), value: settings.pickup_contact_name || '', onChange: function(v){ upd('pickup_contact_name', v); } }),
              el(c.TextControl, { type: 'email', label: __('E-mailadres ophaaladres', 'soocool-for-woocommerce'), value: settings.pickup_email || '', onChange: function(v){ upd('pickup_email', v); } }),
              el(c.TextControl, { label: __('Telefoon/mobiel ophaaladres', 'soocool-for-woocommerce'), value: settings.pickup_phone || '', onChange: function(v){ upd('pickup_phone', v); } }),
              el(c.TextControl, { label: __('Straat ophaaladres', 'soocool-for-woocommerce'), value: settings.pickup_street || '', onChange: function(v){ upd('pickup_street', v); } }),
              el(c.TextControl, { label: __('Huisnummer ophaaladres', 'soocool-for-woocommerce'), value: settings.pickup_house_number || '', onChange: function(v){ upd('pickup_house_number', v); } }),
              el(c.TextControl, { label: __('Postcode ophaaladres', 'soocool-for-woocommerce'), value: settings.pickup_postal_code || '', onChange: function(v){ upd('pickup_postal_code', v); } }),
              el(c.TextControl, { label: __('Plaats ophaaladres', 'soocool-for-woocommerce'), value: settings.pickup_city || '', onChange: function(v){ upd('pickup_city', v); } }),
              el(c.TextControl, { className: 'soocool-field-full', label: __('Landcode ophaaladres', 'soocool-for-woocommerce'), value: settings.pickup_country || 'NL', onChange: function(v){ upd('pickup_country', v); } })
            )
          ),
          el(WebhookCard, { settings: settings })
        ),
        el('div', { className: 'soocool-mapping-column soocool-mapping-column-right' },
          el(Card, null,
            el('h3', null, __('Planning & goederen', 'soocool-for-woocommerce')),
            el('div', { className: 'soocool-field-grid two' },
              el(c.TextControl, { type: 'number', min: 0, max: 30, label: __('Ophaaldatum-offset in dagen', 'soocool-for-woocommerce'), value: String(settings.pickup_days_offset == null ? 1 : settings.pickup_days_offset), onChange: function(v){ upd('pickup_days_offset', Number(v)); } }),
              el(c.TextControl, { type: 'number', min: 0, max: 30, label: __('Bezorgdatum-offset in dagen', 'soocool-for-woocommerce'), value: String(settings.delivery_days_offset == null ? 2 : settings.delivery_days_offset), onChange: function(v){ upd('delivery_days_offset', Number(v)); } }),
              el(c.TextControl, { type: 'time', label: __('Ophaalvenster start', 'soocool-for-woocommerce'), value: settings.pickup_time_from || '', onChange: function(v){ upd('pickup_time_from', v); } }),
              el(c.TextControl, { type: 'time', label: __('Ophaalvenster eindigt', 'soocool-for-woocommerce'), value: settings.pickup_time_to || '', onChange: function(v){ upd('pickup_time_to', v); } }),
              el(c.TextControl, { type: 'time', label: __('Bezorgvenster start', 'soocool-for-woocommerce'), help: __('SooCool vereist voor deze koppeling bezorgtaken met exact 08:00-18:00.', 'soocool-for-woocommerce'), value: '08:00', disabled: true, onChange: function(){} }),
              el(c.TextControl, { type: 'time', label: __('Bezorgvenster eindigt', 'soocool-for-woocommerce'), help: __('SooCool vereist voor deze koppeling bezorgtaken met exact 08:00-18:00.', 'soocool-for-woocommerce'), value: '18:00', disabled: true, onChange: function(){} })
            ),
            el('div', { className: 'soocool-field-grid two' },
              el(c.TextControl, { label: __('Fallback goederenomschrijving', 'soocool-for-woocommerce'), value: settings.goods_description_fallback || '', onChange: function(v){ upd('goods_description_fallback', v); } }),
              el(c.TextControl, { label: __('SooCool packagingType', 'soocool-for-woocommerce'), help: __('Standaard: box. Wijzig dit wanneer SooCool een andere packagingType-waarde verwacht.', 'soocool-for-woocommerce'), value: settings.packaging_type || 'box', onChange: function(v){ upd('packaging_type', v); } }),
              el(c.SelectControl, { label: __('Transportvereiste', 'soocool-for-woocommerce'), help: __('Wordt verstuurd als goods[].transportRequirements. Standaard: cooled.', 'soocool-for-woocommerce'), value: settings.temperature_regime || 'cooled', options: [{ label: __('Gekoeld', 'soocool-for-woocommerce'), value: 'cooled' }, { label: __('Bevroren', 'soocool-for-woocommerce'), value: 'frozen' }, { label: __('Omgevingstemperatuur', 'soocool-for-woocommerce'), value: 'ambient' }], onChange: function(v){ upd('temperature_regime', v); } }),
              el(c.TextControl, { type: 'number', min: 1, label: __('Pakketbreedte', 'soocool-for-woocommerce'), help: __('Wordt verstuurd als goods[].dimensions.width.', 'soocool-for-woocommerce'), value: String(settings.package_width == null ? 60 : settings.package_width), onChange: function(v){ upd('package_width', Number(v)); } }),
              el(c.TextControl, { type: 'number', min: 1, label: __('Pakketdiepte', 'soocool-for-woocommerce'), help: __('Wordt verstuurd als goods[].dimensions.depth.', 'soocool-for-woocommerce'), value: String(settings.package_depth == null ? 40 : settings.package_depth), onChange: function(v){ upd('package_depth', Number(v)); } }),
              el(c.TextControl, { type: 'number', min: 1, label: __('Pakkethoogte', 'soocool-for-woocommerce'), help: __('Wordt verstuurd als goods[].dimensions.height.', 'soocool-for-woocommerce'), value: String(settings.package_height == null ? 11 : settings.package_height), onChange: function(v){ upd('package_height', Number(v)); } }),
              el(c.TextControl, { className: 'soocool-field-full', type: 'number', min: 1, label: __('Pakketgewicht', 'soocool-for-woocommerce'), help: __('Wordt verstuurd als goods[].weight.', 'soocool-for-woocommerce'), value: String(settings.package_weight == null ? 1600 : settings.package_weight), onChange: function(v){ upd('package_weight', Number(v)); } })
            ),
            el(c.TextControl, { type: 'url', label: __('SooCool webhook-URL', 'soocool-for-woocommerce'), help: __('Optionele callback-URL die met de SooCool-order wordt meegestuurd. Laat leeg om de plugin-ontvanger te gebruiken. Header-token plus HMAC-signature-authenticatie is standaard verplicht; legacy fallbacks vereisen expliciete filters.', 'soocool-for-woocommerce'), value: settings.webhook_url || '', onChange: function(v){ upd('webhook_url', v); } })
          )
        )
      ),
      el(Note, null, __('Ophalen is optioneel. Laat dit uitgeschakeld tenzij SooCool heeft bevestigd dat je account ophaaltaken mag versturen. Orders met alleen bezorging bevatten nog steeds de verplichte bezorgtaak en goederen.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions' }, el(SaveButton, { isSaving: s.saving, onClick: function(){ s.save(__('Kon de koppeling-instellingen niet opslaan. Controleer verplichte ophaal- en bezorgvelden.', 'soocool-for-woocommerce'), __('Ophaal- en bezorginstellingen opgeslagen.', 'soocool-for-woocommerce')); } }, __('Ophalen & bezorgen opslaan', 'soocool-for-woocommerce')))
    );
  }


  function DeliveryDaysScreen(){
    var s = useSettings(__('Kon de bezorgdagen-instellingen niet laden.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    var openState = useState({ 0: true });
    var openCards = openState[0];
    var setOpenCards = openState[1];
    var slotEditState = useState({});
    var openSlots = slotEditState[0];
    var setOpenSlots = slotEditState[1];
    var expandedListState = useState({});
    var expandedSlotLists = expandedListState[0];
    var setExpandedSlotLists = expandedListState[1];
    var weekdayByValue = soocoolWeekdayOptions.reduce(function(acc, item){ acc[item.value] = item.label; return acc; }, {});
    function defaultSchedule(){
      var slots = defaultDeliveryTimeSlots();
      return defaultDeliveryRules().map(function(rule, ruleIndex){
        return Object.assign({}, rule, { sort_order: (ruleIndex + 1) * 10, slots: slots.filter(function(slot){ return slot.weekdays.indexOf(rule.delivery_weekday) !== -1; }).map(function(slot){ return Object.assign({}, slot, { weekdays: [rule.delivery_weekday] }); }) });
      });
    }
    function normalizeSchedule(){
      var schedule = settings && Array.isArray(settings.checkout_delivery_schedule) ? settings.checkout_delivery_schedule : [];
      if (!schedule.length) {
        var rules = normalizedDeliveryRules(settings);
        var slots = normalizedDeliveryTimeSlots(settings);
        schedule = rules.map(function(rule, ruleIndex){
          return Object.assign({}, rule, {
            sort_order: rule.sort_order == null ? (ruleIndex + 1) * 10 : rule.sort_order,
            slots: slots.filter(function(slot){ return !Array.isArray(slot.weekdays) || slot.weekdays.indexOf(rule.delivery_weekday) !== -1; }).map(function(slot){ return Object.assign({}, slot, { weekdays: [rule.delivery_weekday] }); })
          });
        });
      }
      if (!schedule.length) { schedule = defaultSchedule(); }
      return schedule.map(function(rule, ruleIndex){
        var deliveryWeekday = rule.delivery_weekday || rule.delivery_day || 'monday';
        var slots = Array.isArray(rule.slots) && rule.slots.length ? rule.slots : defaultDeliveryTimeSlots().filter(function(slot){ return slot.weekdays.indexOf(deliveryWeekday) !== -1; });
        return Object.assign({ enabled: true, delivery_weekday: 'monday', cutoff_weekday: 'saturday', cutoff_time: '13:00', sort_order: (ruleIndex + 1) * 10 }, rule || {}, {
          delivery_weekday: deliveryWeekday,
          cutoff_weekday: rule.cutoff_weekday || rule.cutoff_day || 'saturday',
          slots: slots.map(function(slot, slotIndex){ return Object.assign({ enabled: true, label: '', time_from: '14:00', time_to: '18:00', cutoff_time: '14:00', sort_order: (slotIndex + 1) * 10 }, slot || {}, { weekdays: [deliveryWeekday] }); })
        });
      });
    }
    function enabledRuleCount(schedule){ return schedule.filter(function(rule){ return rule.enabled !== false; }).length; }
    function enabledSlotCount(slots){ return slots.filter(function(slot){ return slot.enabled !== false; }).length; }
    function scheduleToRules(schedule){
      return schedule.map(function(rule){ return { enabled: rule.enabled !== false, delivery_weekday: rule.delivery_weekday || 'monday', cutoff_weekday: rule.cutoff_weekday || 'saturday', cutoff_time: rule.cutoff_time || '13:00' }; });
    }
    function scheduleToSlots(schedule){
      var slots = [];
      schedule.forEach(function(rule){
        (Array.isArray(rule.slots) ? rule.slots : []).forEach(function(slot){ slots.push(Object.assign({}, slot, { weekdays: [rule.delivery_weekday || 'monday'] })); });
      });
      return slots.length ? slots : defaultDeliveryTimeSlots();
    }
    function setSchedule(schedule){
      var next = Object.assign({}, settings);
      next.checkout_delivery_schedule = schedule;
      next.checkout_delivery_rules = scheduleToRules(schedule);
      next.checkout_delivery_time_slots = scheduleToSlots(schedule);
      setSettings(next);
    }
    function createSlot(rule){
      var slots = Array.isArray(rule.slots) ? rule.slots : [];
      return { enabled: true, label: '', time_from: '14:00', time_to: '18:00', cutoff_time: '14:00', sort_order: (slots.length + 1) * 10, weekdays: [rule.delivery_weekday || 'monday'] };
    }
    function addRule(){
      var schedule = normalizeSchedule().slice();
      var newIndex = schedule.length;
      schedule.push({ enabled: true, delivery_weekday: 'monday', cutoff_weekday: 'saturday', cutoff_time: '13:00', sort_order: (newIndex + 1) * 10, slots: [createSlot({ delivery_weekday: 'monday', slots: [] })] });
      setSchedule(schedule);
      var next = {};
      next[newIndex] = true;
      setOpenCards(next);
    }
    function removeRule(index){
      var schedule = normalizeSchedule().filter(function(rule, ruleIndex){ return ruleIndex !== index; });
      if (!schedule.length) { return; }
      if (!enabledRuleCount(schedule)) { schedule[0] = Object.assign({}, schedule[0], { enabled: true }); }
      setSchedule(schedule);
    }
    function updateRule(index, key, value){
      var schedule = normalizeSchedule().slice();
      var rule = Object.assign({}, schedule[index] || {});
      if (key === 'enabled' && value === false && rule.enabled !== false && enabledRuleCount(schedule) <= 1) { return; }
      rule[key] = value;
      if (key === 'delivery_weekday') {
        rule.slots = (Array.isArray(rule.slots) ? rule.slots : []).map(function(slot){ return Object.assign({}, slot, { weekdays: [value] }); });
      }
      schedule[index] = rule;
      setSchedule(schedule);
    }
    function addSlot(ruleIndex){
      var schedule = normalizeSchedule().slice();
      var rule = Object.assign({}, schedule[ruleIndex] || {});
      rule.slots = (Array.isArray(rule.slots) ? rule.slots.slice() : []);
      rule.slots.push(createSlot(rule));
      schedule[ruleIndex] = rule;
      setSchedule(schedule);
    }
    function removeSlot(ruleIndex, slotIndex){
      var schedule = normalizeSchedule().slice();
      var rule = Object.assign({}, schedule[ruleIndex] || {});
      var slots = (Array.isArray(rule.slots) ? rule.slots : []).filter(function(slot, index){ return index !== slotIndex; });
      if (!slots.length) { return; }
      if (!enabledSlotCount(slots)) { slots[0] = Object.assign({}, slots[0], { enabled: true }); }
      rule.slots = slots;
      schedule[ruleIndex] = rule;
      setSchedule(schedule);
    }
    function updateSlot(ruleIndex, slotIndex, key, value){
      var schedule = normalizeSchedule().slice();
      var rule = Object.assign({}, schedule[ruleIndex] || {});
      var slots = Array.isArray(rule.slots) ? rule.slots.slice() : [];
      var slot = Object.assign({}, slots[slotIndex] || {});
      if (key === 'enabled' && value === false && slot.enabled !== false && enabledSlotCount(slots) <= 1) { return; }
      slot[key] = value;
      slot.weekdays = [rule.delivery_weekday || 'monday'];
      slots[slotIndex] = slot;
      rule.slots = slots;
      schedule[ruleIndex] = rule;
      setSchedule(schedule);
    }
    function slotKey(ruleIndex, slotIndex){ return String(ruleIndex) + '-' + String(slotIndex); }
    function toggleCard(index){
      var next = {};
      if (openCards[index] !== true) {
        next[index] = true;
      }
      setOpenCards(next);
    }
    function toggleSlot(ruleIndex, slotIndex){
      var key = slotKey(ruleIndex, slotIndex);
      var next = Object.assign({}, openSlots);
      next[key] = next[key] !== true;
      setOpenSlots(next);
    }
    function toggleSlotList(ruleIndex){
      var next = Object.assign({}, expandedSlotLists);
      next[ruleIndex] = next[ruleIndex] !== true;
      setExpandedSlotLists(next);
    }
    var schedule = normalizeSchedule();
    return el(FieldGroup, { title: __('Bezorgschema', 'soocool-for-woocommerce'), badge: __('Checkout', 'soocool-for-woocommerce'), description: __('Beheer per bezorgdag de cut-off en tijdsloten voor de klassieke WooCommerce checkout. Checkout Blocks worden in deze release niet ondersteund.', 'soocool-for-woocommerce') },
      s.loading ? el(Loading) : null,
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el('div', { className: 'soocool-delivery-dashboard' },
        el('div', { className: 'soocool-delivery-overview' },
          el('div', null,
            el('h3', null, __('Checkout bezorgmoment', 'soocool-for-woocommerce')),
            el('p', { className: 'soocool-muted' }, __('Klanten kiezen eerst een bezorgdag en daarna een tijdslot. Het gekozen tijdslot wordt opgeslagen bij de order; de SooCool API-delivery window blijft 08:00-18:00 zolang deze koppeling dat vereist.', 'soocool-for-woocommerce'))
          )
        ),
        el('section', { className: 'soocool-delivery-section' },
          el('div', { className: 'soocool-delivery-section__header' }, el('h3', null, __('Algemene instellingen', 'soocool-for-woocommerce'))),
          el('div', { className: 'soocool-delivery-settings-list' },
            el('div', { className: 'soocool-delivery-setting-row soocool-delivery-setting-row--toggle' },
              el('div', { className: 'soocool-delivery-setting-copy' }, el('h4', null, __('Activeer bezorgkeuze in de checkout', 'soocool-for-woocommerce')), el('p', null, __('Schakel dit uit om terug te vallen op de bestaande delivery offset.', 'soocool-for-woocommerce'))),
              el('div', { className: 'soocool-delivery-setting-control soocool-delivery-setting-control--toggle' }, el(c.ToggleControl, { label: __('Activeer bezorgkeuze in de checkout', 'soocool-for-woocommerce'), checked: settings.checkout_delivery_enabled !== false, onChange: function(v){ var next = Object.assign({}, settings, { checkout_delivery_enabled: v }); setSettings(next); } }))
            ),
            el('div', { className: 'soocool-delivery-setting-row soocool-delivery-setting-row--toggle' },
              el('div', { className: 'soocool-delivery-setting-copy' }, el('h4', null, __('Verlopen tijdsloten verbergen', 'soocool-for-woocommerce')), el('p', null, __('Aanbevolen: checkout blijft rustiger. Zet uit om verlopen tijdsloten subtiel als niet beschikbaar te tonen.', 'soocool-for-woocommerce'))),
              el('div', { className: 'soocool-delivery-setting-control soocool-delivery-setting-control--toggle' }, el(c.ToggleControl, { label: __('Verlopen tijdsloten verbergen', 'soocool-for-woocommerce'), checked: settings.checkout_delivery_hide_unavailable_slots !== false, onChange: function(v){ var next = Object.assign({}, settings, { checkout_delivery_hide_unavailable_slots: v }); setSettings(next); } }))
            ),
            el('div', { className: 'soocool-delivery-setting-row soocool-delivery-setting-row--number' },
              el('div', { className: 'soocool-delivery-setting-copy' }, el('h4', null, __('Aantal dagen vooruit tonen', 'soocool-for-woocommerce')), el('p', null, __('Bepaalt hoeveel toekomstige bezorgdagen zichtbaar zijn in checkout.', 'soocool-for-woocommerce'))),
              el('div', { className: 'soocool-delivery-setting-control soocool-delivery-setting-control--number' }, el(c.TextControl, { type: 'number', min: 7, max: 60, label: __('Aantal dagen vooruit tonen', 'soocool-for-woocommerce'), hideLabelFromVision: true, value: String(settings.checkout_delivery_days_ahead == null ? 14 : settings.checkout_delivery_days_ahead), onChange: function(v){ var next = Object.assign({}, settings, { checkout_delivery_days_ahead: Number(v) }); setSettings(next); } }))
            ),
            el('div', { className: 'soocool-delivery-setting-row soocool-delivery-setting-row--holidays' },
              el('div', { className: 'soocool-delivery-setting-copy' }, el('h4', null, __('Geblokkeerde datums / feestdagen', 'soocool-for-woocommerce')), el('p', null, __('Komma-gescheiden datums in YYYY-MM-DD, bijvoorbeeld 2026-12-25, 2026-12-26.', 'soocool-for-woocommerce'))),
              el('div', { className: 'soocool-delivery-setting-control soocool-delivery-setting-control--holidays' }, el(c.TextControl, { label: __('Geblokkeerde datums / feestdagen', 'soocool-for-woocommerce'), hideLabelFromVision: true, value: settings.checkout_delivery_holidays || '', onChange: function(v){ var next = Object.assign({}, settings, { checkout_delivery_holidays: v }); setSettings(next); } }))
            )
          )
        ),
        el('section', { className: 'soocool-delivery-section soocool-delivery-section--schedule' },
          el('div', { className: 'soocool-delivery-section__header soocool-delivery-section__header--split' },
            el('div', null, el('h3', null, __('Bezorgschema', 'soocool-for-woocommerce')), el('p', { className: 'soocool-field-help' }, __('Elke bezorgdag beheert nu zijn eigen cut-off en tijdsloten. Dit voorkomt losse tabellen en maakt beheer overzichtelijker.', 'soocool-for-woocommerce'))),
            el(c.Button, { variant: 'secondary', onClick: addRule, className: 'soocool-delivery-add-rule' }, __('+ Bezorgdag toevoegen', 'soocool-for-woocommerce'))
          ),
          el('div', { className: 'soocool-delivery-schedule-cards' },
            schedule.map(function(rule, ruleIndex){
              var isOpen = openCards[ruleIndex] === true;
              var panelId = 'soocool-delivery-schedule-panel-' + ruleIndex;
              var buttonId = 'soocool-delivery-schedule-button-' + ruleIndex;
              var slots = Array.isArray(rule.slots) ? rule.slots : [];
              var activeSlots = slots.filter(function(slot){ return slot.enabled !== false; }).length;
              return el('article', { className: 'soocool-delivery-schedule-card' + (rule.enabled === false ? ' is-disabled' : ''), key: ruleIndex },
                el('div', { className: 'soocool-delivery-schedule-card__top' },
                  el('button', { type: 'button', id: buttonId, className: 'soocool-delivery-schedule-card__toggle', 'aria-expanded': isOpen ? 'true' : 'false', 'aria-controls': panelId, onClick: function(){ toggleCard(ruleIndex); } },
                    el('span', { className: 'soocool-delivery-schedule-card__summary' },
                      el('span', { className: 'soocool-delivery-schedule-card__title' }, weekdayByValue[rule.delivery_weekday] || rule.delivery_weekday),
                      el('span', { className: 'soocool-delivery-schedule-card__meta' }, __('Bestelbaar t/m', 'soocool-for-woocommerce') + ' ' + (weekdayByValue[rule.cutoff_weekday] || rule.cutoff_weekday) + ' ' + (rule.cutoff_time || '13:00')),
                      el('span', { className: 'soocool-delivery-schedule-card__count' }, activeSlots + ' ' + __('actieve tijdsloten', 'soocool-for-woocommerce'))
                    ),
                    !isOpen ? el('span', { className: 'soocool-delivery-schedule-card__edit-text' }, __('Bewerken', 'soocool-for-woocommerce')) : null,
                    el('span', { className: 'dashicons ' + (isOpen ? 'dashicons-arrow-up-alt2' : 'dashicons-edit'), 'aria-hidden': true })
                  ),
                  el('div', { className: 'soocool-delivery-schedule-card__actions' },
                    el(c.ToggleControl, { label: __('Actief', 'soocool-for-woocommerce'), checked: rule.enabled !== false, onChange: function(v){ updateRule(ruleIndex, 'enabled', v); } }),
                    el(c.Button, { variant: 'secondary', isDestructive: true, disabled: schedule.length <= 1, onClick: function(){ removeRule(ruleIndex); }, 'aria-label': __('Verwijder bezorgdag', 'soocool-for-woocommerce') }, el('span', { className: 'dashicons dashicons-trash', 'aria-hidden': true }))
                  )
                ),
                isOpen ? el('div', { id: panelId, className: 'soocool-delivery-schedule-card__panel', role: 'region', 'aria-labelledby': buttonId },
                  el('div', { className: 'soocool-delivery-schedule-fields' },
                    el(c.SelectControl, { label: __('Bezorgdag', 'soocool-for-woocommerce'), value: rule.delivery_weekday || 'monday', options: soocoolWeekdayOptions, onChange: function(v){ updateRule(ruleIndex, 'delivery_weekday', v); } }),
                    el(c.SelectControl, { label: __('Cut-off dag', 'soocool-for-woocommerce'), value: rule.cutoff_weekday || 'saturday', options: soocoolWeekdayOptions, onChange: function(v){ updateRule(ruleIndex, 'cutoff_weekday', v); } }),
                    el(c.TextControl, { type: 'time', label: __('Cut-off tijd', 'soocool-for-woocommerce'), value: rule.cutoff_time || '13:00', onChange: function(v){ updateRule(ruleIndex, 'cutoff_time', v); } }),
                    el(c.TextControl, { type: 'number', min: 0, step: 1, label: __('Volgorde', 'soocool-for-woocommerce'), value: String(rule.sort_order == null ? (ruleIndex + 1) * 10 : rule.sort_order), onChange: function(v){ updateRule(ruleIndex, 'sort_order', parseInt(v, 10) || 0); } })
                  ),
                  el('div', { className: 'soocool-delivery-schedule-slots' },
                    el('div', { className: 'soocool-delivery-schedule-slots__header' },
                      el('h4', null, __('Tijdsloten', 'soocool-for-woocommerce')),
                      el(c.Button, { variant: 'tertiary', onClick: function(){ addSlot(ruleIndex); } }, __('+ Tijdslot toevoegen', 'soocool-for-woocommerce'))
                    ),
                    (function(){
                      var allSlots = Array.isArray(rule.slots) ? rule.slots : [];
                      var showAllSlots = expandedSlotLists[ruleIndex] === true;
                      var visibleSlots = showAllSlots ? allSlots : allSlots.slice(0, 4);
                      return el(Fragment, null,
                        el('div', { id: panelId + '-slots', className: 'soocool-delivery-schedule-slots__list' }, visibleSlots.map(function(slot, slotIndex){
                      var isSlotOpen = openSlots[slotKey(ruleIndex, slotIndex)] === true;
                      var slotPanelId = 'soocool-delivery-slot-panel-' + ruleIndex + '-' + slotIndex;
                      var slotButtonId = 'soocool-delivery-slot-button-' + ruleIndex + '-' + slotIndex;
                      return el('div', { className: 'soocool-delivery-schedule-slot' + (slot.enabled === false ? ' is-disabled' : '') + (isSlotOpen ? ' is-open' : ''), key: slotIndex },
                        el('div', { className: 'soocool-delivery-schedule-slot__summary' },
                          el('div', { className: 'soocool-delivery-schedule-slot__main' },
                            el('strong', null, (slot.time_from || '14:00') + '-' + (slot.time_to || '18:00')),
                            slot.label ? el('span', { className: 'soocool-delivery-schedule-slot__label' }, slot.label) : null
                          ),
                          el('span', { className: 'soocool-delivery-schedule-slot__meta' }, __('Cut-off', 'soocool-for-woocommerce') + ' ' + (slot.cutoff_time || slot.time_from || '14:00')),
                          el('span', { className: 'soocool-delivery-schedule-slot__status' }, slot.enabled === false ? __('Uitgeschakeld', 'soocool-for-woocommerce') : __('Actief', 'soocool-for-woocommerce')),
                          el('div', { className: 'soocool-delivery-schedule-slot__actions' },
                            el(c.Button, { id: slotButtonId, variant: 'tertiary', className: 'soocool-delivery-slot-edit' + (isSlotOpen ? ' is-open' : ''), onClick: function(){ toggleSlot(ruleIndex, slotIndex); }, 'aria-expanded': isSlotOpen ? 'true' : 'false', 'aria-controls': slotPanelId, 'aria-label': isSlotOpen ? __('Sluit tijdslotdetails', 'soocool-for-woocommerce') : __('Bewerk tijdslot', 'soocool-for-woocommerce') }, el('span', { className: 'dashicons ' + (isSlotOpen ? 'dashicons-arrow-up-alt2' : 'dashicons-edit'), 'aria-hidden': true }), el('span', { className: 'screen-reader-text' }, isSlotOpen ? __('Sluiten', 'soocool-for-woocommerce') : __('Bewerken', 'soocool-for-woocommerce'))),
                            el(c.Button, { variant: 'secondary', isDestructive: true, disabled: (Array.isArray(rule.slots) ? rule.slots.length : 0) <= 1, onClick: function(){ removeSlot(ruleIndex, slotIndex); }, className: 'soocool-delivery-slot-remove', 'aria-label': __('Verwijder tijdslot', 'soocool-for-woocommerce') }, el('span', { className: 'dashicons dashicons-trash', 'aria-hidden': true }))
                          )
                        ),
                        isSlotOpen ? el('div', { id: slotPanelId, className: 'soocool-delivery-schedule-slot__details', role: 'region', 'aria-labelledby': slotButtonId },
                          el(c.ToggleControl, { label: __('Actief', 'soocool-for-woocommerce'), checked: slot.enabled !== false, onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'enabled', v); } }),
                          el(c.TextControl, { label: __('Label', 'soocool-for-woocommerce'), placeholder: __('Optioneel', 'soocool-for-woocommerce'), value: slot.label || '', onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'label', v); } }),
                          el(c.TextControl, { type: 'time', label: __('Van', 'soocool-for-woocommerce'), value: slot.time_from || '14:00', onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'time_from', v); } }),
                          el(c.TextControl, { type: 'time', label: __('Tot', 'soocool-for-woocommerce'), value: slot.time_to || '18:00', onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'time_to', v); } }),
                          el(c.TextControl, { type: 'time', label: __('Cut-off', 'soocool-for-woocommerce'), value: slot.cutoff_time || slot.time_from || '14:00', onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'cutoff_time', v); } }),
                          el(c.TextControl, { type: 'number', min: 0, step: 1, label: __('Volgorde', 'soocool-for-woocommerce'), value: String(slot.sort_order == null ? (slotIndex + 1) * 10 : slot.sort_order), onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'sort_order', parseInt(v, 10) || 0); } })
                        ) : null
                      );
                    })),
                    allSlots.length > 4 ? el(c.Button, { variant: 'secondary', className: 'soocool-delivery-slots-more', onClick: function(){ toggleSlotList(ruleIndex); }, 'aria-expanded': showAllSlots ? 'true' : 'false', 'aria-controls': panelId + '-slots' }, showAllSlots ? __('Minder tijdsloten tonen', 'soocool-for-woocommerce') : __('Alle tijdsloten tonen', 'soocool-for-woocommerce')) : null
                    );
                    })()
                  )
                ) : null
              );
            })
          )
        ),
        el('div', { className: 'soocool-delivery-footer' },
          el(SaveButton, { isSaving: s.saving, onClick: function(){ s.save(__('Kon het bezorgschema niet opslaan. Controleer de ingevulde dagen en tijden.', 'soocool-for-woocommerce'), __('Bezorgschema opgeslagen.', 'soocool-for-woocommerce')); } }, __('Bezorgschema opslaan', 'soocool-for-woocommerce'))
        )
      )
    );
  }


  function AutomationScreen(){
    var s = useSettings(__('Kon de automatiseringsinstellingen niet laden.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    function upd(key, value){ var next = Object.assign({}, settings); next[key] = value; setSettings(next); }
    return el(FieldGroup, { title: __('Automatisering', 'soocool-for-woocommerce'), badge: __('Optioneel', 'soocool-for-woocommerce'), description: __('Automatisch verzenden staat standaard uit. Houd handmatige synchronisatie beschikbaar tijdens staging.', 'soocool-for-woocommerce') },
      s.loading ? el(Loading) : null,
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el(Card, null,
        el('div', { className: 'soocool-field-grid two' },
          el(c.ToggleControl, { label: __('Geschikte orders automatisch naar SooCool versturen', 'soocool-for-woocommerce'), help: __('Verstuurt passende WooCommerce-orders automatisch zodra ze de gekozen status bereiken.', 'soocool-for-woocommerce'), checked: !!settings.auto_submit_enabled, onChange: function(v){ upd('auto_submit_enabled', v); } }),
          el(c.ToggleControl, { label: __('Handmatig opnieuw versturen van gesynchroniseerde orders toestaan', 'soocool-for-woocommerce'), help: __('Laat dit uitgeschakeld, tenzij SooCool om een vervangende order of staging-hertest vraagt.', 'soocool-for-woocommerce'), checked: !!settings.allow_resubmit, onChange: function(v){ upd('allow_resubmit', v); } }),
          el(c.SelectControl, { label: __('Orderstatus voor automatisch verzenden', 'soocool-for-woocommerce'), value: settings.auto_submit_status || 'processing', options: [{ label: __('In behandeling', 'soocool-for-woocommerce'), value: 'processing' }, { label: __('Afgerond', 'soocool-for-woocommerce'), value: 'completed' }, { label: __('In de wacht', 'soocool-for-woocommerce'), value: 'on-hold' }], onChange: function(v){ upd('auto_submit_status', v); } }),
          el(c.TextControl, { type: 'number', min: 20, max: 500, label: __('Logretentielimiet', 'soocool-for-woocommerce'), value: String(settings.log_retention == null ? 100 : settings.log_retention), onChange: function(v){ upd('log_retention', Number(v)); } })
        )
      ),
      el(Card, { soft: true },
        el('h3', null, __('Onderhoud', 'soocool-for-woocommerce')),
        el('p', { className: 'soocool-field-help' }, __('Verstuur elke order opnieuw waarvan de laatste SooCool-synchronisatie is mislukt. Grote batches draaien op de achtergrond via Action Scheduler.', 'soocool-for-woocommerce')),
        el('div', { className: 'soocool-actions' }, el(ResyncButton))
      ),
      el(Note, null, __('Handmatig versturen blijft beschikbaar vanuit het WooCommerce orderscherm.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions' }, el(SaveButton, { isSaving: s.saving, onClick: function(){ s.save(__('Kon de automatiseringsinstellingen niet opslaan.', 'soocool-for-woocommerce'), __('Automatiseringsinstellingen opgeslagen.', 'soocool-for-woocommerce')); } }, __('Automatisering opslaan', 'soocool-for-woocommerce')))
    );
  }

  function LabelsScreen(){
    var s = useSettings(__('Kon de labelinstellingen niet laden.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    return el(FieldGroup, { title: __('Verzendlabels', 'soocool-for-woocommerce'), badge: __('PDF', 'soocool-for-woocommerce'), description: __('Kies het standaard PDF-formaat voor het downloaden van SooCool verzendlabels vanuit een order.', 'soocool-for-woocommerce') },
      s.loading ? el(Loading) : null,
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el(Card, null, el('div', { className: 'soocool-field-grid two' }, el(c.SelectControl, { label: __('Standaard labelformaat', 'soocool-for-woocommerce'), value: settings.label_output || 'a6', options: [{ label: __('A6 enkel label', 'soocool-for-woocommerce'), value: 'a6' }, { label: __('Gebundeld A4-vel', 'soocool-for-woocommerce'), value: 'collated_a4' }], onChange: function(v){ setSettings(Object.assign({}, settings, { label_output: v })); } }))),
      el('div', { className: 'soocool-actions' }, el(SaveButton, { isSaving: s.saving, onClick: function(){ s.save(__('Kon de labelinstellingen niet opslaan.', 'soocool-for-woocommerce'), __('Verzendlabelinstellingen opgeslagen.', 'soocool-for-woocommerce')); } }, __('Verzendlabelinstellingen opslaan', 'soocool-for-woocommerce')))
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
    var s = useSettings(__('Kon de API-testinstellingen niet laden.', 'soocool-for-woocommerce'));
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
      return el(FieldGroup, { title: __('SooCool API-test', 'soocool-for-woocommerce'), badge: __('API-test', 'soocool-for-woocommerce'), description: __('Kies één test: stuur een echte WooCommerce order of gebruik een veilige testorder. De plugin bouwt dezelfde SooCool orderpayload als de normale order-sync.', 'soocool-for-woocommerce') },
        el(Loading, { message: __('API-testinstellingen laden…', 'soocool-for-woocommerce') })
      );
    }

    if (s.errorMessage) {
      return el(FieldGroup, { title: __('SooCool API-test', 'soocool-for-woocommerce'), badge: __('API-test', 'soocool-for-woocommerce'), description: __('Kies één test: stuur een echte WooCommerce order of gebruik een veilige testorder. De plugin bouwt dezelfde SooCool orderpayload als de normale order-sync.', 'soocool-for-woocommerce') },
        el(ErrorNotice, { message: s.errorMessage })
      );
    }

    function submit(){
      if (busy) { return; }
      if (mode === 'real' && !window.confirm(__('Dit verstuurt de gekozen WooCommerce-order naar de actieve SooCool-omgeving en kan daar een echte SooCool-order aanmaken of bijwerken. Doorgaan?', 'soocool-for-woocommerce'))) { return; }
      if (mode === 'real' && settings.environment === 'production' && !window.confirm(__('De productieomgeving is actief. Ga alleen door wanneer dit bewust een productie-ordertest is.', 'soocool-for-woocommerce'))) { return; }
      setBusy(true);
      setErrorMessage('');
      setResult(null);
      runManualTest({ test_mode: mode, woocommerce_order_id: Number(orderId || 0) }).then(function(response){
        setResult(response || {});
        if (response && response.success) {
          emitToast(__('API-test afgerond.', 'soocool-for-woocommerce'), 'success');
        } else {
          emitToast(__('API-test gaf een fout terug. Controleer de resultaatdetails.', 'soocool-for-woocommerce'), 'error');
        }
      }).catch(function(){
        var message = __('Kon de API-test niet uitvoeren.', 'soocool-for-woocommerce');
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

    return el(FieldGroup, { title: __('SooCool API-test', 'soocool-for-woocommerce'), badge: __('API-test', 'soocool-for-woocommerce'), description: __('Kies één test: stuur een echte WooCommerce order of gebruik een veilige testorder. De plugin bouwt dezelfde SooCool orderpayload als de normale order-sync.', 'soocool-for-woocommerce') },
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
          result.api_base_url ? el('span', null, __('API-URL:', 'soocool-for-woocommerce') + ' ' + String(result.api_base_url)) : null,
          result.order_reference ? el('span', null, __('Orderreferentie:', 'soocool-for-woocommerce') + ' ' + String(result.order_reference)) : null,
          result.soocool_order_id ? el('span', null, __('SooCool order-ID:', 'soocool-for-woocommerce') + ' ' + String(result.soocool_order_id)) : null,
          result.portal_dates && result.portal_dates.length ? el('span', null, __('Controleer portaldatum:', 'soocool-for-woocommerce') + ' ' + result.portal_dates.join(', ')) : null
        ),
        el('p', { className: 'soocool-next-step' }, result.success ? __('Volgende stap: zoek in het juiste SooCool-portaal op de getoonde orderreferentie of op de getoonde ophaal-/bezorgdatum. Productieorders staan niet in het testportaal.', 'soocool-for-woocommerce') : __('Volgende stap: controleer de foutdetails en pas orderdata, API-key, timeWindow of payload aan voordat je opnieuw test.', 'soocool-for-woocommerce'))
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
        el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'primary', className: 'soocool-manual-submit soocool-primary-action', isBusy: busy, disabled: busy || (mode === 'real' && !Number(orderId || 0)), onClick: submit }, busy ? __('API-test draait…', 'soocool-for-woocommerce') : __('Start API-test naar SooCool', 'soocool-for-woocommerce')))
      ),
      result && result.errors && result.errors.length ? el(Card, null, el('h3', null, __('SooCool-fouten', 'soocool-for-woocommerce')), el('ul', { className: 'soocool-manual-errors' }, result.errors.map(function(error, index){ return el('li', { key: index }, String(error)); }))) : null,
      result && typeof result.payload !== 'undefined' ? el(JsonCard, { title: __('Verzonden payload', 'soocool-for-woocommerce'), value: result.payload }) : null,
      result && typeof result.body !== 'undefined' ? el(JsonCard, { title: __('SooCool-reactie', 'soocool-for-woocommerce'), value: result.body }) : null,
      result ? el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'primary', className: 'soocool-primary-action', onClick: reset }, __('Nieuwe API-test starten', 'soocool-for-woocommerce'))) : null
    );
  }

  function LogsTable(props){
    return el('div', { className: 'soocool-table-wrap' }, el('table', { className: 'widefat striped soocool-logs', 'aria-label': __('SooCool-activiteitenlogs', 'soocool-for-woocommerce') },
      el('thead', null, el('tr', null, el('th', { scope: 'col' }, __('Tijd', 'soocool-for-woocommerce')), el('th', { scope: 'col' }, __('Niveau', 'soocool-for-woocommerce')), el('th', { scope: 'col' }, __('Melding', 'soocool-for-woocommerce')), el('th', { scope: 'col' }, __('Details', 'soocool-for-woocommerce')))),
      el('tbody', null, props.logs.length ? props.logs.map(function(log, index){ return el('tr', { key: String(log.created_at) + index }, el('td', null, log.created_at), el('td', null, el('span', { className: 'soocool-log-level is-' + log.level }, log.level)), el('td', null, log.message), el('td', null, el('code', null, JSON.stringify(log.context)))); }) : el('tr', null, el('td', { colSpan: 4 }, __('Nog geen logs.', 'soocool-for-woocommerce'))))
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
        if (loaded) { emitToast(__('Logs vernieuwd.', 'soocool-for-woocommerce'), 'success'); }
      }).catch(function(){
        var message = __('Kon de logs niet laden.', 'soocool-for-woocommerce');
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
        var message = __('Kon meer logs niet laden.', 'soocool-for-woocommerce');
        setErrorMessage(message);
        emitToast(message, 'error');
      }).finally(function(){ setBusy(false); });
    }
    function clear(){ if (busy) { return; } if (!window.confirm(__('Dit wist alle opgeslagen SooCool logs. Doorgaan?', 'soocool-for-woocommerce'))) { return; } setBusy(true); setErrorMessage(''); clearLogs().then(function(){ setLogs([]); setTotal(0); setHasMore(false); emitToast(__('Logs gewist.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ var message = __('Kon de logs niet wissen.', 'soocool-for-woocommerce'); setErrorMessage(message); emitToast(message, 'error'); }).finally(function(){ setBusy(false); }); }
    useEffect(function(){ refresh(); }, []);
    return el(FieldGroup, { title: __('Activiteitenlogs', 'soocool-for-woocommerce'), badge: __('Geschoond', 'soocool-for-woocommerce'), description: __('Recente geschoonde SooCool API-activiteit. Secrets en volledige payload bodies worden niet opgeslagen.', 'soocool-for-woocommerce') },
      errorMessage ? el(ErrorNotice, { message: errorMessage }) : null,
      el(Note, null, __('Gebruik deze logs alleen voor probleemoplossing. Gebruik WooCommerce ordernotities en het SooCool-portaal voor definitieve operationele controles.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'secondary', isBusy: busy && loaded, disabled: busy, onClick: refresh }, __('Vernieuwen', 'soocool-for-woocommerce')), el(c.Button, { variant: 'secondary', className: 'soocool-danger-action', disabled: busy, onClick: clear }, __('Logs wissen', 'soocool-for-woocommerce'))),
      loading ? el(Loading, { message: __('Laatste 50 logs laden…', 'soocool-for-woocommerce') }) : null,
      !loading ? el(LogsTable, { logs: logs }) : null,
      !loading && (total > 0 || hasMore) ? el('div', { className: 'soocool-log-footer' },
        el('p', { className: 'soocool-field-help soocool-log-count' }, String(logs.length) + ' / ' + String(total || logs.length) + ' ' + __('logs getoond.', 'soocool-for-woocommerce')),
        hasMore ? el(c.Button, { variant: 'primary', className: 'soocool-primary-action soocool-load-more', isBusy: busy, disabled: busy, onClick: loadMore }, __('Meer laden', 'soocool-for-woocommerce')) : null
      ) : null
    );
  }

  var tabs = [
    { name: 'connection', title: __('API-koppeling', 'soocool-for-woocommerce') },
    { name: 'mapping', title: __('Ophalen & bezorgen', 'soocool-for-woocommerce') },
    { name: 'delivery_days', title: __('Bezorgdagen', 'soocool-for-woocommerce') },
    { name: 'automation', title: __('Automatisering', 'soocool-for-woocommerce') },
    { name: 'labels', title: __('Verzendlabels', 'soocool-for-woocommerce') },
    { name: 'api_test', title: __('API-test', 'soocool-for-woocommerce') },
    { name: 'logs', title: __('Activiteitenlogs', 'soocool-for-woocommerce') }
  ];
  function activeFromHash(){ var hash = (window.location.hash || '').replace('#', ''); return ['connection', 'mapping', 'delivery_days', 'automation', 'labels', 'api_test', 'logs'].indexOf(hash) !== -1 ? hash : 'connection'; }
  function renderTabContent(active){ if (active === 'mapping') { return el(MappingScreen); } if (active === 'delivery_days') { return el(DeliveryDaysScreen); } if (active === 'automation') { return el(AutomationScreen); } if (active === 'labels') { return el(LabelsScreen); } if (active === 'api_test') { return el(ApiTestScreen); } if (active === 'logs') { return el(LogsScreen); } return el(ConnectionScreen); }
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
    return el('main', { className: 'soocool-shell', 'aria-label': __('SooCool for WooCommerce instellingen', 'soocool-for-woocommerce') },
      el(ToastHost),
      el('section', { className: 'soocool-panel soocool-tabs', 'aria-label': __('SooCool-instellingen', 'soocool-for-woocommerce') },
        el('div', { className: 'components-tab-panel__tabs', role: 'tablist', 'aria-label': __('SooCool-instellingensecties', 'soocool-for-woocommerce') },
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
