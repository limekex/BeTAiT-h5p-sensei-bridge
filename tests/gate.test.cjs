const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function setup() {
  const controls = [], completed = [], listeners = new Map();
  function node(kind, disabled = false) {
    const attributes = new Map(), classes = new Set();
    return {
      kind, disabled, title: '', style: { opacity: '', display: '' },
      classList: { add: x => classes.add(x), remove: x => classes.delete(x), contains: x => classes.has(x) },
      setAttribute: (k, v) => attributes.set(k, v),
      getAttribute: k => attributes.has(k) ? attributes.get(k) : null,
      removeAttribute: k => attributes.delete(k),
      matches(selector) {
        if (kind === 'legacy-form') return selector.includes('form[data-id="complete-lesson-form"]');
        if (kind === 'form') return selector.includes('form.lesson_button_form');
        if (kind === 'modern') return selector.includes('.wp-block-sensei-lms-button-complete-lesson button');
        if (kind === 'legacy') return selector.includes('[data-id="complete-lesson-button"]');
        if (kind === 'quiz') return selector.includes('a.sensei-quiz-link');
        return false;
      },
      closest(selector) {
        if (selector === 'form') return kind.endsWith('form') ? this : null;
        return this.matches(selector) ? this : null;
      },
      querySelector() { return null; },
      fire(type, key) {
        const event = { type, key, target: this, prevented: false, stopped: false,
          preventDefault(){this.prevented=true;}, stopImmediatePropagation(){this.stopped=true;} };
        for (const fn of listeners.get(type) || []) { fn(event); if(event.stopped) break; }
        return event;
      }
    };
  }
  const link = node('quiz'), button = node('modern'), legacy = node('legacy');
  const externalDisabled = node('modern', true), form = node('form'), legacyForm = node('legacy-form');
  const indicator = node('completed'), unrelated = node('unrelated');
  controls.push(link, button, legacy, externalDisabled); completed.push(indicator);
  form.setAttribute('action', '/lesson/fixture'); legacyForm.setAttribute('action', '/lesson/fixture');
  const document = {
    getElementById() { return {}; },
    addEventListener(type, fn) { if (!listeners.has(type)) listeners.set(type, new Set()); listeners.get(type).add(fn); },
    querySelectorAll(selector) {
      if (selector === '.wp-block-sensei-lms-button-lesson-completed button') return completed;
      return controls.filter(n => n.matches(selector));
    }
  };
  const context = { document, window: { fkH5P: { require: true, bypass: false } }, console, setTimeout, clearTimeout };
  const source = fs.readFileSync(process.env.BRIDGE_SOURCE || path.join(__dirname, '../h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js'), 'utf8');
  vm.runInNewContext(source.replace('  //bind();', 'window.testGate = setQuizButtonVisibility;'), context);
  return { ...context, controls, node, listeners, link, button, legacy, externalDisabled, indicator, unrelated, form, legacyForm, update: context.window.testGate };
}

test('repeated renders bind capture handlers once; modern and screen-reader actions share the gate', () => {
  const f = setup();
  for (let i=0;i<100;i++) f.update(false);
  assert.equal([...f.listeners.values()].reduce((n,s)=>n+s.size,0),3);
  for (const control of [f.link,f.button,f.legacy]) {
    assert.equal(control.getAttribute('aria-disabled'),'true');
    for(const [type,key] of [['click'],['keydown','Enter'],['keydown',' ']]) {
      const event=control.fire(type,key); assert.equal(event.prevented,true); assert.equal(event.stopped,true);
    }
    assert.equal(control.fire('keydown','Tab').prevented,false);
  }
  assert.equal(f.form.fire('submit').prevented,true);
  assert.equal(f.legacyForm.fire('submit').prevented,true);
  assert.equal(f.unrelated.fire('click').prevented,false);
  f.update(true);
  for(const control of [f.link,f.button,f.legacy]) {
    assert.equal(control.getAttribute('aria-disabled'),null);
    assert.equal(control.fire('click').prevented,false);
  }
  assert.equal(f.form.fire('submit').prevented,false);
  assert.equal(f.externalDisabled.disabled,true);
  assert.equal(f.indicator.disabled,true);
});

test('bypass and requirement removal release only the bridge gate', () => {
  for (const mode of ['bypass','require']) {
    const f=setup(); f.update(false); f.window.fkH5P[mode]=mode==='bypass'; f.update(false);
    assert.equal(f.link.fire('click').prevented,false);
    assert.equal(f.button.disabled,false); assert.equal(f.externalDisabled.disabled,true);
  }
});

test('Sensei disabled and title changes during a lock survive unlocking', () => {
  const f=setup(); f.update(false);
  assert.equal(f.button.disabled,false,'Native disabled belongs to Sensei, not the bridge');
  f.button.disabled=true; f.button.title='Lesson is completed';
  f.externalDisabled.disabled=false;
  f.update(true);
  assert.equal(f.button.disabled,true); assert.equal(f.button.title,'Lesson is completed');
  assert.equal(f.externalDisabled.disabled,false);
});

test('new controls are blocked before a render; detached controls lose bridge decoration', () => {
  const f=setup(); f.update(false);
  const added=f.node('legacy'); f.controls.push(added);
  assert.equal(added.fire('click').prevented,true);
  f.update(false); assert.equal(added.getAttribute('aria-disabled'),'true');
  f.controls.splice(f.controls.indexOf(added),1); f.update(false);
  assert.equal(added.getAttribute('aria-disabled'),null);
  assert.equal(added.classList.contains('fkhs-locked'),false);
});
