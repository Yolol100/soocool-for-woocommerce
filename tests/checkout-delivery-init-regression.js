'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../assets/frontend/checkout-delivery.js'), 'utf8');

function mount(readyState, withJquery = false) {
  const listeners = {};
  let queries = 0;
  let checkoutUpdated = null;
  const document = {
    readyState,
    body: {},
    addEventListener(name, callback) {
      (listeners[name] || (listeners[name] = [])).push(callback);
    },
    querySelectorAll(selector) {
      assert.equal(selector, '.soocool-delivery-options');
      queries += 1;
      return [];
    }
  };
  const window = {};
  if (withJquery) {
    window.jQuery = function (element) {
      assert.equal(element, document.body);
      return {
        on(name, callback) {
          assert.equal(name, 'updated_checkout');
          checkoutUpdated = callback;
        }
      };
    };
  }

  vm.runInNewContext(source, { document, window }, { filename: 'checkout-delivery.js' });

  return {
    listeners,
    queryCount: () => queries,
    triggerDOMContentLoaded() {
      for (const callback of listeners.DOMContentLoaded || []) {
        callback();
      }
    },
    triggerCheckoutUpdated() {
      assert.equal(typeof checkoutUpdated, 'function');
      checkoutUpdated();
    }
  };
}

// WordPress can load checkout scripts after DOMContentLoaded (e.g. async asset optimizers).
const complete = mount('complete');
assert.equal(complete.queryCount(), 1, 'Late-loaded checkout script must initialize immediately');
assert.equal((complete.listeners.DOMContentLoaded || []).length, 0);

const interactive = mount('interactive');
assert.equal(interactive.queryCount(), 1, 'Already parsed document must initialize immediately');

const loading = mount('loading', true);
assert.equal(loading.queryCount(), 0, 'Do not initialize before DOMContentLoaded');
assert.equal((loading.listeners.DOMContentLoaded || []).length, 1);
loading.triggerDOMContentLoaded();
assert.equal(loading.queryCount(), 1, 'Initialize exactly once after DOMContentLoaded');
loading.triggerCheckoutUpdated();
assert.equal(loading.queryCount(), 2, 'AJAX checkout fragment updates must reinitialize controls');

console.log('SooCool checkout initialization and refresh regression checks passed.');
