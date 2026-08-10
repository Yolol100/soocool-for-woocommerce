(function(wp){
  var root = document.getElementById('soocool-admin-app');
  if (!root || !wp || !wp.element || !wp.i18n || !wp.apiFetch || !wp.components) {
    return;
  }

  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var useRef = wp.element.useRef;
  var __ = wp.i18n.__;
  var sprintf = wp.i18n.sprintf;
  var apiFetch = wp.apiFetch;
  var c = wp.components;
  var adminConfig = window.sooCoolAdmin || {};
  var settingsCache = null;
  var settingsRequest = null;
  var keepSecretPlaceholder = '__SOOCOOL_KEEP_CURRENT_SECRET__';
  var MAX_SLOTS_PER_RULE = 12;
  var unsavedSettings = { dirty: false };

  if (apiFetch.createNonceMiddleware) {
    apiFetch.use(apiFetch.createNonceMiddleware((window.sooCoolAdmin && window.sooCoolAdmin.nonce) || ''));
  }

  function isMoneyInputValid(value){
    var normalized = String(value == null ? '' : value).replace(',', '.').trim();
    if (normalized === '') { return false; }
    var amount = Number(normalized);
    return isFinite(amount) && amount >= 0 && amount <= 999 && /^\d+(?:[.,]\d{0,2})?$/.test(String(value).trim());
  }
  function moneyInputValue(value){
    return typeof value === 'number' && isFinite(value) ? value.toFixed(2).replace('.', ',') : String(value == null ? '' : value);
  }

  function isMaskedSecretValue(value){
    var normalized = String(value == null ? '' : value).trim();
    return normalized.indexOf(keepSecretPlaceholder) !== -1 || normalized.indexOf('***') !== -1 || normalized.indexOf('•') !== -1 || normalized.indexOf('[redacted]') !== -1;
  }

  function cleanPayload(settings){
    var payload = Object.assign({}, settings || {});
    delete payload.api_key_masked;
    delete payload.api_key_present;
    delete payload.api_key_source;
    delete payload.api_key_length;
    delete payload.api_key_status;
    delete payload.active_api_key_field;
    delete payload.test_api_key_present;
    delete payload.production_api_key_present;
    if (payload.clear_active_api_key !== true) { delete payload.clear_active_api_key; }
    ['api_key', 'test_api_key', 'production_api_key'].forEach(function(field){
      var keyValue = payload[field] == null ? '' : String(payload[field]).trim();
      var uuidMatch = keyValue.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
      if (uuidMatch) {
        payload[field] = uuidMatch[0].toLowerCase();
      } else if (!keyValue || isMaskedSecretValue(keyValue)) {
        delete payload[field];
      }
    });
    [
      'checkout_delivery_netherlands_surcharge_amount',
      'checkout_delivery_netherlands_evening_surcharge_amount',
      'checkout_delivery_belgium_surcharge_amount',
      'checkout_delivery_belgium_evening_surcharge_amount'
    ].forEach(function(field){
      if (Object.prototype.hasOwnProperty.call(payload, field) && isMoneyInputValid(payload[field])) {
        payload[field] = Number(String(payload[field]).replace(',', '.'));
      }
    });
    [
      'pickup_days_offset', 'delivery_days_offset', 'checkout_delivery_days_ahead',
      'package_width', 'package_depth', 'package_height', 'package_weight',
      'missing_product_weight', 'log_retention'
    ].forEach(function(field){
      if (Object.prototype.hasOwnProperty.call(payload, field) && /^\d+$/.test(String(payload[field]).trim())) {
        payload[field] = parseInt(String(payload[field]), 10);
      }
    });
    return payload;
  }
  function api(path, method, data){
    var args = { path: path };
    if (method) { args.method = method; }
    if (typeof data !== 'undefined') { args.data = data; }
    return apiFetch(args);
  }
  function cloneSettings(settings){ return JSON.parse(JSON.stringify(settings || {})); }
  function getSettings(){ return api('/soocool/v1/settings'); }
  function getSharedSettings(force){
    if (!force && settingsCache) { return Promise.resolve(cloneSettings(settingsCache)); }
    if (!force && settingsRequest) { return settingsRequest.then(cloneSettings); }
    settingsRequest = getSettings().then(function(next){
      settingsCache = cloneSettings(next);
      return cloneSettings(settingsCache);
    }).finally(function(){ settingsRequest = null; });
    return settingsRequest;
  }
  function saveSettings(settings){ return api('/soocool/v1/settings', 'POST', cleanPayload(settings)); }
  function testConnection(){ return api('/soocool/v1/connection/test', 'POST'); }
  function getLogs(limit, offset, filters){
    filters = filters || {};
    var query = [
      'limit=' + encodeURIComponent(limit || 50),
      'offset=' + encodeURIComponent(offset || 0),
      'level=' + encodeURIComponent(filters.level || ''),
      'search=' + encodeURIComponent(filters.search || ''),
      'order_id=' + encodeURIComponent(filters.orderId || ''),
      'date_from=' + encodeURIComponent(filters.dateFrom || ''),
      'date_to=' + encodeURIComponent(filters.dateTo || '')
    ].join('&');
    return api('/soocool/v1/logs?' + query);
  }
  function clearLogs(){ return api('/soocool/v1/logs', 'DELETE'); }
  function getWebhookSecret(){ return api('/soocool/v1/webhook/secret/reveal', 'POST'); }
  function regenWebhookSecret(){ return api('/soocool/v1/webhook/secret/rotate', 'POST'); }
  function resyncFailed(){ return api('/soocool/v1/maintenance/resync-failed', 'POST'); }
  function syncOrder(orderId){ return api('/soocool/v1/orders/' + encodeURIComponent(orderId) + '/sync', 'POST', { force: false }); }
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

  function formatDateTime(value){
    var raw = String(value || '').trim();
    if (!raw) { return ''; }
    var parsed = new Date(raw.indexOf('T') === -1 ? raw.replace(' ', 'T') : raw);
    if (isNaN(parsed.getTime()) || !window.Intl || !window.Intl.DateTimeFormat) { return raw; }
    try {
      return new window.Intl.DateTimeFormat(adminConfig.locale || undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
    } catch (e) {
      return raw;
    }
  }
  function scrollToTop(){
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
  }
  function slugId(value){
    var normalized = String(value || 'section').toLowerCase();
    if (typeof normalized.normalize === 'function') { normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
    return normalized.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'section';
  }
  function isSafeHttpsBaseUrl(value){
    try {
      var parsed = new URL(String(value || '').trim());
      return parsed.protocol === 'https:' && !parsed.username && !parsed.password && !parsed.search && !parsed.hash && (!parsed.port || parsed.port === '443') && (parsed.pathname === '' || parsed.pathname === '/');
    } catch (e) {
      return false;
    }
  }
  function isSafeHttpsUrl(value){
    var normalized = String(value || '').trim();
    if (!normalized) { return true; }
    try {
      var parsed = new URL(normalized);
      return parsed.protocol === 'https:' && !parsed.username && !parsed.password;
    } catch (e) {
      return false;
    }
  }
  function isEmailOrEmpty(value){
    var normalized = String(value || '').trim();
    return !normalized || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized);
  }
  function isTimeString(value){
    return /^([01]\d|2[0-3]):[0-5]\d$/.test(String(value || ''));
  }
  function invalidHolidayDates(value){
    return String(value || '').split(',').map(function(item){ return item.trim(); }).filter(Boolean).filter(function(item){
      if (!/^\d{4}-\d{2}-\d{2}$/.test(item)) { return true; }
      var parts = item.split('-').map(Number);
      var date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
      return date.getUTCFullYear() !== parts[0] || date.getUTCMonth() !== parts[1] - 1 || date.getUTCDate() !== parts[2];
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
      { id: 'daytime', type: 'daytime', enabled: true, label: __('Ochtend - Middag', 'soocool-for-woocommerce'), time_from: '08:00', time_to: '18:00', cutoff_time: '08:00', weekdays: weekdays, sort_order: 10 },
      { id: 'evening', type: 'evening', enabled: true, label: __('Avond', 'soocool-for-woocommerce'), time_from: '17:00', time_to: '22:00', cutoff_time: '17:00', weekdays: weekdays, sort_order: 20 }
    ];
  }
  function localizedDefaultDeliveryLabel(label){
    if (label === 'Ochtend - Middag') { return __('Ochtend - Middag', 'soocool-for-woocommerce'); }
    if (label === 'Avond') { return __('Avond', 'soocool-for-woocommerce'); }
    return label;
  }
  function normalizedDeliveryTimeSlots(settings){
    var slots = settings && Array.isArray(settings.checkout_delivery_time_slots) ? settings.checkout_delivery_time_slots : defaultDeliveryTimeSlots();
    if (!slots.length) { slots = defaultDeliveryTimeSlots(); }
    return slots.map(function(slot, index){
      var defaults = defaultDeliveryTimeSlots()[index] || defaultDeliveryTimeSlots()[0];
      var next = Object.assign({}, defaults, slot || {});
      next.label = localizedDefaultDeliveryLabel(next.label || '');
      if (!Array.isArray(next.weekdays) || !next.weekdays.length) {
        next.weekdays = soocoolWeekdayOptions.map(function(item){ return item.value; });
      }
      if (next.sort_order == null) { next.sort_order = (index + 1) * 10; }
      return next;
    });
  }

  function Loading(props){ return el('div', { className: 'soocool-inline-status', role: 'status', 'aria-live': 'polite' }, el(c.Spinner), el('span', null, props && props.message ? props.message : __('Instellingen laden...', 'soocool-for-woocommerce'))); }
  function ErrorNotice(props){ return el(c.Notice, { status: 'error', isDismissible: false }, props.message); }
  function ConfirmDialog(props){
    if (!props.open) { return null; }
    var descriptionId = 'soocool-confirm-' + slugId(props.title || 'dialog');
    var modalClass = 'soocool-confirm-modal' + (props.destructive ? ' is-destructive' : '');
    return el(c.Modal, { title: props.title, onRequestClose: props.busy ? function(){} : props.onCancel, className: modalClass, shouldCloseOnClickOutside: !props.busy, shouldCloseOnEsc: !props.busy, 'aria.describedby': descriptionId },
      el('div', { className: 'soocool-confirm-content' },
        el('span', { className: 'soocool-confirm-icon', 'aria-hidden': true }, '!'),
        el('div', { className: 'soocool-confirm-content__copy', id: descriptionId },
          el('p', { className: 'soocool-confirm-copy' }, props.message),
          props.detail ? el('p', { className: 'soocool-confirm-detail' }, props.detail) : null
        )
      ),
      el('div', { className: 'soocool-modal-actions' },
        el(c.Button, { variant: 'secondary', className: 'soocool-confirm-cancel', disabled: props.busy, onClick: props.onCancel }, __('Annuleren', 'soocool-for-woocommerce')),
        el(c.Button, { variant: 'primary', className: 'soocool-confirm-action' + (props.destructive ? ' soocool-danger-fill' : ''), isBusy: props.busy, disabled: props.busy, onClick: props.onConfirm }, props.confirmLabel || __('Doorgaan', 'soocool-for-woocommerce'))
      )
    );
  }
  function DisclosureCard(props){
    var detailsRef = useRef(null);
    useEffect(function(){
      if ((props.defaultOpen || props.forceOpen) && detailsRef.current) {
        detailsRef.current.open = true;
      }
    }, [props.defaultOpen, props.forceOpen]);
    return el('details', { className: 'soocool-disclosure' + (props.className ? ' ' + props.className : ''), ref: detailsRef },
      el('summary', { className: 'soocool-disclosure__summary' },
        el('span', null, el('strong', null, props.title), props.description ? el('small', null, props.description) : null),
        el('span', { className: 'soocool-disclosure__chevron', 'aria-hidden': true })
      ),
      el('div', { className: 'soocool-disclosure__content' }, props.children)
    );
  }
  function FieldGroup(props){
    var headingId = 'soocool-heading-' + slugId(props.title);
    var hasHeaderActions = !!props.headerAction || !!props.badge;
    return el('section', { className: 'soocool-card', 'aria-labelledby': headingId },
      el('div', { className: 'soocool-card-header' },
        el('div', { className: 'soocool-card-header__copy' }, el('h2', { id: headingId }, props.title), props.description ? el('p', { className: 'soocool-muted' }, props.description) : null),
        hasHeaderActions ? el('div', { className: 'soocool-card-header__actions' }, props.headerAction || null, props.badge ? el('span', { className: 'soocool-pill is-subtle' }, props.badge) : null) : null
      ),
      el('div', { className: 'soocool-fields' }, props.children)
    );
  }
  function Card(props){ return el('div', { className: 'soocool-settings-card' + (props.soft ? ' is-soft' : '') + (props.className ? ' ' + props.className : '') }, props.children); }
  function SaveButton(props){ var variant = props.variant || 'primary'; return el(c.Button, { variant: variant, isBusy: props.isSaving, disabled: props.isSaving || !!props.disabled, onClick: props.onClick, className: (variant === 'primary' ? 'soocool-primary-action ' : '') + 'soocool-action-button' }, props.isSaving ? __('Opslaan...', 'soocool-for-woocommerce') : (props.children || __('Instellingen opslaan', 'soocool-for-woocommerce'))); }
  function Status(props){ return el('div', { className: 'soocool-status is-' + props.tone, role: props.tone === 'error' ? 'alert' : 'status', 'aria-live': props.tone === 'error' ? 'assertive' : 'polite' }, el('span', { 'aria-hidden': true }, props.tone === 'success' ? '✓' : props.tone === 'error' ? '!' : '•'), el('span', null, props.message)); }
  function Note(props){ return el('div', { className: props.className ? 'soocool-note ' + props.className : 'soocool-note' }, props.children); }
  function SettingsLoadScreen(props){
    return el(FieldGroup, { title: props.title, badge: props.badge, description: props.description },
      props.error ? el(Fragment, null,
        el(ErrorNotice, { message: props.error }),
        el('div', { className: 'soocool-actions' }, el(c.Button, { variant: 'secondary', onClick: props.onRetry }, __('Opnieuw laden', 'soocool-for-woocommerce')))
      ) : el(Loading, { message: props.loadingMessage || __('Instellingen laden...', 'soocool-for-woocommerce') })
    );
  }
  function MoneyControl(props){
    return el('div', { className: 'soocool-money-control' },
      el(c.TextControl, { type: 'text', inputMode: 'decimal', label: props.label, help: props.invalid ? __('Vul een bedrag tussen € 0 en € 999 in, met maximaal twee decimalen.', 'soocool-for-woocommerce') : undefined, className: props.invalid ? 'has-error' : '', hideLabelFromVision: !!props.hideLabelFromVision, value: moneyInputValue(props.value), onChange: props.onChange, 'aria-invalid': props.invalid ? 'true' : 'false' })
    );
  }
  function WebhookCard(props){
    var settings = props.settings || {};
    var secretState = useState('');
    var secret = secretState[0];
    var setSecret = secretState[1];
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    var confirmState = useState(false);
    var confirmOpen = confirmState[0];
    var setConfirmOpen = confirmState[1];
    var url = settings.effective_webhook_url || settings.generated_webhook_url || '';
    var header = settings.webhook_header_name || 'X-SooCool-Webhook-Token';
    var timestampHeader = settings.webhook_timestamp_header_name || 'X-SooCool-Webhook-Timestamp';
    var signatureHeader = settings.webhook_signature_header_name || 'X-SooCool-Webhook-Signature';
    var eventIdHeader = settings.webhook_event_id_header_name || 'X-SooCool-Webhook-Id';
    useEffect(function(){
      if (!secret) { return; }
      var timer = setTimeout(function(){ setSecret(''); }, 60000);
      return function(){ clearTimeout(timer); };
    }, [secret]);
    function copy(value, label){ copyText(value).then(function(){ emitToast(label, 'success'); }).catch(function(){ emitToast(__('Kopiëren mislukt; selecteer en kopieer handmatig.', 'soocool-for-woocommerce'), 'error'); }); }
    function reveal(){ if (busy) { return; } setBusy(true); getWebhookSecret().then(function(r){ setSecret(r && r.secret ? r.secret : ''); }).catch(function(){ emitToast(__('Kon de webhook-token niet laden.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); }); }
    function regenerate(){ if (busy) { return; } setBusy(true); regenWebhookSecret().then(function(r){ setSecret(r && r.secret ? r.secret : ''); setConfirmOpen(false); emitToast(__('Nieuwe webhook-token gegenereerd. Werk deze nu bij in SooCool.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ emitToast(__('Kon de webhook-token niet opnieuw genereren.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); }); }
    return el(DisclosureCard, { title: __('Webhook en beveiliging', 'soocool-for-woocommerce'), description: __('Technische instellingen voor track & trace-terugkoppelingen.', 'soocool-for-woocommerce'), defaultOpen: !!props.defaultOpen, forceOpen: !!props.forceOpen },
      el('div', { className: 'soocool-field-grid two' },
        el(c.TextControl, { type: 'url', label: __('Aangepaste callback-URL', 'soocool-for-woocommerce'), help: __('Laat leeg om de veilige plugin-webhook hieronder te gebruiken.', 'soocool-for-woocommerce'), 'aria-invalid': !isSafeHttpsUrl(settings.webhook_url) ? 'true' : 'false', value: settings.webhook_url || '', onChange: function(v){ if (props.onUpdate) { props.onUpdate('webhook_url', v); } } }),
        el(c.TextControl, { label: __('Actieve webhook-URL', 'soocool-for-woocommerce'), value: url, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Token-header', 'soocool-for-woocommerce'), value: header, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Timestamp-header', 'soocool-for-woocommerce'), value: timestampHeader, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Signature-header', 'soocool-for-woocommerce'), value: signatureHeader, readOnly: true, onChange: function(){} }),
        el(c.TextControl, { label: __('Optionele event-ID-header', 'soocool-for-woocommerce'), value: eventIdHeader, readOnly: true, onChange: function(){} })
      ),
      el(Note, null, __('HMAC-SHA256 gebruikt: timestamp + "." + raw body met de webhook-token als sleutel.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions' },
        el(c.Button, { variant: 'secondary', disabled: !url, onClick: function(){ copy(url, __('Webhook-URL gekopieerd.', 'soocool-for-woocommerce')); } }, __('URL kopiëren', 'soocool-for-woocommerce')),
        el(c.Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: reveal }, secret ? __('Token vernieuwen', 'soocool-for-woocommerce') : __('Token tonen', 'soocool-for-woocommerce')),
        el(c.Button, { variant: 'secondary', className: 'soocool-danger-action', disabled: busy, onClick: function(){ setConfirmOpen(true); } }, __('Token roteren', 'soocool-for-woocommerce'))
      ),
      secret ? el('div', { className: 'soocool-secret-row' },
        el(c.TextControl, { label: __('Webhook-token', 'soocool-for-woocommerce'), value: secret, readOnly: true, onChange: function(){} }),
        el('div', { className: 'soocool-secret-actions' },
          el(c.Button, { variant: 'secondary', onClick: function(){ copy(secret, __('Webhook-token gekopieerd.', 'soocool-for-woocommerce')); } }, __('Token kopiëren', 'soocool-for-woocommerce')),
          el(c.Button, { variant: 'link', onClick: function(){ setSecret(''); } }, __('Token verbergen', 'soocool-for-woocommerce'))
        ),
        el('p', { className: 'soocool-field-help soocool-field-full' }, __('De token wordt na 60 seconden automatisch verborgen.', 'soocool-for-woocommerce'))
      ) : null,
      el(ConfirmDialog, { open: confirmOpen, busy: busy, destructive: true, title: __('Webhook-token roteren', 'soocool-for-woocommerce'), message: __('De huidige token werkt daarna direct niet meer.', 'soocool-for-woocommerce'), detail: __('Werk de nieuwe token meteen bij in SooCool om track & trace-webhooks niet te onderbreken.', 'soocool-for-woocommerce'), confirmLabel: __('Token roteren', 'soocool-for-woocommerce'), onCancel: function(){ setConfirmOpen(false); }, onConfirm: regenerate })
    );
  }
  function ResyncButton(){
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    var confirmState = useState(false);
    var confirmOpen = confirmState[0];
    var setConfirmOpen = confirmState[1];
    function run(){ if (busy) { return; } setBusy(true); resyncFailed().then(function(r){ setConfirmOpen(false); emitToast(r && r.message ? r.message : __('Hersynchronisatie gestart.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ emitToast(__('Kon de hersynchronisatie niet starten.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); }); }
    return el(Fragment, null,
      el(c.Button, { variant: 'secondary', className: 'soocool-action-button soocool-resync-button', isBusy: busy, disabled: busy, onClick: function(){ setConfirmOpen(true); } }, __('Mislukte orders opnieuw synchroniseren', 'soocool-for-woocommerce')),
      el(ConfirmDialog, { open: confirmOpen, busy: busy, title: __('Mislukte orders opnieuw synchroniseren', 'soocool-for-woocommerce'), message: __('De plugin plant maximaal 200 mislukte orders in voor verwerking op de achtergrond.', 'soocool-for-woocommerce'), detail: __('Controleer na afloop de activiteitenlogs en ordernotities. Bestaande geplande taken worden niet dubbel toegevoegd.', 'soocool-for-woocommerce'), confirmLabel: __('Hersynchronisatie starten', 'soocool-for-woocommerce'), onCancel: function(){ setConfirmOpen(false); }, onConfirm: run })
    );
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
    return el('div', { className: 'soocool-toast is-' + toast.tone, role: toast.tone === 'error' ? 'alert' : 'status', 'aria-live': toast.tone === 'error' ? 'assertive' : 'polite', style: { left: toastLeft ? toastLeft + 'px' : '50%' } }, toast.message);
  }

  function stableSettings(settings){
    var clean = cleanPayload(settings || {});
    return JSON.stringify(Object.keys(clean).sort().reduce(function(acc, key){ acc[key] = clean[key]; return acc; }, {}));
  }
  function useSettings(loadError){
    var cachedAtMount = settingsCache ? cloneSettings(settingsCache) : null;
    var state = useState(cachedAtMount || {});
    var settings = state[0];
    var setSettings = state[1];
    var loadingState = useState(!cachedAtMount);
    var loading = loadingState[0];
    var setLoading = loadingState[1];
    var loadedState = useState(!!cachedAtMount);
    var loaded = loadedState[0];
    var setLoaded = loadedState[1];
    var savingState = useState(false);
    var saving = savingState[0];
    var setSaving = savingState[1];
    var savedState = useState(false);
    var saved = savedState[0];
    var setSaved = savedState[1];
    var baselineState = useState(cachedAtMount ? stableSettings(cachedAtMount) : '');
    var baseline = baselineState[0];
    var setBaseline = baselineState[1];
    var errorState = useState('');
    var errorMessage = errorState[0];
    var setErrorMessage = errorState[1];
    var dirty = !loading && !!baseline && stableSettings(settings) !== baseline;
    unsavedSettings.dirty = dirty;
    function accept(next){
      var accepted = cloneSettings(next);
      settingsCache = cloneSettings(accepted);
      setSettings(accepted);
      setBaseline(stableSettings(accepted));
    }
    function load(force){
      if (!force && settingsCache) {
        accept(settingsCache);
        setLoaded(true);
        setLoading(false);
        setErrorMessage('');
        return;
      }
      setLoading(true); setLoaded(false); setErrorMessage('');
      getSharedSettings(!!force).then(function(next){ accept(next); setLoaded(true); }).catch(function(error){ setErrorMessage(error && error.message ? error.message : loadError); }).finally(function(){ setLoading(false); });
    }
    useEffect(function(){ if (!loaded) { load(false); } }, []);
    useEffect(function(){ return function(){ unsavedSettings.dirty = false; }; }, []);
    useEffect(function(){
      if (!dirty) { return; }
      function warn(event){ event.preventDefault(); event.returnValue = ''; }
      window.addEventListener('beforeunload', warn);
      return function(){ window.removeEventListener('beforeunload', warn); };
    }, [dirty]);
    function save(failMessage, successMessage){
      if (saving) { return; }
      setSaving(true); setSaved(false); setErrorMessage('');
      saveSettings(settings).then(function(next){ accept(next); setSaved(true); emitToast(successMessage || __('Instellingen opgeslagen.', 'soocool-for-woocommerce'), 'success'); }).catch(function(error){ var message = error && error.message ? error.message : failMessage; setErrorMessage(message); emitToast(message, 'error'); }).finally(function(){ setSaving(false); });
    }
    return { settings: settings, setSettings: setSettings, accept: accept, loading: loading, loaded: loaded, saving: saving, saved: saved, dirty: dirty, errorMessage: errorMessage, save: save, reload: function(){ load(true); } };
  }

  function ConnectionScreen(props){
    var s = useSettings(__('Kon de SooCool-instellingen niet laden.', 'soocool-for-woocommerce'));
    var testingState = useState(false);
    var testing = testingState[0];
    var setTesting = testingState[1];
    var clearingKeyState = useState(false);
    var clearingKey = clearingKeyState[0];
    var setClearingKey = clearingKeyState[1];
    var clearKeyConfirmState = useState(false);
    var clearKeyConfirmOpen = clearKeyConfirmState[0];
    var setClearKeyConfirmOpen = clearKeyConfirmState[1];
    var statusState = useState(null);
    var status = statusState[0];
    var setStatus = statusState[1];
    var settings = s.settings;
    var setSettings = s.setSettings;
    var currentEnvironment = settings.environment || (props && props.environment) || 'test';
    var apiKeyManagedByConstant = settings.api_key_source === 'constant';
    var managedApiKeyHelp = __('Deze API-key wordt beheerd via SOOCOOL_API_KEY in wp-config.php.', 'soocool-for-woocommerce');
    useEffect(function(){
      if (s.loading) { return; }
      if (settings.api_key_status === 'invalid_masked_or_corrupt') {
        emitToast(__('De opgeslagen API-key is ongeldig of bevat nog een gemaskeerde waarde. Plak de echte SooCool API-key en sla opnieuw op.', 'soocool-for-woocommerce'), 'error');
      }
    }, [s.loading, settings.api_key_status]);
    function upd(key, value){ var next = Object.assign({}, settings); next[key] = value; setSettings(next); if (key === 'environment' && props && typeof props.onEnvironmentChange === 'function') { props.onEnvironmentChange(value); } }
    useEffect(function(){ if (!s.loading && settings.environment && props && typeof props.onEnvironmentChange === 'function') { props.onEnvironmentChange(settings.environment); } }, [s.loading, settings.environment]);
    if (!s.loaded) {
      return el(SettingsLoadScreen, { title: __('API-koppeling', 'soocool-for-woocommerce'), badge: __('Verplicht', 'soocool-for-woocommerce'), description: __('Koppel WooCommerce aan de juiste SooCool API-omgeving voordat orders worden verstuurd.', 'soocool-for-woocommerce'), error: s.errorMessage, onRetry: s.reload });
    }
    var connectionIssues = [];
    if (!isSafeHttpsBaseUrl(settings.test_base_url)) { connectionIssues.push(__('De test-API-URL moet een veilige HTTPS-basis-URL zonder pad, query of afwijkende poort zijn.', 'soocool-for-woocommerce')); }
    if (!isSafeHttpsBaseUrl(settings.production_base_url)) { connectionIssues.push(__('De productie-API-URL moet een veilige HTTPS-basis-URL zonder pad, query of afwijkende poort zijn.', 'soocool-for-woocommerce')); }
    var activeStoredKeyPresent = currentEnvironment === 'test' ? !!settings.test_api_key_present : !!settings.production_api_key_present;
    var activeEnteredKeyPresent = currentEnvironment === 'test' ? !!String(settings.test_api_key || '').trim() : !!String(settings.production_api_key || '').trim();
    var activeKeyPresent = apiKeyManagedByConstant || activeStoredKeyPresent || activeEnteredKeyPresent;
    function clearStoredApiKey(){
      if (!activeStoredKeyPresent || clearingKey) { return; }
      var environmentLabel = currentEnvironment === 'production' ? __('productie', 'soocool-for-woocommerce') : __('test', 'soocool-for-woocommerce');
      setClearingKey(true);
      saveSettings({ environment: currentEnvironment, clear_active_api_key: true })
        .then(function(next){
          s.accept(next);
          setClearKeyConfirmOpen(false);
          var message = __('De opgeslagen API-key is verwijderd.', 'soocool-for-woocommerce') + ' (' + environmentLabel + ')';
          if (apiKeyManagedByConstant) {
            message += ' ' + __('De API-keyconstante in wp-config.php blijft actief.', 'soocool-for-woocommerce');
          }
          emitToast(message, 'success');
        })
        .catch(function(error){
          emitToast(error && error.message ? error.message : __('Kon de opgeslagen API-key niet verwijderen.', 'soocool-for-woocommerce'), 'error');
        })
        .finally(function(){ setClearingKey(false); });
    }
    function ping(){
      if (testing) { return; }
      setTesting(true);
      setStatus({ message: __('Instellingen opslaan vóór het testen…', 'soocool-for-woocommerce'), tone: 'neutral' });
      saveSettings(settings)
        .then(function(next){
          s.accept(next);
          setStatus({ message: __('Verbinding testen…', 'soocool-for-woocommerce'), tone: 'neutral' });
          return testConnection();
        })
        .then(function(result){
          var contractWarning = !!(result && result.contract_warning);
          var message = contractWarning
            ? __('De API is bereikbaar, maar de ping-respons wijkt af van het verwachte SooCool-contract. Controleer dit vóór productiegebruik.', 'soocool-for-woocommerce')
            : (result && result.message ? result.message : __('Verbinding succesvol.', 'soocool-for-woocommerce'));
          var tone = contractWarning ? 'warning' : 'success';
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
    return el(Fragment, null,
      el(FieldGroup, { title: __('API-koppeling', 'soocool-for-woocommerce'), badge: __('Verplicht', 'soocool-for-woocommerce'), description: __('Koppel WooCommerce aan de juiste SooCool API-omgeving voordat orders worden verstuurd.', 'soocool-for-woocommerce') },
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      connectionIssues.length ? el(c.Notice, { status: 'error', isDismissible: false },
        el('strong', null, __('Controleer de API-URL’s:', 'soocool-for-woocommerce')),
        el('ul', { className: 'soocool-validation-list' }, connectionIssues.map(function(issue, index){ return el('li', { key: index }, issue); }))
      ) : null,
      !activeKeyPresent ? el(c.Notice, { status: 'warning', isDismissible: false }, __('Voeg voor de actieve omgeving een API-key toe voordat je de verbinding test.', 'soocool-for-woocommerce')) : null,
      el(Card, null,
        el('div', { className: 'soocool-field-grid two' },
          el(c.SelectControl, { label: __('SooCool-omgeving', 'soocool-for-woocommerce'), value: currentEnvironment, options: [{ label: 'Test', value: 'test' }, { label: __('Productie', 'soocool-for-woocommerce'), value: 'production' }], help: __('De actieve omgeving bepaalt automatisch welke API-key en basis-URL gebruikt worden.', 'soocool-for-woocommerce'), onChange: function(v){ upd('environment', v); } }),
          currentEnvironment === 'test'
            ? el(c.TextControl, { type: 'password', label: __('Test API-key', 'soocool-for-woocommerce'), help: apiKeyManagedByConstant ? managedApiKeyHelp : __('Actief: deze key wordt gebruikt voor testaanvragen.', 'soocool-for-woocommerce'), value: settings.test_api_key || '', disabled: apiKeyManagedByConstant, onFocus: function(){ if (isMaskedSecretValue(settings.test_api_key)) { upd('test_api_key', ''); } }, onClick: function(){ if (isMaskedSecretValue(settings.test_api_key)) { upd('test_api_key', ''); } }, onChange: function(v){ upd('test_api_key', v); } })
            : el(c.TextControl, { type: 'password', label: __('Productie API-key', 'soocool-for-woocommerce'), help: apiKeyManagedByConstant ? managedApiKeyHelp : __('Actief: deze key wordt gebruikt voor productieaanvragen.', 'soocool-for-woocommerce'), value: settings.production_api_key || '', disabled: apiKeyManagedByConstant, onFocus: function(){ if (isMaskedSecretValue(settings.production_api_key)) { upd('production_api_key', ''); } }, onClick: function(){ if (isMaskedSecretValue(settings.production_api_key)) { upd('production_api_key', ''); } }, onChange: function(v){ upd('production_api_key', v); } }),
          el(c.TextControl, { type: 'url', label: __('SooCool test-API-URL', 'soocool-for-woocommerce'), help: __('Wordt gebruikt wanneer de testomgeving actief is.', 'soocool-for-woocommerce'), 'aria-invalid': !isSafeHttpsBaseUrl(settings.test_base_url) ? 'true' : 'false', value: settings.test_base_url || '', onChange: function(v){ upd('test_base_url', v); } }),
          el(c.TextControl, { type: 'url', label: __('SooCool productie-API-URL', 'soocool-for-woocommerce'), help: __('Wordt gebruikt wanneer de productieomgeving actief is.', 'soocool-for-woocommerce'), 'aria-invalid': !isSafeHttpsBaseUrl(settings.production_base_url) ? 'true' : 'false', value: settings.production_base_url || '', onChange: function(v){ upd('production_base_url', v); } })
        ),
        null
      ),
      status ? el(Status, { tone: status.tone, message: status.message }) : null,
      el('div', { className: 'soocool-action-bar soocool-connection-actions' },
        s.dirty ? el('span', { className: 'soocool-unsaved soocool-action-bar__status', role: 'status' }, __('Niet-opgeslagen wijzigingen', 'soocool-for-woocommerce')) : null,
        el('div', { className: 'soocool-action-bar__primary' },
          el(SaveButton, { variant: 'secondary', isSaving: s.saving, disabled: !s.dirty || connectionIssues.length > 0, onClick: function(){ s.save(__('Kon de instellingen niet opslaan. Controleer de ingevulde waarden.', 'soocool-for-woocommerce'), __('API-instellingen opgeslagen.', 'soocool-for-woocommerce')); } }),
          el(c.Button, { variant: 'primary', className: 'soocool-primary-action soocool-action-button soocool-connection-test', isBusy: testing, disabled: s.saving || testing || clearingKey || connectionIssues.length > 0 || !activeKeyPresent, onClick: ping }, s.dirty ? __('Opslaan en verbinding testen', 'soocool-for-woocommerce') : __('Verbinding testen', 'soocool-for-woocommerce'))
        ),
        activeStoredKeyPresent || (currentEnvironment === 'test' && adminConfig.testPortalUrl) ? el('div', { className: 'soocool-action-bar__secondary' },
          activeStoredKeyPresent ? el(c.Button, { variant: 'secondary', className: 'soocool-danger-action soocool-action-button', isDestructive: true, isBusy: clearingKey, disabled: s.dirty || s.saving || testing || clearingKey, onClick: function(){ setClearKeyConfirmOpen(true); } }, __('Opgeslagen API-key verwijderen', 'soocool-for-woocommerce')) : null,
          currentEnvironment === 'test' && adminConfig.testPortalUrl ? el(c.Button, { variant: 'link', href: adminConfig.testPortalUrl, target: '_blank', rel: 'noreferrer noopener', 'aria-label': __('SooCool testportaal openen in een nieuw tabblad', 'soocool-for-woocommerce') }, __('SooCool testportaal openen', 'soocool-for-woocommerce')) : null
        ) : null
      )
    ),
      el(ConfirmDialog, {
        open: clearKeyConfirmOpen,
        busy: clearingKey,
        destructive: true,
        title: __('Opgeslagen API-key verwijderen', 'soocool-for-woocommerce'),
        message: __('Weet je zeker dat je de opgeslagen API-key voor de actieve omgeving wilt verwijderen?', 'soocool-for-woocommerce'),
        detail: apiKeyManagedByConstant ? __('De API-keyconstante in wp-config.php blijft actief.', 'soocool-for-woocommerce') : null,
        confirmLabel: __('Opgeslagen API-key verwijderen', 'soocool-for-woocommerce'),
        onCancel: function(){ setClearKeyConfirmOpen(false); },
        onConfirm: clearStoredApiKey
      })
    );
  }

  function MappingScreen(){
    var s = useSettings(__('Kon de koppeling-instellingen niet laden.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    function upd(key, value){ var next = Object.assign({}, settings); next[key] = value; setSettings(next); }
    if (!s.loaded) {
      return el(SettingsLoadScreen, { title: __('Ophalen & bezorgen', 'soocool-for-woocommerce'), badge: __('Orders', 'soocool-for-woocommerce'), description: __('Beheer de dagelijkse ordergegevens. Technische en zelden gewijzigde opties staan ingeklapt.', 'soocool-for-woocommerce'), error: s.errorMessage, onRetry: s.reload });
    }
    var mappingIssues = [];
    var pickupPlanningHasIssue = false;
    var packagingHasIssue = false;
    var webhookHasIssue = false;
    if (settings.enable_pickup) {
      var requiredPickupFields = [settings.pickup_company, settings.pickup_street, settings.pickup_house_number, settings.pickup_postal_code, settings.pickup_city, settings.pickup_country];
      if (requiredPickupFields.some(function(value){ return !String(value || '').trim(); })) {
        pickupPlanningHasIssue = true;
        mappingIssues.push(__('Vul het volledige ophaaladres in.', 'soocool-for-woocommerce'));
      }
      if (!String(settings.pickup_email || '').trim() && !String(settings.pickup_phone || '').trim()) {
        pickupPlanningHasIssue = true;
        mappingIssues.push(__('Vul voor de ophaallocatie een e-mailadres of telefoonnummer in.', 'soocool-for-woocommerce'));
      }
      if (!isEmailOrEmpty(settings.pickup_email)) {
        pickupPlanningHasIssue = true;
        mappingIssues.push(__('Vul een geldig e-mailadres voor de ophaallocatie in.', 'soocool-for-woocommerce'));
      }
      if (!/^[A-Z]{2}$/.test(String(settings.pickup_country || '').toUpperCase())) {
        pickupPlanningHasIssue = true;
        mappingIssues.push(__('Gebruik voor het ophaalland een ISO-landcode van twee letters.', 'soocool-for-woocommerce'));
      }
      if (!settings.pickup_time_from || !settings.pickup_time_to || settings.pickup_time_from >= settings.pickup_time_to) {
        pickupPlanningHasIssue = true;
        mappingIssues.push(__('De eindtijd van het ophaalvenster moet na de starttijd liggen.', 'soocool-for-woocommerce'));
      }
      if (Number(settings.delivery_days_offset) < 1 || Number(settings.delivery_days_offset) <= Number(settings.pickup_days_offset)) {
        pickupPlanningHasIssue = true;
        mappingIssues.push(__('De fallback-bezorgdatum moet later zijn dan de ophaaldatum.', 'soocool-for-woocommerce'));
      }
    }
    if ([settings.package_width, settings.package_depth, settings.package_height, settings.package_weight, settings.missing_product_weight].some(function(value){ return !isFinite(Number(value)) || Number(value) < 1; })) {
      packagingHasIssue = true;
      mappingIssues.push(__('Pakketafmetingen en gewichten moeten groter zijn dan nul.', 'soocool-for-woocommerce'));
    }
    if (!isSafeHttpsUrl(settings.webhook_url)) {
      webhookHasIssue = true;
      mappingIssues.push(__('De aangepaste webhook-URL moet leeg zijn of een geldige HTTPS-URL zonder gebruikersnaam of wachtwoord.', 'soocool-for-woocommerce'));
    }
    return el(FieldGroup, { title: __('Ophalen & bezorgen', 'soocool-for-woocommerce'), badge: __('Orders', 'soocool-for-woocommerce'), description: __('Beheer de dagelijkse ordergegevens. Technische en zelden gewijzigde opties staan ingeklapt.', 'soocool-for-woocommerce') },
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      mappingIssues.length ? el(c.Notice, { status: 'error', isDismissible: false },
        el('strong', null, __('Controleer de instellingen:', 'soocool-for-woocommerce')),
        el('ul', { className: 'soocool-validation-list' }, mappingIssues.map(function(issue, index){ return el('li', { key: index }, issue); }))
      ) : null,
      el(Card, { soft: true }, el('div', { className: 'soocool-compact-row' },
        el(c.ToggleControl, { label: __('Ophaaltaak aanmaken vóór bezorging', 'soocool-for-woocommerce'), help: __('Alleen inschakelen wanneer ophaaltaken met SooCool zijn afgesproken.', 'soocool-for-woocommerce'), checked: !!settings.enable_pickup, onChange: function(v){ upd('enable_pickup', v); } }),
        el(c.TextControl, { label: __('Prefix voor WooCommerce-orderreferentie', 'soocool-for-woocommerce'), help: __('Bijvoorbeeld TEST-. Laat leeg voor het normale ordernummer.', 'soocool-for-woocommerce'), value: settings.order_reference_prefix || '', onChange: function(v){ upd('order_reference_prefix', v); } })
      )),
      el(DisclosureCard, { id: 'pickup-planning', title: __('Afzender en planning', 'soocool-for-woocommerce'), description: __('Adres, datums en tijdvensters voor de ophaaltaak.', 'soocool-for-woocommerce'), defaultOpen: pickupPlanningHasIssue, forceOpen: pickupPlanningHasIssue },
        el('div', { className: 'soocool-mapping-split' },
        el('div', { className: 'soocool-mapping-column soocool-mapping-column-left' },
          settings.enable_pickup ? el(Card, null,
            el('h3', null, __('Afzender', 'soocool-for-woocommerce')),
            el('div', { className: 'soocool-field-grid two soocool-sender-grid' },
              el(c.TextControl, { required: true, autoComplete: 'organization', 'aria-invalid': !String(settings.pickup_company || '').trim() ? 'true' : 'false', label: __('Bedrijfsnaam', 'soocool-for-woocommerce'), value: settings.pickup_company || '', onChange: function(v){ upd('pickup_company', v); } }),
              el(c.TextControl, { autoComplete: 'name', label: __('Contactpersoon', 'soocool-for-woocommerce'), value: settings.pickup_contact_name || '', onChange: function(v){ upd('pickup_contact_name', v); } }),
              el(c.TextControl, { type: 'email', autoComplete: 'email', 'aria-invalid': !isEmailOrEmpty(settings.pickup_email) ? 'true' : 'false', label: __('E-mailadres', 'soocool-for-woocommerce'), value: settings.pickup_email || '', onChange: function(v){ upd('pickup_email', v); } }),
              el(c.TextControl, { type: 'tel', inputMode: 'tel', autoComplete: 'tel', label: __('Telefoonnummer', 'soocool-for-woocommerce'), value: settings.pickup_phone || '', onChange: function(v){ upd('pickup_phone', v); } }),
              el(c.TextControl, { required: true, autoComplete: 'address-line1', 'aria-invalid': !String(settings.pickup_street || '').trim() ? 'true' : 'false', label: __('Straat', 'soocool-for-woocommerce'), value: settings.pickup_street || '', onChange: function(v){ upd('pickup_street', v); } }),
              el(c.TextControl, { required: true, autoComplete: 'address-line2', 'aria-invalid': !String(settings.pickup_house_number || '').trim() ? 'true' : 'false', label: __('Huisnummer', 'soocool-for-woocommerce'), value: settings.pickup_house_number || '', onChange: function(v){ upd('pickup_house_number', v); } }),
              el(c.TextControl, { required: true, autoComplete: 'postal-code', 'aria-invalid': !String(settings.pickup_postal_code || '').trim() ? 'true' : 'false', label: __('Postcode', 'soocool-for-woocommerce'), value: settings.pickup_postal_code || '', onChange: function(v){ upd('pickup_postal_code', v); } }),
              el(c.TextControl, { required: true, autoComplete: 'address-level2', 'aria-invalid': !String(settings.pickup_city || '').trim() ? 'true' : 'false', label: __('Plaats', 'soocool-for-woocommerce'), value: settings.pickup_city || '', onChange: function(v){ upd('pickup_city', v); } }),
              el(c.TextControl, { required: true, maxLength: 2, autoComplete: 'country', 'aria-invalid': !/^[A-Z]{2}$/.test(String(settings.pickup_country || '').toUpperCase()) ? 'true' : 'false', className: 'soocool-field-full', label: __('Landcode', 'soocool-for-woocommerce'), help: __('Gebruik de ISO-landcode met twee letters, bijvoorbeeld NL of BE.', 'soocool-for-woocommerce'), value: settings.pickup_country || 'NL', onChange: function(v){ upd('pickup_country', String(v || '').toUpperCase().slice(0, 2)); } })
            )
          ) : el(Card, { soft: true }, el('h3', null, __('Ophalen staat uit', 'soocool-for-woocommerce')), el('p', { className: 'soocool-field-help' }, __('De plugin maakt alleen een bezorgtaak en goederen aan. Hierdoor blijven ophaalvelden uit beeld.', 'soocool-for-woocommerce')))
        ),
        el('div', { className: 'soocool-mapping-column soocool-mapping-column-right' },
          el(Card, { className: 'soocool-planning-card' },
            el('h3', null, __('Planning', 'soocool-for-woocommerce')),
            el('div', { className: 'soocool-field-grid two' },
              settings.enable_pickup ? el(c.TextControl, { type: 'number', min: 0, max: 29, 'aria-invalid': Number(settings.pickup_days_offset) < 0 || Number(settings.pickup_days_offset) > 29 ? 'true' : 'false', label: __('Ophaaldatum-offset in dagen', 'soocool-for-woocommerce'), value: String(settings.pickup_days_offset == null ? 1 : settings.pickup_days_offset), onChange: function(v){ upd('pickup_days_offset', Number(v)); } }) : null,
              el(c.TextControl, { type: 'number', min: 0, max: 30, 'aria-invalid': settings.enable_pickup && (Number(settings.delivery_days_offset) < 1 || Number(settings.delivery_days_offset) <= Number(settings.pickup_days_offset)) ? 'true' : 'false', label: __('Fallback bezorgdatum-offset in dagen', 'soocool-for-woocommerce'), help: __('Alleen voor orders zonder gekozen bezorgdatum.', 'soocool-for-woocommerce'), value: String(settings.delivery_days_offset == null ? 1 : settings.delivery_days_offset), onChange: function(v){ upd('delivery_days_offset', Number(v)); } }),
              settings.enable_pickup ? el(c.TextControl, { type: 'time', 'aria-invalid': !isTimeString(settings.pickup_time_from) || !isTimeString(settings.pickup_time_to) || settings.pickup_time_from >= settings.pickup_time_to ? 'true' : 'false', label: __('Ophaalvenster start', 'soocool-for-woocommerce'), value: settings.pickup_time_from || '', onChange: function(v){ upd('pickup_time_from', v); } }) : null,
              settings.enable_pickup ? el(c.TextControl, { type: 'time', 'aria-invalid': !isTimeString(settings.pickup_time_from) || !isTimeString(settings.pickup_time_to) || settings.pickup_time_from >= settings.pickup_time_to ? 'true' : 'false', label: __('Ophaalvenster einde', 'soocool-for-woocommerce'), value: settings.pickup_time_to || '', onChange: function(v){ upd('pickup_time_to', v); } }) : null,
              el('div', { className: 'soocool-readonly-setting soocool-field-full' },
                el('span', null, __('Fallback bezorgvenster', 'soocool-for-woocommerce')),
                el('strong', null, '08:00–18:00'),
                el('small', null, __('Wordt alleen gebruikt wanneer geen bezorgmoment is gekozen; het Bezorgschema heeft voorrang.', 'soocool-for-woocommerce'))
              )
            )
          )
        )
        )
      ),
      el(DisclosureCard, { title: __('Goederen en verpakking', 'soocool-for-woocommerce'), description: __('Fallbacks en pakketwaarden die meestal eenmalig worden ingesteld.', 'soocool-for-woocommerce'), defaultOpen: packagingHasIssue, forceOpen: packagingHasIssue },
        el('div', { className: 'soocool-field-grid two' },
          el(c.TextControl, { label: __('Fallback goederenomschrijving', 'soocool-for-woocommerce'), value: settings.goods_description_fallback || '', onChange: function(v){ upd('goods_description_fallback', v); } }),
          el(c.TextControl, { label: __('Verpakkingstype', 'soocool-for-woocommerce'), help: __('Gebruik alleen de door SooCool afgesproken technische sleutel. Standaard: box.', 'soocool-for-woocommerce'), value: settings.packaging_type || 'box', maxLength: 32, 'aria-invalid': !/^[a-z0-9_-]{1,32}$/.test(String(settings.packaging_type || 'box')) ? 'true' : 'false', onChange: function(v){ upd('packaging_type', String(v || '').toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 32)); } }),
          el(c.SelectControl, { className: 'soocool-field-full soocool-compact-label-control', label: __('Transportvereiste', 'soocool-for-woocommerce'), value: settings.temperature_regime || 'cooled', options: [{ label: __('Gekoeld', 'soocool-for-woocommerce'), value: 'cooled' }, { label: __('Bevroren', 'soocool-for-woocommerce'), value: 'frozen' }, { label: __('Omgevingstemperatuur', 'soocool-for-woocommerce'), value: 'ambient' }], onChange: function(v){ upd('temperature_regime', v); } }),
          el('div', { className: 'soocool-field-full soocool-subsection' },
            el('h4', null, __('Pakketstandaarden', 'soocool-for-woocommerce')),
            el('p', { className: 'soocool-field-help' }, __('Standaard buitenmaten en het maximale doosgewicht voor orders zonder productspecifieke pakketgegevens.', 'soocool-for-woocommerce')),
            el('div', { className: 'soocool-package-grid' },
              el(c.TextControl, { type: 'number', min: 1, 'aria-invalid': !isFinite(Number(settings.package_width)) || Number(settings.package_width) < 1 ? 'true' : 'false', label: __('Breedte (cm)', 'soocool-for-woocommerce'), value: String(settings.package_width == null ? 60 : settings.package_width), onChange: function(v){ upd('package_width', Number(v)); } }),
              el(c.TextControl, { type: 'number', min: 1, 'aria-invalid': !isFinite(Number(settings.package_depth)) || Number(settings.package_depth) < 1 ? 'true' : 'false', label: __('Diepte (cm)', 'soocool-for-woocommerce'), value: String(settings.package_depth == null ? 40 : settings.package_depth), onChange: function(v){ upd('package_depth', Number(v)); } }),
              el(c.TextControl, { type: 'number', min: 1, 'aria-invalid': !isFinite(Number(settings.package_height)) || Number(settings.package_height) < 1 ? 'true' : 'false', label: __('Hoogte (cm)', 'soocool-for-woocommerce'), value: String(settings.package_height == null ? 11 : settings.package_height), onChange: function(v){ upd('package_height', Number(v)); } }),
              el(c.TextControl, { type: 'number', min: 1, step: 100, 'aria-invalid': !isFinite(Number(settings.package_weight)) || Number(settings.package_weight) < 1 ? 'true' : 'false', label: __('Maximaal gewicht per doos (gram)', 'soocool-for-woocommerce'), value: String(settings.package_weight == null ? 10000 : settings.package_weight), onChange: function(v){ upd('package_weight', Number(v)); } })
            )
          ),
          el(c.TextControl, { className: 'soocool-field-full', type: 'number', min: 1, step: 100, 'aria-invalid': !isFinite(Number(settings.missing_product_weight)) || Number(settings.missing_product_weight) < 1 ? 'true' : 'false', label: __('Fallbackgewicht bij ontbrekend productgewicht (gram)', 'soocool-for-woocommerce'), help: __('Wordt per producteenheid gebruikt wanneer geen eenduidig WooCommerce-gewicht beschikbaar is.', 'soocool-for-woocommerce'), value: String(settings.missing_product_weight == null ? 1000 : settings.missing_product_weight), onChange: function(v){ upd('missing_product_weight', Number(v)); } })
        )
      ),
      el(WebhookCard, { settings: settings, onUpdate: upd, defaultOpen: webhookHasIssue, forceOpen: webhookHasIssue }),
      el(Note, null, __('Ophalen is optioneel. Laat dit uitgeschakeld tenzij SooCool heeft bevestigd dat het account ophaaltaken mag versturen.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions soocool-save-row' },
        s.dirty ? el('span', { className: 'soocool-unsaved', role: 'status' }, __('Niet-opgeslagen wijzigingen', 'soocool-for-woocommerce')) : null,
        el(SaveButton, { isSaving: s.saving, disabled: !s.dirty || mappingIssues.length > 0, onClick: function(){ s.save(__('Kon de koppeling-instellingen niet opslaan. Controleer de ingevulde waarden.', 'soocool-for-woocommerce'), __('Ophaal- en bezorginstellingen opgeslagen.', 'soocool-for-woocommerce')); } }, mappingIssues.length ? __('Los eerst de fouten op', 'soocool-for-woocommerce') : __('Ophalen & bezorgen opslaan', 'soocool-for-woocommerce'))
      )
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
    if (!s.loaded) {
      return el(SettingsLoadScreen, { title: __('Bezorgschema', 'soocool-for-woocommerce'), badge: __('Checkout', 'soocool-for-woocommerce'), description: __('Beheer per bezorgdag de cut-off en dagdelen voor classic checkout en de Blocks-adapter.', 'soocool-for-woocommerce'), error: s.errorMessage, onRetry: s.reload });
    }
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
          slots: slots.map(function(slot, slotIndex){
            var normalizedSlot = Object.assign({ id: 'slot-' + String(slotIndex + 1), type: 'daytime', enabled: true, label: __('Ochtend - Middag', 'soocool-for-woocommerce'), time_from: '08:00', time_to: '18:00', cutoff_time: '08:00', sort_order: (slotIndex + 1) * 10 }, slot || {}, { weekdays: [deliveryWeekday] });
            normalizedSlot.label = localizedDefaultDeliveryLabel(normalizedSlot.label || '');
            return normalizedSlot;
          })
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
      var id = 'custom-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
      return { id: id, type: 'daytime', enabled: true, label: __('Nieuw dagdeel', 'soocool-for-woocommerce'), time_from: '08:00', time_to: '18:00', cutoff_time: '08:00', sort_order: (slots.length + 1) * 10, weekdays: [rule.delivery_weekday || 'monday'] };
    }
    function addRule(){
      var schedule = normalizeSchedule().slice();
      var newIndex = schedule.length;
      var usedDays = schedule.map(function(rule){ return rule.delivery_weekday; });
      var available = soocoolWeekdayOptions.find(function(option){ return usedDays.indexOf(option.value) === -1; });
      if (!available) { emitToast(__('Alle weekdagen zijn al toegevoegd.', 'soocool-for-woocommerce'), 'warning'); return; }
      var dayIndex = soocoolWeekdayOptions.findIndex(function(option){ return option.value === available.value; });
      var cutoffDay = soocoolWeekdayOptions[(dayIndex + soocoolWeekdayOptions.length - 1) % soocoolWeekdayOptions.length].value;
      schedule.push({ enabled: true, delivery_weekday: available.value, cutoff_weekday: cutoffDay, cutoff_time: '13:00', sort_order: (newIndex + 1) * 10, slots: [createSlot({ delivery_weekday: available.value, slots: [] })] });
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
      if (rule.slots.length >= MAX_SLOTS_PER_RULE) {
        emitToast(__('Per bezorgdag zijn maximaal 12 dagdelen toegestaan.', 'soocool-for-woocommerce'), 'warning');
        return;
      }
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
    var daysAhead = Number(settings.checkout_delivery_days_ahead);
    var daysAheadHasIssue = !Number.isInteger(daysAhead) || daysAhead < 7 || daysAhead > 92;
    var holidaysHaveIssue = invalidHolidayDates(settings.checkout_delivery_holidays).length > 0;
    var surchargeAmounts = [
      settings.checkout_delivery_netherlands_surcharge_amount == null ? 0 : settings.checkout_delivery_netherlands_surcharge_amount,
      settings.checkout_delivery_netherlands_evening_surcharge_amount == null ? 0 : settings.checkout_delivery_netherlands_evening_surcharge_amount,
      settings.checkout_delivery_belgium_surcharge_amount == null ? 2 : settings.checkout_delivery_belgium_surcharge_amount,
      settings.checkout_delivery_belgium_evening_surcharge_amount == null ? 1.5 : settings.checkout_delivery_belgium_evening_surcharge_amount
    ];
    var surchargeHasIssue = surchargeAmounts.some(function(amount){ return !isMoneyInputValid(amount); });
    var generalHasIssue = daysAheadHasIssue || holidaysHaveIssue;
    function scheduleIssues(){
      var issues = [];
      var seenDays = {};
      if (daysAheadHasIssue) { issues.push(__('Het aantal dagen vooruit moet een heel getal tussen 7 en 92 zijn.', 'soocool-for-woocommerce')); }
      if (holidaysHaveIssue) { issues.push(__('Gebruik voor geblokkeerde datums geldige datums in het formaat JJJJ-MM-DD.', 'soocool-for-woocommerce')); }
      var activeRules = schedule.filter(function(rule){ return rule.enabled !== false; });
      if (!activeRules.length) { issues.push(__('Activeer minimaal één bezorgdag.', 'soocool-for-woocommerce')); }
      if (surchargeHasIssue) {
        issues.push(__('Controleer de bezorgtoeslagen: gebruik een bedrag tussen € 0 en € 999 met maximaal twee decimalen.', 'soocool-for-woocommerce'));
      }
      schedule.forEach(function(rule){
        var day = rule.delivery_weekday || 'monday';
        if (seenDays[day]) { issues.push((weekdayByValue[day] || day) + ': ' + __('deze bezorgdag komt meer dan één keer voor.', 'soocool-for-woocommerce')); }
        seenDays[day] = true;
        if (!isTimeString(rule.cutoff_time)) { issues.push((weekdayByValue[day] || day) + ': ' + __('vul een geldige cut-offtijd in.', 'soocool-for-woocommerce')); }
        var configuredSlots = Array.isArray(rule.slots) ? rule.slots : [];
        var activeSlots = configuredSlots.filter(function(slot){ return slot.enabled !== false; });
        var seenSlotTimes = {};
        if (configuredSlots.length > MAX_SLOTS_PER_RULE) { issues.push((weekdayByValue[day] || day) + ': ' + __('maximaal 12 dagdelen zijn toegestaan.', 'soocool-for-woocommerce')); }
        if (rule.enabled !== false && !activeSlots.length) { issues.push((weekdayByValue[day] || day) + ': ' + __('activeer minimaal één dagdeel.', 'soocool-for-woocommerce')); }
        configuredSlots.forEach(function(slot){
          var label = String(slot.label || '').trim();
          var from = String(slot.time_from || '');
          var to = String(slot.time_to || '');
          if (!label || label.length > 80) { issues.push((weekdayByValue[day] || day) + ': ' + __('geef ieder dagdeel een label van maximaal 80 tekens.', 'soocool-for-woocommerce')); }
          if (!isTimeString(from) || !isTimeString(to) || from >= to) { issues.push((weekdayByValue[day] || day) + ' — ' + (slot.label || __('Dagdeel', 'soocool-for-woocommerce')) + ': ' + __('de eindtijd moet na de starttijd liggen.', 'soocool-for-woocommerce')); }
          if (isTimeString(from) && isTimeString(to) && from < to) {
            var timeKey = from + '|' + to;
            if (seenSlotTimes[timeKey]) { issues.push((weekdayByValue[day] || day) + ': ' + __('ieder dagdeel moet een unieke start- en eindtijd hebben.', 'soocool-for-woocommerce')); }
            seenSlotTimes[timeKey] = true;
          }
          if (!isTimeString(slot.cutoff_time || from)) { issues.push((weekdayByValue[day] || day) + ' — ' + (slot.label || __('Dagdeel', 'soocool-for-woocommerce')) + ': ' + __('vul een geldige cut-offtijd in.', 'soocool-for-woocommerce')); }
        });
      });
      return issues;
    }
    var validationIssues = scheduleIssues();
    var generalIssueCount = (daysAheadHasIssue ? 1 : 0) + (holidaysHaveIssue ? 1 : 0);
    var surchargeIssueCount = surchargeHasIssue ? 1 : 0;
    var scheduleHasIssue = validationIssues.length > generalIssueCount + surchargeIssueCount;
    var canAddRule = schedule.length < soocoolWeekdayOptions.length;
    return el(FieldGroup, { title: __('Bezorgschema', 'soocool-for-woocommerce'), badge: __('Checkout', 'soocool-for-woocommerce'), description: __('Stel per bezorgdag de uiterste besteltijd en dagdelen in. Checkout Blocks blijft staging-first totdat pariteit is gevalideerd.', 'soocool-for-woocommerce') },
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      validationIssues.length ? el(c.Notice, { status: 'error', isDismissible: false },
        el('strong', null, __('Controleer het bezorgschema:', 'soocool-for-woocommerce')),
        el('ul', { className: 'soocool-validation-list' }, validationIssues.map(function(issue, index){ return el('li', { key: index }, issue); }))
      ) : null,
      el('div', { className: 'soocool-delivery-dashboard' },
        el(DisclosureCard, { className: 'soocool-delivery-accordion soocool-delivery-accordion--schedule', defaultOpen: true, forceOpen: scheduleHasIssue, title: __('Bezorgschema', 'soocool-for-woocommerce'), description: __('Elke bezorgdag beheert een eigen cut-off en één of meer aanpasbare dagdelen.', 'soocool-for-woocommerce') },
          el('div', { className: 'soocool-delivery-schedule-toolbar' },
            el(c.Button, { variant: 'secondary', onClick: addRule, disabled: !canAddRule, className: 'soocool-delivery-add-rule' }, el('span', { className: 'dashicons dashicons-plus-alt2', 'aria-hidden': true }), el('span', null, canAddRule ? __('Bezorgdag toevoegen', 'soocool-for-woocommerce') : __('Alle weekdagen toegevoegd', 'soocool-for-woocommerce')))
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
                  el(c.Button, { variant: 'tertiary', id: buttonId, className: 'soocool-delivery-schedule-card__toggle', 'aria-expanded': isOpen ? 'true' : 'false', 'aria-controls': panelId, onClick: function(){ toggleCard(ruleIndex); } },
                    el('span', { className: 'soocool-delivery-schedule-card__summary' },
                      el('span', { className: 'soocool-delivery-schedule-card__title' }, weekdayByValue[rule.delivery_weekday] || rule.delivery_weekday),
                      el('span', { className: 'soocool-delivery-schedule-card__meta' }, __('Bestelbaar t/m', 'soocool-for-woocommerce') + ' ' + (weekdayByValue[rule.cutoff_weekday] || rule.cutoff_weekday) + ' ' + (rule.cutoff_time || '13:00')),
                      el('span', { className: 'soocool-delivery-schedule-card__count' }, activeSlots + ' ' + __('actieve dagdelen', 'soocool-for-woocommerce'))
                    ),
                    !isOpen ? el('span', { className: 'soocool-delivery-schedule-card__edit-text' }, __('Bewerken', 'soocool-for-woocommerce')) : null,
                    el('span', { className: 'dashicons ' + (isOpen ? 'dashicons-arrow-up-alt2' : 'dashicons-edit'), 'aria-hidden': true })
                  ),
                  el('div', { className: 'soocool-delivery-schedule-card__actions' },
                    el(c.ToggleControl, { label: __('Actief', 'soocool-for-woocommerce'), checked: rule.enabled !== false, onChange: function(v){ updateRule(ruleIndex, 'enabled', v); } }),
                    el(c.Button, { variant: 'secondary', isDestructive: true, disabled: schedule.length <= 1, onClick: function(){ removeRule(ruleIndex); }, 'aria-label': __('Verwijder bezorgdag', 'soocool-for-woocommerce') + ': ' + (weekdayByValue[rule.delivery_weekday] || rule.delivery_weekday) }, el('span', { className: 'dashicons dashicons-trash', 'aria-hidden': true }))
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
                      el('div', { className: 'soocool-delivery-schedule-slots__copy' },
                        el('h4', null, __('Dagdelen', 'soocool-for-woocommerce')),
                        el('span', { className: 'soocool-field-help' }, __('Standaard dagdelen: Ochtend - Middag en Avond. Je kunt labels, tijden, cut-offs en volgorde zelf aanpassen.', 'soocool-for-woocommerce'))
                      ),
                      el(c.Button, { variant: 'secondary', onClick: function(){ addSlot(ruleIndex); }, disabled: slots.length >= MAX_SLOTS_PER_RULE, className: 'soocool-delivery-add-slot' }, el('span', { className: 'dashicons dashicons-plus-alt2', 'aria-hidden': true }), el('span', null, slots.length >= MAX_SLOTS_PER_RULE ? __('Maximum bereikt', 'soocool-for-woocommerce') : __('Dagdeel toevoegen', 'soocool-for-woocommerce')))
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
                            el('strong', null, (slot.time_from || '08:00') + '-' + (slot.time_to || '18:00')),
                            slot.label ? el('span', { className: 'soocool-delivery-schedule-slot__label' }, slot.label) : null
                          ),
                          el('span', { className: 'soocool-delivery-schedule-slot__status' }, slot.enabled === false ? __('Uitgeschakeld', 'soocool-for-woocommerce') : __('Actief', 'soocool-for-woocommerce')),
                          el('div', { className: 'soocool-delivery-schedule-slot__cutoff' }, el('span', null, __('Cut-off', 'soocool-for-woocommerce')), el('strong', null, slot.cutoff_time || slot.time_from || '08:00')),
                          el('div', { className: 'soocool-delivery-schedule-slot__actions' },
                            el(c.Button, { id: slotButtonId, variant: 'tertiary', className: 'soocool-delivery-slot-edit' + (isSlotOpen ? ' is-open' : ''), onClick: function(){ toggleSlot(ruleIndex, slotIndex); }, 'aria-expanded': isSlotOpen ? 'true' : 'false', 'aria-controls': slotPanelId, 'aria-label': isSlotOpen ? __('Sluit dagdeeldetails', 'soocool-for-woocommerce') : __('Bewerk dagdeel', 'soocool-for-woocommerce') }, el('span', { className: 'dashicons ' + (isSlotOpen ? 'dashicons-arrow-up-alt2' : 'dashicons-edit'), 'aria-hidden': true }), el('span', { className: 'screen-reader-text' }, isSlotOpen ? __('Sluiten', 'soocool-for-woocommerce') : __('Bewerken', 'soocool-for-woocommerce'))),
                            el(c.Button, { variant: 'secondary', isDestructive: true, disabled: slots.length <= 1, onClick: function(){ removeSlot(ruleIndex, slotIndex); }, className: 'soocool-delivery-slot-remove', 'aria-label': __('Verwijder dagdeel', 'soocool-for-woocommerce') + ': ' + (slot.label || (slot.time_from || '08:00') + '-' + (slot.time_to || '18:00')) }, el('span', { className: 'dashicons dashicons-trash', 'aria-hidden': true }))
                          )
                        ),
                        isSlotOpen ? el('div', { id: slotPanelId, className: 'soocool-delivery-schedule-slot__details', role: 'region', 'aria-labelledby': slotButtonId },
                          el(c.ToggleControl, { label: __('Actief', 'soocool-for-woocommerce'), checked: slot.enabled !== false, onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'enabled', v); } }),
                          el(c.TextControl, { label: __('Label', 'soocool-for-woocommerce'), placeholder: __('Optioneel', 'soocool-for-woocommerce'), value: slot.label || '', onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'label', v); } }),
                          el(c.SelectControl, { label: __('Type dagdeel', 'soocool-for-woocommerce'), help: __('Het type bepaalt of de avondtoeslag geldt, onafhankelijk van gewijzigde tijden.', 'soocool-for-woocommerce'), value: slot.type || 'daytime', options: [{ label: __('Overdag', 'soocool-for-woocommerce'), value: 'daytime' }, { label: __('Avond', 'soocool-for-woocommerce'), value: 'evening' }], onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'type', v); } }),
                          el(c.TextControl, { type: 'time', label: __('Van', 'soocool-for-woocommerce'), value: slot.time_from || '08:00', onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'time_from', v); } }),
                          el(c.TextControl, { type: 'time', label: __('Tot', 'soocool-for-woocommerce'), value: slot.time_to || '18:00', onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'time_to', v); } }),
                          el(c.TextControl, { type: 'time', label: __('Cut-off', 'soocool-for-woocommerce'), value: slot.cutoff_time || slot.time_from || '08:00', onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'cutoff_time', v); } }),
                          el(c.TextControl, { type: 'number', min: 0, step: 1, label: __('Volgorde', 'soocool-for-woocommerce'), value: String(slot.sort_order == null ? (slotIndex + 1) * 10 : slot.sort_order), onChange: function(v){ updateSlot(ruleIndex, slotIndex, 'sort_order', parseInt(v, 10) || 0); } })
                        ) : null
                      );
                    })),
                    allSlots.length > 4 ? el(c.Button, { variant: 'secondary', className: 'soocool-delivery-slots-more', onClick: function(){ toggleSlotList(ruleIndex); }, 'aria-expanded': showAllSlots ? 'true' : 'false', 'aria-controls': panelId + '-slots' }, showAllSlots ? __('Minder dagdelen tonen', 'soocool-for-woocommerce') : __('Alle dagdelen tonen', 'soocool-for-woocommerce')) : null
                    );
                    })()
                  )
                ) : null
              );
            })
          )
        ),
        el(DisclosureCard, { className: 'soocool-delivery-accordion soocool-delivery-accordion--general', defaultOpen: generalHasIssue, forceOpen: generalHasIssue, title: __('Algemene instellingen', 'soocool-for-woocommerce'), description: __('Klanten kiezen een bezorgdag en daarna een dagdeel. De keuze wordt bij de order opgeslagen en in WooCommerce-e-mails getoond.', 'soocool-for-woocommerce') },
          el('div', { className: 'soocool-delivery-settings-list' },
            el('div', { className: 'soocool-delivery-setting-row soocool-delivery-setting-row--toggle' },
              el('div', { className: 'soocool-delivery-setting-copy' }, el('h4', null, __('Activeer bezorgkeuze in de checkout', 'soocool-for-woocommerce')), el('p', null, __('Schakel dit uit om terug te vallen op de bestaande delivery offset.', 'soocool-for-woocommerce'))),
              el('div', { className: 'soocool-delivery-setting-control soocool-delivery-setting-control--toggle' }, el(c.ToggleControl, { label: __('Activeer bezorgkeuze in de checkout', 'soocool-for-woocommerce'), checked: settings.checkout_delivery_enabled !== false, onChange: function(v){ var next = Object.assign({}, settings, { checkout_delivery_enabled: v }); setSettings(next); } }))
            ),
            el('div', { className: 'soocool-delivery-setting-row soocool-delivery-setting-row--toggle' },
              el('div', { className: 'soocool-delivery-setting-copy' }, el('h4', null, __('Verlopen dagdelen verbergen', 'soocool-for-woocommerce')), el('p', null, __('Verbergt verlopen dagdelen voor een rustigere checkout.', 'soocool-for-woocommerce'))),
              el('div', { className: 'soocool-delivery-setting-control soocool-delivery-setting-control--toggle' }, el(c.ToggleControl, { label: __('Verlopen dagdelen verbergen', 'soocool-for-woocommerce'), checked: settings.checkout_delivery_hide_unavailable_slots !== false, onChange: function(v){ var next = Object.assign({}, settings, { checkout_delivery_hide_unavailable_slots: v }); setSettings(next); } }))
            ),
            el('div', { className: 'soocool-delivery-setting-row soocool-delivery-setting-row--number' },
              el('div', { className: 'soocool-delivery-setting-copy' }, el('h4', null, __('Aantal dagen vooruit tonen', 'soocool-for-woocommerce')), el('p', null, __('7–92 dagen zichtbaar in checkout.', 'soocool-for-woocommerce'))),
              el('div', { className: 'soocool-delivery-setting-control soocool-delivery-setting-control--number' }, el(c.TextControl, { type: 'number', min: 7, max: 92, label: __('Aantal dagen vooruit tonen', 'soocool-for-woocommerce'), hideLabelFromVision: true, value: String(settings.checkout_delivery_days_ahead == null ? 92 : settings.checkout_delivery_days_ahead), onChange: function(v){ var next = Object.assign({}, settings, { checkout_delivery_days_ahead: v }); setSettings(next); }, 'aria-invalid': !Number.isInteger(Number(settings.checkout_delivery_days_ahead)) || Number(settings.checkout_delivery_days_ahead) < 7 || Number(settings.checkout_delivery_days_ahead) > 92 ? 'true' : 'false' }))
            ),
            el('div', { className: 'soocool-delivery-setting-row soocool-delivery-setting-row--holidays' },
              el('div', { className: 'soocool-delivery-setting-copy' }, el('h4', null, __('Geblokkeerde datums / feestdagen', 'soocool-for-woocommerce')), el('p', null, __('Komma-gescheiden datums in JJJJ-MM-DD, bijvoorbeeld 2026-12-25, 2026-12-26.', 'soocool-for-woocommerce'))),
              el('div', { className: 'soocool-delivery-setting-control soocool-delivery-setting-control--holidays' }, el(c.TextControl, { label: __('Geblokkeerde datums / feestdagen', 'soocool-for-woocommerce'), hideLabelFromVision: true, 'aria-invalid': invalidHolidayDates(settings.checkout_delivery_holidays).length ? 'true' : 'false', value: settings.checkout_delivery_holidays || '', onChange: function(v){ var next = Object.assign({}, settings, { checkout_delivery_holidays: v }); setSettings(next); } }))
            )
          )
        ),
        el(DisclosureCard, { className: 'soocool-delivery-accordion soocool-delivery-accordion--surcharges', defaultOpen: surchargeHasIssue, forceOpen: surchargeHasIssue, title: __('Bezorgtoeslagen', 'soocool-for-woocommerce'), description: __('Optionele toeslagen per afleverland en voor avondbezorging.', 'soocool-for-woocommerce') },
          el('div', { className: 'soocool-surcharge-grid' },
            el(Card, null,
              el('h3', null, __('Nederland', 'soocool-for-woocommerce')),
              el('p', { className: 'soocool-field-help' }, __('Zet een bedrag op 0 om de betreffende toeslag uit te schakelen.', 'soocool-for-woocommerce')),
              el(MoneyControl, { label: __('Standaard bezorgtoeslag Nederland', 'soocool-for-woocommerce'), value: settings.checkout_delivery_netherlands_surcharge_amount == null ? 0 : settings.checkout_delivery_netherlands_surcharge_amount, invalid: !isMoneyInputValid(settings.checkout_delivery_netherlands_surcharge_amount == null ? 0 : settings.checkout_delivery_netherlands_surcharge_amount), onChange: function(v){ setSettings(Object.assign({}, settings, { checkout_delivery_netherlands_surcharge_amount: v })); } }),
              el(MoneyControl, { label: __('Avondtoeslag Nederland', 'soocool-for-woocommerce'), value: settings.checkout_delivery_netherlands_evening_surcharge_amount == null ? 0 : settings.checkout_delivery_netherlands_evening_surcharge_amount, invalid: !isMoneyInputValid(settings.checkout_delivery_netherlands_evening_surcharge_amount == null ? 0 : settings.checkout_delivery_netherlands_evening_surcharge_amount), onChange: function(v){ setSettings(Object.assign({}, settings, { checkout_delivery_netherlands_evening_surcharge_amount: v })); } })
            ),
            el(Card, null,
              el('h3', null, __('België', 'soocool-for-woocommerce')),
              el('p', { className: 'soocool-field-help' }, __('Zet een bedrag op 0 om de betreffende toeslag uit te schakelen.', 'soocool-for-woocommerce')),
              el(MoneyControl, { label: __('Standaard bezorgtoeslag België', 'soocool-for-woocommerce'), value: settings.checkout_delivery_belgium_surcharge_amount == null ? 2 : settings.checkout_delivery_belgium_surcharge_amount, invalid: !isMoneyInputValid(settings.checkout_delivery_belgium_surcharge_amount == null ? 2 : settings.checkout_delivery_belgium_surcharge_amount), onChange: function(v){ setSettings(Object.assign({}, settings, { checkout_delivery_belgium_surcharge_amount: v })); } }),
              el(MoneyControl, { label: __('Avondtoeslag België', 'soocool-for-woocommerce'), value: settings.checkout_delivery_belgium_evening_surcharge_amount == null ? 1.5 : settings.checkout_delivery_belgium_evening_surcharge_amount, invalid: !isMoneyInputValid(settings.checkout_delivery_belgium_evening_surcharge_amount == null ? 1.5 : settings.checkout_delivery_belgium_evening_surcharge_amount), onChange: function(v){ setSettings(Object.assign({}, settings, { checkout_delivery_belgium_evening_surcharge_amount: v })); } })
            )
          ),
          el('div', { className: 'soocool-field-grid two' },
            el(c.ToggleControl, { label: __('Bezorgtoeslagen zijn belastbaar', 'soocool-for-woocommerce'), help: __('Standaard uitgeschakeld om bestaande winkelbedragen niet stil te wijzigen.', 'soocool-for-woocommerce'), checked: !!settings.checkout_delivery_fee_taxable, onChange: function(v){ setSettings(Object.assign({}, settings, { checkout_delivery_fee_taxable: v })); } }),
            el(c.TextControl, { label: __('Belastingklasse voor bezorgtoeslagen', 'soocool-for-woocommerce'), help: __('Laat leeg voor de standaardklasse; gebruik anders de WooCommerce-slug van de belastingklasse.', 'soocool-for-woocommerce'), value: settings.checkout_delivery_fee_tax_class || '', onChange: function(v){ setSettings(Object.assign({}, settings, { checkout_delivery_fee_tax_class: v })); } })
          ),
          el(Note, null, __('De checkout toont automatisch een Nederlandse toelichting wanneer voor het gekozen land of dagdeel een toeslag geldt.', 'soocool-for-woocommerce'))
        )
      ),
      el('div', { className: 'soocool-actions soocool-save-row soocool-save-row--delivery' + (s.dirty ? ' is-dirty' : '') },
        s.dirty ? el('span', { className: 'soocool-unsaved', role: 'status' }, __('Niet-opgeslagen wijzigingen', 'soocool-for-woocommerce')) : null,
        el(SaveButton, { isSaving: s.saving, disabled: !s.dirty || validationIssues.length > 0, onClick: function(){ s.save(__('Kon het bezorgschema niet opslaan. Controleer de ingevulde dagen en tijden.', 'soocool-for-woocommerce'), __('Bezorgschema opgeslagen.', 'soocool-for-woocommerce')); } }, validationIssues.length ? __('Los eerst de fouten in het schema op', 'soocool-for-woocommerce') : __('Bezorgschema opslaan', 'soocool-for-woocommerce'))
      )
    );
  }


  function AutomationScreen(){
    var s = useSettings(__('Kon de automatiseringsinstellingen niet laden.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    function upd(key, value){ var next = Object.assign({}, settings); next[key] = value; setSettings(next); }
    if (!s.loaded) {
      return el(SettingsLoadScreen, { title: __('Automatisering', 'soocool-for-woocommerce'), badge: __('Optioneel', 'soocool-for-woocommerce'), description: __('Bepaal wanneer geschikte orders automatisch worden verstuurd en beheer het logonderhoud.', 'soocool-for-woocommerce'), error: s.errorMessage, onRetry: s.reload });
    }
    var retention = Number(settings.log_retention);
    var automationIssues = Number.isInteger(retention) && retention >= 20 && retention <= 500 ? [] : [__('Het aantal te bewaren logregels moet een heel getal tussen 20 en 500 zijn.', 'soocool-for-woocommerce')];
    return el(FieldGroup, { title: __('Automatisering', 'soocool-for-woocommerce'), badge: __('Optioneel', 'soocool-for-woocommerce'), description: __('Bepaal wanneer geschikte orders automatisch worden verstuurd en beheer het logonderhoud.', 'soocool-for-woocommerce') },
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      automationIssues.length ? el(c.Notice, { status: 'error', isDismissible: false }, automationIssues[0]) : null,
      el(Card, { className: 'soocool-automation-settings-card' },
        el('div', { className: 'soocool-automation-row' },
          el('div', { className: 'soocool-automation-setting' },
            el(c.ToggleControl, { label: __('Geschikte orders automatisch naar SooCool versturen', 'soocool-for-woocommerce'), help: __('Verstuurt passende WooCommerce-orders automatisch zodra ze de gekozen status bereiken.', 'soocool-for-woocommerce'), checked: !!settings.auto_submit_enabled, onChange: function(v){ upd('auto_submit_enabled', v); } })
          ),
          el('div', { className: 'soocool-automation-field' },
            el(c.SelectControl, { label: __('Orderstatus voor automatisch verzenden', 'soocool-for-woocommerce'), help: settings.auto_submit_enabled ? __('De order wordt gesynchroniseerd zodra deze status wordt bereikt.', 'soocool-for-woocommerce') : __('Activeer automatisch versturen om deze instelling te gebruiken.', 'soocool-for-woocommerce'), disabled: !settings.auto_submit_enabled, value: settings.auto_submit_status || 'pending', options: [{ label: __('Betaling in afwachting', 'soocool-for-woocommerce'), value: 'pending' }, { label: __('In behandeling', 'soocool-for-woocommerce'), value: 'processing' }, { label: __('Afgerond', 'soocool-for-woocommerce'), value: 'completed' }, { label: __('In de wacht', 'soocool-for-woocommerce'), value: 'on-hold' }], onChange: function(v){ upd('auto_submit_status', v); } })
          )
        ),
        el('div', { className: 'soocool-automation-row' },
          el('div', { className: 'soocool-automation-setting' },
            el(c.ToggleControl, { label: __('Handmatig opnieuw versturen van gesynchroniseerde orders toestaan', 'soocool-for-woocommerce'), help: __('Laat dit uitgeschakeld, tenzij SooCool om een vervangende order of staging-hertest vraagt.', 'soocool-for-woocommerce'), checked: !!settings.allow_resubmit, onChange: function(v){ upd('allow_resubmit', v); } })
          ),
          el('div', { className: 'soocool-automation-retention' },
            el(c.TextControl, { type: 'number', min: 20, max: 500, label: __('Aantal logregels bewaren', 'soocool-for-woocommerce'), help: __('Oudere regels worden automatisch verwijderd. Kies een waarde tussen 20 en 500.', 'soocool-for-woocommerce'), value: String(settings.log_retention == null ? 100 : settings.log_retention), onChange: function(v){ upd('log_retention', v); }, 'aria-invalid': automationIssues.length ? 'true' : 'false' })
          )
        )
      ),
      el(Card, { soft: true, className: 'soocool-maintenance-card' },
        el('h3', null, __('Onderhoud', 'soocool-for-woocommerce')),
        el('p', { className: 'soocool-field-help' }, __('Plan orders met een mislukte SooCool-synchronisatie opnieuw in. Grote batches worden veilig op de achtergrond verwerkt.', 'soocool-for-woocommerce')),
        el('div', { className: 'soocool-actions soocool-maintenance-actions' },
          el(ResyncButton),
          adminConfig.ordersUrl ? el(c.Button, { variant: 'secondary', className: 'soocool-action-button', href: adminConfig.ordersUrl }, __('WooCommerce-orders openen', 'soocool-for-woocommerce')) : null
        )
      ),
      el(Note, null, __('Handmatig versturen blijft beschikbaar vanuit het WooCommerce orderscherm.', 'soocool-for-woocommerce')),
      el('div', { className: 'soocool-actions soocool-save-row' }, s.dirty ? el('span', { className: 'soocool-unsaved', role: 'status' }, __('Niet-opgeslagen wijzigingen', 'soocool-for-woocommerce')) : null, el(SaveButton, { isSaving: s.saving, disabled: !s.dirty || automationIssues.length > 0, onClick: function(){ s.save(__('Kon de automatiseringsinstellingen niet opslaan.', 'soocool-for-woocommerce'), __('Automatiseringsinstellingen opgeslagen.', 'soocool-for-woocommerce')); } }, automationIssues.length ? __('Los eerst de fout op', 'soocool-for-woocommerce') : __('Automatisering opslaan', 'soocool-for-woocommerce')))
    );
  }

  function LabelsScreen(){
    var s = useSettings(__('Kon de labelinstellingen niet laden.', 'soocool-for-woocommerce'));
    var settings = s.settings;
    var setSettings = s.setSettings;
    var format = settings.label_output || 'a6';
    function setFormat(value){ setSettings(Object.assign({}, settings, { label_output: value })); }
    if (!s.loaded) {
      return el(SettingsLoadScreen, { title: __('Verzendlabels', 'soocool-for-woocommerce'), badge: __('PDF', 'soocool-for-woocommerce'), description: __('Kies het standaardformaat voor individuele en gebundelde labeldownloads.', 'soocool-for-woocommerce'), error: s.errorMessage, onRetry: s.reload });
    }
    return el(FieldGroup, { title: __('Verzendlabels', 'soocool-for-woocommerce'), badge: __('PDF', 'soocool-for-woocommerce'), description: __('Kies het standaardformaat voor individuele en gebundelde labeldownloads.', 'soocool-for-woocommerce') },
      s.errorMessage ? el(ErrorNotice, { message: s.errorMessage }) : null,
      el('div', { className: 'soocool-label-layout' },
        el(Card, { className: 'soocool-label-format-settings-card' },
          el('div', { className: 'soocool-section-heading' }, el('span', { className: 'dashicons dashicons-tag', 'aria-hidden': true }), el('h3', null, __('Standaardformaat', 'soocool-for-woocommerce'))),
          el(c.RadioControl, { className: 'soocool-label-format-control', selected: format, options: [{ label: __('A6 — één label per PDF', 'soocool-for-woocommerce'), value: 'a6' }, { label: __('A4 — meerdere labels gebundeld', 'soocool-for-woocommerce'), value: 'collated_a4' }], onChange: setFormat }),
          el(Note, null, format === 'a6' ? __('Geschikt voor thermische A6-labelprinters en één label per download.', 'soocool-for-woocommerce') : __('Geschikt voor gewone A4-printers en gebundelde labeldownloads.', 'soocool-for-woocommerce'))
        ),
        el(Card, { soft: true, className: 'soocool-label-preview-card' },
          el('h3', null, __('Voorbeeld', 'soocool-for-woocommerce')),
          el('div', { className: 'soocool-label-preview is-' + format, role: 'img', 'aria-label': format === 'a6' ? __('Voorbeeld A6-label', 'soocool-for-woocommerce') : __('Voorbeeld A4-labelvel', 'soocool-for-woocommerce') },
            format === 'a6' ? el('div', { className: 'soocool-label-sheet' }, el('span', null, 'SooCool'), el('strong', null, 'A6'), el('i', null), el('i', null), el('i', null)) : el('div', { className: 'soocool-label-sheet-grid' }, [0,1,2,3].map(function(item){ return el('span', { key: item }, el('b', null, 'SooCool'), el('i', null)); }))
          ),
          el('p', { className: 'soocool-field-help' }, __('Labels zijn beschikbaar vanuit de orderacties en via bulkacties in het WooCommerce-orderscherm.', 'soocool-for-woocommerce')),
          adminConfig.ordersUrl ? el(c.Button, { variant: 'secondary', className: 'soocool-action-button soocool-label-orders-button', href: adminConfig.ordersUrl }, __('WooCommerce-orders openen', 'soocool-for-woocommerce')) : null
        )
      ),
      el('div', { className: 'soocool-actions soocool-save-row' },
        s.dirty ? el('span', { className: 'soocool-unsaved', role: 'status' }, __('Niet-opgeslagen wijzigingen', 'soocool-for-woocommerce')) : null,
        el(SaveButton, { isSaving: s.saving, disabled: !s.dirty, onClick: function(){ s.save(__('Kon de labelinstellingen niet opslaan.', 'soocool-for-woocommerce'), __('Verzendlabelinstellingen opgeslagen.', 'soocool-for-woocommerce')); } }, __('Verzendlabelinstellingen opslaan', 'soocool-for-woocommerce'))
      )
    );
  }

  function validLogIdentifier(value){
    var normalized = String(value == null ? '' : value).trim();
    return normalized && normalized !== '[missing]' ? normalized : '';
  }
  function logIdentifiers(log){
    var context = log && log.context ? log.context : {};
    return {
      wooCommerce: validLogIdentifier(context.wcOrderId),
      sooCool: validLogIdentifier(context.orderId)
    };
  }
  function localizedLogMessage(log){
    var message = log && log.message ? String(log.message) : '';
    var translations = {
      'Unexpected SooCool connection test error.': __('Onverwachte fout tijdens de SooCool-verbindingstest.', 'soocool-for-woocommerce'),
      'SooCool API key is missing or invalid before request.': __('De SooCool API-key ontbreekt of is ongeldig.', 'soocool-for-woocommerce'),
      'SooCool request failed.': __('De SooCool API-aanvraag is mislukt.', 'soocool-for-woocommerce'),
      'SooCool API response reached the configured size limit.': __('De SooCool API-respons overschreed de ingestelde limiet.', 'soocool-for-woocommerce'),
      'SooCool API error.': __('De SooCool API gaf een fout terug.', 'soocool-for-woocommerce'),
      'SooCool label endpoint returned a non-PDF response.': __('SooCool gaf geen geldig PDF-label terug.', 'soocool-for-woocommerce'),
      'SooCool API request completed.': __('SooCool API-aanvraag voltooid.', 'soocool-for-woocommerce'),
      'Retrying temporary SooCool API error.': __('Tijdelijke SooCool API-fout; nieuwe poging gestart.', 'soocool-for-woocommerce'),
      'SooCool admin email order label attachment skipped.': __('Orderlabel kon niet aan de beheerderse-mail worden toegevoegd.', 'soocool-for-woocommerce'),
      'SooCool admin email good label attachment skipped.': __('Goederenlabel kon niet aan de beheerderse-mail worden toegevoegd.', 'soocool-for-woocommerce'),
      'Unexpected SooCool order action error.': __('Onverwachte fout bij een SooCool-orderactie.', 'soocool-for-woocommerce')
    };
    return translations[message] || message;
  }
  function logSummary(log){
    var identifiers = logIdentifiers(log);
    var prefix = identifiers.wooCommerce
      ? __('WooCommerce-order', 'soocool-for-woocommerce') + ' #' + identifiers.wooCommerce + ' — '
      : identifiers.sooCool
        ? __('SooCool-order', 'soocool-for-woocommerce') + ' #' + identifiers.sooCool + ' — '
        : '';
    var message = localizedLogMessage(log);
    if (log.level === 'error') { return prefix + (message || __('SooCool-actie mislukt.', 'soocool-for-woocommerce')); }
    return prefix + (message || __('SooCool-activiteit voltooid.', 'soocool-for-woocommerce'));
  }
  function contextLabel(key){
    var labels = {
      action: __('Actie', 'soocool-for-woocommerce'),
      method: __('HTTP-methode', 'soocool-for-woocommerce'),
      path: __('API-route', 'soocool-for-woocommerce'),
      status: __('HTTP-status', 'soocool-for-woocommerce'),
      wcOrderId: __('WooCommerce-order', 'soocool-for-woocommerce'),
      orderId: __('Order-ID', 'soocool-for-woocommerce'),
      orderReference: __('Orderreferentie', 'soocool-for-woocommerce'),
      error: __('Foutdetail', 'soocool-for-woocommerce'),
      duration_ms: __('Duur (ms)', 'soocool-for-woocommerce'),
      attempt: __('Poging', 'soocool-for-woocommerce'),
      traceId: __('Trace-ID', 'soocool-for-woocommerce'),
      content_type: __('Content-Type', 'soocool-for-woocommerce'),
      limit: __('Responslimiet', 'soocool-for-woocommerce'),
      api_key_present: __('API-key aanwezig', 'soocool-for-woocommerce'),
      api_key_source: __('API-keybron', 'soocool-for-woocommerce'),
      api_key_status: __('API-keystatus', 'soocool-for-woocommerce'),
      api_key_length: __('API-keylengte', 'soocool-for-woocommerce'),
      header_name_sent: __('Verstuurde header', 'soocool-for-woocommerce'),
      request_url_host: __('API-host', 'soocool-for-woocommerce'),
      request_path: __('Aanvraagpad', 'soocool-for-woocommerce')
    };
    return labels[key] || String(key).replace(/_/g, ' ').replace(/([a-z])([A-Z])/g, '$1 $2');
  }
  function contextPairs(context){
    context = context || {};
    var preferredOrder = ['wcOrderId', 'orderId', 'orderReference', 'action', 'method', 'path', 'status', 'attempt', 'duration_ms', 'traceId', 'error'];
    return Object.keys(context).filter(function(key){ return context[key] !== null && typeof context[key] !== 'undefined' && context[key] !== ''; }).sort(function(a, b){
      var aIndex = preferredOrder.indexOf(a);
      var bIndex = preferredOrder.indexOf(b);
      if (aIndex === -1 && bIndex === -1) { return a.localeCompare(b); }
      if (aIndex === -1) { return 1; }
      if (bIndex === -1) { return -1; }
      return aIndex - bIndex;
    }).map(function(key){ return [key, contextLabel(key), typeof context[key] === 'object' ? JSON.stringify(context[key]) : String(context[key])]; });
  }
  function exportLogs(logs){
    var blob = new Blob([JSON.stringify(logs || [], null, 2)], { type: 'application/json;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'soocool-activiteitenlogs-' + new Date().toISOString().slice(0, 10) + '.json';
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
  }
  function RetryOrderButton(props){
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    var confirmState = useState(false);
    var confirmOpen = confirmState[0];
    var setConfirmOpen = confirmState[1];
    function retry(){
      if (busy) { return; }
      setBusy(true);
      syncOrder(props.orderId).then(function(result){ setConfirmOpen(false); emitToast(result && result.message ? result.message : __('Order opnieuw gesynchroniseerd.', 'soocool-for-woocommerce'), result && result.success === false ? 'error' : 'success'); if (props.onComplete) { props.onComplete(); } }).catch(function(error){ emitToast(error && error.message ? error.message : __('Kon de order niet opnieuw synchroniseren.', 'soocool-for-woocommerce'), 'error'); }).finally(function(){ setBusy(false); });
    }
    return el(Fragment, null,
      el(c.Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: function(){ setConfirmOpen(true); } }, __('Opnieuw proberen', 'soocool-for-woocommerce')),
      el(ConfirmDialog, { open: confirmOpen, busy: busy, title: __('Order opnieuw synchroniseren', 'soocool-for-woocommerce'), message: sprintf(__('WooCommerce-order #%s wordt opnieuw naar SooCool gestuurd.', 'soocool-for-woocommerce'), String(props.orderId)), detail: __('De normale dubbele-ordercontrole blijft actief. Geforceerd opnieuw versturen wordt niet gebruikt.', 'soocool-for-woocommerce'), confirmLabel: __('Opnieuw proberen', 'soocool-for-woocommerce'), onCancel: function(){ setConfirmOpen(false); }, onConfirm: retry })
    );
  }
  function LogsList(props){
    if (!props.logs.length) { return el(Card, { soft: true }, el('p', { className: 'soocool-empty-state' }, __('Geen logs gevonden voor deze filters.', 'soocool-for-woocommerce'))); }
    return el('div', { className: 'soocool-log-list', 'aria-label': __('SooCool-activiteitenlogs', 'soocool-for-woocommerce') }, props.logs.map(function(log, index){
      var context = log.context || {};
      var pairs = contextPairs(context);
      var identifiers = logIdentifiers(log);
      var wcOrderId = /^\d+$/.test(identifiers.wooCommerce) && Number(identifiers.wooCommerce) > 0 ? identifiers.wooCommerce : '';
      var orderUrl = wcOrderId && adminConfig.ordersUrl ? adminConfig.ordersUrl + '&s=' + encodeURIComponent(wcOrderId) : '';
      var httpStatus = Number(context.status || 0);
      var httpStatusClass = httpStatus >= 200 && httpStatus < 300 ? ' is-success' : httpStatus >= 400 ? ' is-error' : '';
      return el('article', { className: 'soocool-log-entry is-' + (log.level || 'info'), key: String(log.created_at) + index },
        el('div', { className: 'soocool-log-entry__summary' },
          el('div', { className: 'soocool-log-entry__content' },
            el('h3', null, logSummary(log)),
            el('div', { className: 'soocool-log-entry__meta-row' },
              el('div', { className: 'soocool-log-entry__badges' },
                el('span', { className: 'soocool-log-level is-' + log.level }, log.level === 'error' ? __('Fout', 'soocool-for-woocommerce') : __('Info', 'soocool-for-woocommerce')),
                context.status ? el('span', { className: 'soocool-http-pill' + httpStatusClass }, 'HTTP ' + String(context.status)) : null
              ),
              el('div', { className: 'soocool-log-meta' },
                context.method ? el('span', null, context.method) : null,
                context.path ? el('code', null, context.path) : null,
                context.orderReference ? el('span', null, __('Referentie', 'soocool-for-woocommerce') + ': ' + String(context.orderReference)) : null
              )
            )
          ),
          el('time', { dateTime: log.created_at || '' }, formatDateTime(log.created_at))
        ),
        log.level === 'error' && context.error ? el('p', { className: 'soocool-log-error-detail' }, String(context.error)) : null,
        orderUrl || (log.level === 'error' && wcOrderId) ? el('div', { className: 'soocool-log-entry__actions' },
          orderUrl ? el(c.Button, { variant: 'secondary', href: orderUrl }, __('Order openen', 'soocool-for-woocommerce')) : null,
          log.level === 'error' && wcOrderId ? el(RetryOrderButton, { orderId: wcOrderId, onComplete: props.onRefresh }) : null
        ) : null,
        el('details', { className: 'soocool-log-details' },
          el('summary', null, __('Technische details', 'soocool-for-woocommerce')),
          el('div', { className: 'soocool-log-details__body' },
            pairs.length ? el('dl', null, pairs.map(function(pair){ return el('div', { key: pair[0] }, el('dt', null, pair[1]), el('dd', null, pair[2])); })) : null,
            el('div', { className: 'soocool-log-details__actions' },
              el(c.Button, { variant: 'tertiary', className: 'soocool-log-copy-button', onClick: function(){ copyText(JSON.stringify(log, null, 2)).then(function(){ emitToast(__('Logdetails gekopieerd.', 'soocool-for-woocommerce'), 'success'); }).catch(function(){ emitToast(__('Kopiëren is niet gelukt. Open de technische details en kopieer ze handmatig.', 'soocool-for-woocommerce'), 'error'); }); } }, __('Details kopiëren', 'soocool-for-woocommerce'))
            )
          )
        )
      );
    }));
  }

  function LogsScreen(){
    var logsState = useState([]);
    var logs = logsState[0];
    var setLogs = logsState[1];
    var loadingState = useState(false);
    var loading = loadingState[0];
    var setLoading = loadingState[1];
    var listBusyState = useState(false);
    var listBusy = listBusyState[0];
    var setListBusy = listBusyState[1];
    var requestSequence = useRef(0);
    var busyState = useState(false);
    var busy = busyState[0];
    var setBusy = busyState[1];
    var busyActionState = useState('');
    var busyAction = busyActionState[0];
    var setBusyAction = busyActionState[1];
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
    var levelState = useState('');
    var level = levelState[0];
    var setLevel = levelState[1];
    var searchState = useState('');
    var search = searchState[0];
    var setSearch = searchState[1];
    var orderState = useState('');
    var orderId = orderState[0];
    var setOrderId = orderState[1];
    var fromState = useState('');
    var dateFrom = fromState[0];
    var setDateFrom = fromState[1];
    var toState = useState('');
    var dateTo = toState[0];
    var setDateTo = toState[1];
    var clearState = useState(false);
    var clearOpen = clearState[0];
    var setClearOpen = clearState[1];
    var pageSize = 50;
    function filters(){ return { level: level, search: search.trim(), orderId: Number(orderId) || '', dateFrom: dateFrom, dateTo: dateTo }; }
    function normalize(response){
      if (Array.isArray(response)) { return { items: response, total: response.length, has_more: false }; }
      response = response || {};
      return { items: Array.isArray(response.items) ? response.items : [], total: Number(response.total || 0), has_more: !!response.has_more };
    }
    function refresh(silent){
      var requestId = ++requestSequence.current;
      setListBusy(true); setLoading(!loaded); setErrorMessage('');
      if (!silent) { setBusy(true); setBusyAction('refresh'); }
      getLogs(pageSize, 0, filters()).then(function(response){
        if (requestId !== requestSequence.current) { return; }
        var next = normalize(response); setLogs(next.items); setTotal(next.total); setHasMore(next.has_more);
        if (loaded && !silent) { emitToast(__('Logs vernieuwd.', 'soocool-for-woocommerce'), 'success'); }
      }).catch(function(error){
        if (requestId !== requestSequence.current) { return; }
        var message = error && error.message ? error.message : __('Kon de logs niet laden.', 'soocool-for-woocommerce');
        setErrorMessage(message); if (!silent) { emitToast(message, 'error'); }
      }).finally(function(){
        if (requestId !== requestSequence.current) { return; }
        setListBusy(false); setLoading(false); setLoaded(true);
        if (!silent) { setBusy(false); setBusyAction(''); }
      });
    }
    function loadMore(){
      if (busy || listBusy || !hasMore) { return; }
      var requestId = ++requestSequence.current;
      setBusy(true); setListBusy(true); setBusyAction('load-more'); setErrorMessage('');
      getLogs(pageSize, logs.length, filters()).then(function(response){
        if (requestId !== requestSequence.current) { return; }
        var next = normalize(response); setLogs(logs.concat(next.items)); setTotal(next.total); setHasMore(next.has_more);
      }).catch(function(error){
        if (requestId !== requestSequence.current) { return; }
        var message = error && error.message ? error.message : __('Kon meer logs niet laden.', 'soocool-for-woocommerce'); setErrorMessage(message); emitToast(message, 'error');
      }).finally(function(){
        if (requestId !== requestSequence.current) { return; }
        setBusy(false); setListBusy(false); setBusyAction('');
      });
    }
    function exportFiltered(){
      if (busy || !total) { return; }
      var exportFilters = filters();
      var exported = [];
      setBusy(true); setBusyAction('export'); setErrorMessage('');
      function loadBatch(offset){
        return getLogs(100, offset, exportFilters).then(function(response){
          var next = normalize(response);
          exported = exported.concat(next.items);
          if (next.has_more && !next.items.length) {
            throw new Error(__('De logexport stopte omdat de server geen volgende resultaten teruggaf.', 'soocool-for-woocommerce'));
          }
          if (next.has_more && exported.length < next.total) {
            return loadBatch(exported.length);
          }
          return exported;
        });
      }
      loadBatch(0).then(function(items){ exportLogs(items); emitToast(String(items.length) + ' ' + __('gefilterde logregels geëxporteerd.', 'soocool-for-woocommerce'), 'success'); }).catch(function(error){ var message = error && error.message ? error.message : __('Kon de gefilterde logs niet exporteren.', 'soocool-for-woocommerce'); setErrorMessage(message); emitToast(message, 'error'); }).finally(function(){ setBusy(false); setBusyAction(''); });
    }
    function clear(){ if (busy) { return; } requestSequence.current += 1; setListBusy(false); setBusy(true); setBusyAction('clear'); setErrorMessage(''); clearLogs().then(function(){ setLogs([]); setTotal(0); setHasMore(false); setClearOpen(false); emitToast(__('Logs gewist.', 'soocool-for-woocommerce'), 'success'); }).catch(function(error){ var message = error && error.message ? error.message : __('Kon de logs niet wissen.', 'soocool-for-woocommerce'); setErrorMessage(message); emitToast(message, 'error'); }).finally(function(){ setBusy(false); setBusyAction(''); }); }
    function resetFilters(){ setLevel(''); setSearch(''); setOrderId(''); setDateFrom(''); setDateTo(''); }
    useEffect(function(){ refresh(true); }, []);
    useEffect(function(){
      if (!loaded) { return; }
      var timer = setTimeout(function(){ refresh(true); }, 350);
      return function(){ clearTimeout(timer); };
    }, [level, search, orderId, dateFrom, dateTo]);
    return el(FieldGroup, { title: __('Activiteitenlogs', 'soocool-for-woocommerce'), description: __('Zoek op order, melding of fout. Technische details blijven ingeklapt.', 'soocool-for-woocommerce') },
      errorMessage ? el(ErrorNotice, { message: errorMessage }) : null,
      el('p', { className: 'soocool-field-help soocool-log-privacy-note' }, __('Geheime waarden en volledige aanvragen worden niet gelogd. Controleer definitieve details in ordernotities en het SooCool-portaal.', 'soocool-for-woocommerce')),
      el(Card, { soft: true, className: 'soocool-log-filter-card' },
        el('div', { className: 'soocool-log-filters soocool-log-filters--primary' },
          el('div', { className: 'soocool-log-filter-field soocool-log-filter-field--search' },
            el(c.TextControl, { disabled: busy, label: __('Zoeken', 'soocool-for-woocommerce'), placeholder: __('Melding, route of referentie', 'soocool-for-woocommerce'), value: search, onChange: setSearch })
          ),
          el('div', { className: 'soocool-log-filter-field soocool-log-filter-field--id' },
            el(c.TextControl, { disabled: busy, type: 'number', min: 1, label: __('Order-ID', 'soocool-for-woocommerce'), value: orderId, onChange: setOrderId })
          ),
          el('div', { className: 'soocool-log-filter-field soocool-log-filter-field--level' },
            el(c.SelectControl, { className: 'soocool-log-level-control', disabled: busy, label: __('Niveau', 'soocool-for-woocommerce'), value: level, options: [{ label: __('Alles', 'soocool-for-woocommerce'), value: '' }, { label: __('Alleen fouten', 'soocool-for-woocommerce'), value: 'error' }, { label: __('Alleen info', 'soocool-for-woocommerce'), value: 'info' }], onChange: setLevel })
          )
        ),
        el('fieldset', { className: 'soocool-log-period' },
          el('legend', null, __('Periode', 'soocool-for-woocommerce')),
          el('div', { className: 'soocool-log-period-fields' },
            el('div', { className: 'soocool-log-filter-field' },
              el(c.TextControl, { disabled: busy, type: 'date', label: __('Vanaf', 'soocool-for-woocommerce'), value: dateFrom, onChange: setDateFrom })
            ),
            el('div', { className: 'soocool-log-filter-field' },
              el(c.TextControl, { disabled: busy, type: 'date', label: __('Tot en met', 'soocool-for-woocommerce'), value: dateTo, onChange: setDateTo })
            )
          )
        ),
        el('div', { className: 'soocool-log-toolbar' },
          el('div', { className: 'soocool-log-toolbar__primary' },
            el(c.Button, { variant: 'secondary', className: 'soocool-log-refresh', isBusy: (busyAction === 'refresh' && loaded) || listBusy, disabled: busy || listBusy, onClick: function(){ refresh(false); } }, __('Vernieuwen', 'soocool-for-woocommerce')),
            el(c.Button, { variant: 'tertiary', onClick: resetFilters, disabled: !level && !search && !orderId && !dateFrom && !dateTo }, __('Filters wissen', 'soocool-for-woocommerce'))
          ),
          el('div', { className: 'soocool-log-toolbar__secondary' },
            el(c.Button, { variant: 'tertiary', isBusy: busyAction === 'export', disabled: busy || listBusy || !total, onClick: exportFiltered }, __('Gefilterde logs exporteren', 'soocool-for-woocommerce')),
            el(c.Button, { variant: 'tertiary', className: 'soocool-danger-action', disabled: busy || listBusy || !total, onClick: function(){ setClearOpen(true); } }, __('Alle logs wissen', 'soocool-for-woocommerce'))
          )
        )
      ),
      loading ? el(Loading, { message: __('Activiteitenlogs laden…', 'soocool-for-woocommerce') }) : null,
      !loading ? el(LogsList, { logs: logs, onRefresh: function(){ refresh(true); } }) : null,
      !loading && (total > 0 || hasMore) ? el('div', { className: 'soocool-log-footer' },
        el('p', { className: 'soocool-field-help soocool-log-count' }, String(logs.length) + ' / ' + String(total || logs.length) + ' ' + __('resultaten getoond.', 'soocool-for-woocommerce')),
        hasMore ? el(c.Button, { variant: 'primary', className: 'soocool-primary-action soocool-load-more', isBusy: busyAction === 'load-more', disabled: busy || listBusy, onClick: loadMore }, __('Meer laden', 'soocool-for-woocommerce')) : null
      ) : null,
      el(ConfirmDialog, { open: clearOpen, busy: busyAction === 'clear', destructive: true, title: __('Alle activiteitenlogs wissen', 'soocool-for-woocommerce'), message: __('Dit verwijdert alle lokaal opgeslagen SooCool-logregels permanent.', 'soocool-for-woocommerce'), detail: __('WooCommerce-ordernotities en ordermetadata blijven behouden.', 'soocool-for-woocommerce'), confirmLabel: __('Logs definitief wissen', 'soocool-for-woocommerce'), onCancel: function(){ setClearOpen(false); }, onConfirm: clear })
    );
  }

  var tabs = [
    { name: 'connection', title: __('Overzicht', 'soocool-for-woocommerce') },
    { name: 'mapping', title: __('Ophalen & bezorgen', 'soocool-for-woocommerce') },
    { name: 'delivery_days', title: __('Bezorgdagen', 'soocool-for-woocommerce') },
    { name: 'automation', title: __('Automatisering', 'soocool-for-woocommerce') },
    { name: 'labels', title: __('Verzendlabels', 'soocool-for-woocommerce') },
    { name: 'logs', title: __('Activiteitenlogs', 'soocool-for-woocommerce') }
  ];
  function activeFromHash(){ var hash = (window.location.hash || '').replace('#', ''); if (hash === 'overview') { return 'connection'; } return tabs.some(function(tab){ return tab.name === hash; }) ? hash : 'connection'; }
  function useHorizontalOverflow(dependency){
    var ref = useRef(null);
    var state = useState({ before: false, after: false });
    var flags = state[0];
    var setFlags = state[1];
    useEffect(function(){
      var node = ref.current;
      if (!node) { return; }
      var scroller = node.querySelector('.components-tab-panel__tabs');
      if (!scroller) { return; }
      function update(){
        var before = scroller.scrollLeft > 2;
        var after = scroller.scrollLeft + scroller.clientWidth < scroller.scrollWidth - 2;
        setFlags(function(current){
          return current.before === before && current.after === after ? current : { before: before, after: after };
        });
      }
      var observer = typeof ResizeObserver === 'function' ? new ResizeObserver(update) : null;
      scroller.addEventListener('scroll', update, { passive: true });
      window.addEventListener('resize', update);
      if (observer) { observer.observe(scroller); }
      update();
      var frame = window.requestAnimationFrame ? window.requestAnimationFrame(update) : 0;
      return function(){
        scroller.removeEventListener('scroll', update);
        window.removeEventListener('resize', update);
        if (observer) { observer.disconnect(); }
        if (frame && window.cancelAnimationFrame) { window.cancelAnimationFrame(frame); }
      };
    }, [dependency]);
    return { ref: ref, before: flags.before, after: flags.after };
  }
  function replaceTabHash(name){ if (name === 'connection') { window.history.replaceState(null, '', window.location.pathname + window.location.search); } else { window.history.replaceState(null, '', '#' + name); } }
  function renderTabContent(active){ if (active === 'mapping') { return el(MappingScreen); } if (active === 'delivery_days') { return el(DeliveryDaysScreen); } if (active === 'automation') { return el(AutomationScreen); } if (active === 'labels') { return el(LabelsScreen); } if (active === 'logs') { return el(LogsScreen); } return el(ConnectionScreen); }
  function App(){
    var activeState = useState(activeFromHash());
    var active = activeState[0];
    var setActive = activeState[1];
    var pendingState = useState('');
    var pendingTab = pendingState[0];
    var setPendingTab = pendingState[1];
    var discardState = useState(false);
    var discardOpen = discardState[0];
    var setDiscardOpen = discardState[1];
    var tabOverflow = useHorizontalOverflow(active);
    useEffect(function(){
      var activeTab = document.getElementById('soocool-tab-' + active);
      if (activeTab && activeTab.scrollIntoView) {
        activeTab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      }
    }, [active]);
    useEffect(function(){
      function onHashChange(){
        var next = activeFromHash();
        if (next === active) { return; }
        if (unsavedSettings.dirty) {
          replaceTabHash(active);
          setPendingTab(next);
          setDiscardOpen(true);
          return;
        }
        setActive(next);
      }
      window.addEventListener('hashchange', onHashChange);
      return function(){ window.removeEventListener('hashchange', onHashChange); };
    }, [active]);
    function applyTab(name){ unsavedSettings.dirty = false; setActive(name); replaceTabHash(name); scrollToTop(); }
    function selectTab(name){
      if (name === active) { return true; }
      if (unsavedSettings.dirty) {
        setPendingTab(name);
        setDiscardOpen(true);
        return false;
      }
      applyTab(name);
      return true;
    }
    function discardChanges(){ var next = pendingTab; setDiscardOpen(false); setPendingTab(''); if (next) { applyTab(next); } }
    return el('main', { className: 'soocool-shell', 'aria-label': __('SooCool for WooCommerce instellingen', 'soocool-for-woocommerce') },
      el(ToastHost),
      el('section', { className: 'soocool-panel soocool-tabs', 'aria-label': __('SooCool-instellingen', 'soocool-for-woocommerce') },
        el('div', { ref: tabOverflow.ref, className: 'soocool-tabs__nav' + (tabOverflow.before ? ' has-overflow-before' : '') + (tabOverflow.after ? ' has-overflow-after' : '') },
          el('div', { className: 'components-tab-panel__tabs', role: 'tablist', 'aria-label': __('SooCool-instellingensecties', 'soocool-for-woocommerce'), 'aria-orientation': 'horizontal' },
            tabs.map(function(tab){
              var selected = active === tab.name;
              return el(c.Button, { key: tab.name, role: 'tab', id: 'soocool-tab-' + tab.name, 'aria-selected': selected, 'aria-controls': 'soocool-panel-' + tab.name, tabIndex: selected ? 0 : -1, className: 'soocool-tab' + (selected ? ' is-active' : ''), onClick: function(){ selectTab(tab.name); }, onKeyDown: function(event){
                var index = tabs.findIndex(function(item){ return item.name === tab.name; });
                var nextIndex = index;
                if (event.key === 'ArrowRight') { nextIndex = (index + 1) % tabs.length; }
                if (event.key === 'ArrowLeft') { nextIndex = (index - 1 + tabs.length) % tabs.length; }
                if (event.key === 'Home') { nextIndex = 0; }
                if (event.key === 'End') { nextIndex = tabs.length - 1; }
                if (nextIndex !== index) { event.preventDefault(); if (selectTab(tabs[nextIndex].name)) { setTimeout(function(){ var next = document.getElementById('soocool-tab-' + tabs[nextIndex].name); if (next && next.focus) { next.focus(); } }, 0); } }
              } }, tab.title);
            })
          )
        ),
        el('div', { className: 'components-tab-panel__tab-content', role: 'tabpanel', id: 'soocool-panel-' + active, 'aria-labelledby': 'soocool-tab-' + active }, renderTabContent(active))
      ),
      el(ConfirmDialog, { open: discardOpen, busy: false, destructive: true, title: __('Niet-opgeslagen wijzigingen', 'soocool-for-woocommerce'), message: __('Je hebt wijzigingen die nog niet zijn opgeslagen.', 'soocool-for-woocommerce'), detail: __('Sla eerst op of kies hieronder om de wijzigingen te verwerpen en door te gaan.', 'soocool-for-woocommerce'), confirmLabel: __('Wijzigingen verwerpen', 'soocool-for-woocommerce'), onCancel: function(){ setDiscardOpen(false); setPendingTab(''); }, onConfirm: discardChanges })
    );
  }
  wp.element.createRoot(root).render(el(App));
})(window.wp);
