const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const source = name => fs.readFileSync(`${__dirname}/../h5p-sensei-bridge/assets/js/${name}.js`, 'utf8');
function report(i18n) {
  const keys=['date','user','lesson','h5p','score','criterion','passed','completed'];
  const dom=new JSDOM(`<div class="fkhs-report-scroll"><table id="fkhs-report-table"><thead><tr>${keys.map(k=>`<th data-key="${k}">${k}</th>`).join('')}</tr></thead><tbody>${['Ada','Bea'].map((u,i)=>`<tr data-user="${u}" data-date-iso="2026-11-0${i+1}" data-passed="${i}" data-completed="${i}"><td>${u}</td></tr>`).join('')}</tbody></table></div>`,{runScripts:'outside-only'});
  const w=dom.window;
  // jsdom does not implement native dialog modality/focus trapping; browser check covers it.
  w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
  w.HTMLDialogElement.prototype.close=function(){this.open=false;};
  if(i18n) w.fkhsAdmin={i18n};
  w.eval(source('h5p-sensei-admin-report'));
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  return {dom,w,d:w.document,filter:key=>w.document.querySelector(`th[data-key="${key}"] .fkhs-filter-icon`)};
}
test('report fallback names, initial pagination and column sorting are accessible',()=>{
  const f=report();
  assert.equal(f.filter('user').getAttribute('aria-label'),'Filter user');
  assert.equal(f.filter('h5p'),null,'No nonfunctional controls on unsupported column');
  assert.equal(f.d.querySelector('#fkhs-page-info').textContent,'1–2 / 2');
  assert.equal(f.d.querySelector('#fkhs-prev').disabled,true);
  assert.equal(f.d.querySelector('#fkhs-next').disabled,true);
  const sort=f.d.querySelector('th[data-key="user"] button');
  assert.equal(sort.getAttribute('aria-label'),'Sort by user');
  sort.click(); assert.equal(sort.closest('th').getAttribute('aria-sort'),'ascending');
  assert.equal(f.d.querySelector('th[data-key="date"]').hasAttribute('aria-sort'),false);
  f.dom.window.close();
});
test('filter opens focused, Escape cancels edits, Apply commits and restores trigger focus',()=>{
  const f=report(), trigger=f.filter('user'); trigger.focus(); trigger.click();
  let input=f.d.querySelector('dialog input');
  assert.equal(f.d.activeElement,input); assert.ok(input.labels.length);
  input.dispatchEvent(new f.w.KeyboardEvent('keydown',{key:'Tab',shiftKey:true,bubbles:true,cancelable:true}));
  assert.equal(f.d.activeElement.textContent,'Apply');
  f.d.activeElement.dispatchEvent(new f.w.KeyboardEvent('keydown',{key:'Tab',bubbles:true,cancelable:true}));
  assert.equal(f.d.activeElement,input);
  input.value='Ada'; input.dispatchEvent(new f.w.Event('input'));
  input.dispatchEvent(new f.w.KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
  assert.equal(f.d.querySelector('dialog'),null); assert.equal(f.d.activeElement,trigger);
  assert.equal(trigger.getAttribute('aria-expanded'),'false');
  trigger.click(); input=f.d.querySelector('dialog input'); assert.equal(input.value,'');
  input.value='Ada'; input.dispatchEvent(new f.w.Event('input'));
  [...f.d.querySelectorAll('dialog button')].find(b=>b.textContent==='Apply').click();
  assert.equal(f.d.activeElement,trigger); assert.equal(f.d.querySelector('#fkhs-page-info').textContent,'1–1 / 1');
  assert.equal(trigger.getAttribute('aria-label'),'Filter user (active)');
  trigger.click(); [...f.d.querySelectorAll('dialog button')].find(b=>b.textContent==='Clear').click();
  assert.equal(f.d.querySelector('#fkhs-page-info').textContent,'1–2 / 2');
  f.dom.window.close();
});
test('all filter inputs have associated labels and translated controls override English fallback',()=>{
  const f=report({filterColumn:'Filtrer %s',close:'Lukk',apply:'Bruk'});
  for(const key of ['date','score','criterion','passed','completed']) {
    f.filter(key).click();
    for(const input of f.d.querySelectorAll('dialog input, dialog select')) assert.ok(input.labels.length,key);
    assert.match(f.d.querySelector('dialog h4').textContent,/Filtrer/);
    [...f.d.querySelectorAll('dialog button')].find(b=>b.textContent==='Lukk').click();
  }
  f.dom.window.close();
});
function front(){
  const dom=new JSDOM('<main><div class="h5p-iframe-wrapper"><iframe class="h5p-iframe" data-content-id="1"></iframe></div><div class="sensei-lesson-footer"></div></main>',{runScripts:'outside-only'});
  const w=dom.window; w.fkH5P={require:true,i18n:{close:'Lukk'}};
  w.matchMedia=()=>({matches:true});
  w.HTMLElement.prototype.scrollIntoView=function(opts){this.scrolled=opts;};
  w.eval(source('h5p-sensei-bridge').replace('  //bind();','window.uiTest={render:renderStatusPanelFromItems, badge:attachBadge, overlay:attachOverlay, focus:focusActivity};'));
  return {dom,w,d:w.document,wrap:w.document.querySelector('.h5p-iframe-wrapper')};
}
test('remaining task control is a button, escapes titles and focuses the matching activity without motion',()=>{
  const f=front();
  f.w.uiTest.render([{content_id:1,title:'A & <img src=x>',threshold:70,requirement_met:false,counts_for_gate:true}]);
  const button=f.d.querySelector('.fkhs-status-link');
  assert.equal(button.tagName,'BUTTON'); assert.equal(button.type,'button');
  assert.match(button.textContent,/A & <img src=x>/); assert.equal(button.querySelector('img'),null);
  button.click(); assert.equal(f.d.activeElement,f.wrap.querySelector('iframe'));
  assert.equal(f.wrap.scrolled.behavior,'auto');
  f.dom.window.close();
});
test('retry badges and inline result controls are keyboard elements; Escape restores activity focus',()=>{
  const f=front();
  f.w.uiTest.badge(f.wrap,'Not passed','fail',()=>f.w.uiTest.focus(f.wrap));
  const badge=f.wrap.querySelector('.fkhs-h5p-badge'); assert.equal(badge.tagName,'BUTTON'); badge.click();
  assert.equal(f.d.activeElement,f.wrap.querySelector('iframe'));
  f.w.uiTest.overlay(f.wrap,'pass','Passed','1/1');
  const panel=f.wrap.querySelector('.fkhs-h5p-overlay');
  assert.equal(f.w.getComputedStyle(panel).position,'relative','Does not cover H5P');
  const close=panel.querySelector('button'); assert.equal(close.textContent,'Lukk'); assert.equal(close.type,'button');
  close.focus(); close.dispatchEvent(new f.w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
  assert.equal(f.wrap.querySelector('.fkhs-h5p-overlay'),null);
  assert.equal(f.d.activeElement,f.wrap.querySelector('iframe'));
  assert.equal(f.wrap.dataset.fkhsDismissed,'1');
  f.dom.window.close();
});
