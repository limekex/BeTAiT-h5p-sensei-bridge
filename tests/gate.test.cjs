const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function setup() {
  function node(disabled = false) {
    const attributes = new Map(), classes = new Set(), events = new Map();
    return {
      disabled, title: '', style: { opacity: '', display: '' },
      classList: { add: x => classes.add(x), remove: x => classes.delete(x), contains: x => classes.has(x) },
      setAttribute: (k, v) => attributes.set(k, v),
      getAttribute: k => attributes.has(k) ? attributes.get(k) : null,
      removeAttribute: k => attributes.delete(k),
      addEventListener(k, fn) { if (!events.has(k)) events.set(k, new Set()); events.get(k).add(fn); },
      removeEventListener(k, fn) { events.get(k)?.delete(fn); },
      count() { return [...events.values()].reduce((n, s) => n + s.size, 0); },
      closest(selector) { return selector === 'button,a' || selector === 'button, a' || selector === 'a,button' ? this : null; },
      fire(kind, key) {
        let prevented = false;
        const e = { target: this, key, preventDefault() { prevented = true; }, stopPropagation() {} };
        for (const fn of events.get(kind) || []) fn(e);
        return prevented;
      }
    };
  }
  const link = node(), button = node(), externalDisabled = node(true), form = node(), host = node();
  form.setAttribute('action', '/quiz/1');
  const document = {
    getElementById() { return {}; }, // Styles already exist; no page initialization needed.
    addEventListener() {},
    querySelectorAll(selector) {
      if (selector.startsWith('.sensei-lesson-actions a')) return [link];
      if (selector.startsWith('.wp-block-sensei-lms-button-view-quiz button')) return [button, externalDisabled];
      if (selector.startsWith('form.lesson_button_form,')) return [form];
      if (selector.startsWith('.sensei-buttons-container,')) return [host];
      return [];
    }
  };
  const context = { document, window: { fkH5P: { require: true, bypass: false } }, console, setTimeout, clearTimeout };
  const source = fs.readFileSync(process.env.BRIDGE_SOURCE || path.join(__dirname, '../h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js'), 'utf8');
  // Expose the real closure to the fixture only; production exports remain unchanged.
  vm.runInNewContext(source.replace('  //bind();', 'window.testGate = setQuizButtonVisibility;'), context);
  return { ...context, link, button, externalDisabled, form, host, update: context.window.testGate };
}

test('100 locked renders register one handler per event; unlock removes every blocker', () => {
  const f = setup();
  for (let i = 0; i < 100; i++) f.update(false);
  assert.equal(f.link.count(), 2);
  assert.equal(f.form.count(), 1);
  assert.equal(f.host.count(), 1);
  assert.equal(f.link.fire('click'), true);
  assert.equal(f.link.fire('keydown', 'Enter'), true);
  assert.equal(f.link.fire('keydown', ' '), true);
  assert.equal(f.form.fire('submit'), true);
  f.update(true);
  for (const el of [f.link, f.button, f.form, f.host]) assert.equal(el.count(), 0);
  assert.equal(f.link.fire('click'), false);
  assert.equal(f.form.fire('submit'), false);
  assert.equal(f.button.disabled, false);
  assert.equal(f.externalDisabled.disabled, true);
  f.update(false);
  assert.equal(f.link.fire('click'), true);
});

test('bypass and removed requirement unlock previously locked controls', () => {
  for (const mode of ['bypass', 'require']) {
    const f = setup(); f.update(false);
    f.window.fkH5P[mode] = mode === 'bypass';
    f.update(false);
    assert.equal(f.link.fire('click'), false);
    assert.equal(f.button.disabled, false);
    assert.equal(f.externalDisabled.disabled, true);
  }
});
