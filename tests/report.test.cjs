const {test}=require('node:test');
const assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const fs=require('node:fs');
const source=fs.readFileSync(`${__dirname}/../h5p-sensei-bridge/assets/js/h5p-sensei-admin-report.js`,'utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function fixture(){
  const dom=new JSDOM(`<table id="fkhs-report-table"><thead><tr>${['date','user','lesson','h5p','score','criterion','passed','completed'].map(k=>`<th data-key="${k}">${k}</th>`).join('')}</tr></thead><tbody></tbody></table>`,{runScripts:'outside-only'});
  const w=dom.window, requests=[];
  w.fkhsAdmin={url:'https://example.test/?rest_route=/fkhs/v1/report',nonce:'fixture-nonce'};
  w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};
  w.HTMLDialogElement.prototype.close=function(){this.open=false;};
  w.fetch=(url,options)=>new Promise((resolve,reject)=>requests.push({url:new URL(url),options,resolve,reject}));
  w.eval(source);w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  return {w,dom,d:w.document,requests};
}
function reply(request,{total=251,page=1,per_page=50,html='<tr><td>Older record</td></tr>',ok=true}={}){
  request.resolve({ok,status:ok?200:403,json:async()=>({total,page,per_page,html})});
}
test('server report pages through history with nonce/no-store; current criterion has no misleading global controls',async()=>{
  const f=fixture(); await tick();
  assert.equal(f.requests.length,1,'DOMContentLoaded cannot double-init');
  assert.equal(f.requests[0].options.headers['X-WP-Nonce'],'fixture-nonce');
  assert.equal(f.requests[0].options.cache,'no-store');
  assert.equal(f.requests[0].url.searchParams.get('rest_route'),'/fkhs/v1/report');
  assert.equal(f.d.querySelector('th[data-key="criterion"] button'),null);
  reply(f.requests[0]);await tick();
  assert.equal(f.d.querySelector('#fkhs-page-info').textContent,'1–50 / 251');
  f.d.querySelector('#fkhs-next').click();
  assert.equal(f.requests[1].url.searchParams.get('page'),'2');
  assert.equal(f.d.querySelector('tbody').textContent,'','Old page not shown during new read');
  reply(f.requests[1],{page:2}); await tick();
  assert.equal(f.d.querySelector('#fkhs-page-info').textContent,'51–100 / 251');
  f.d.querySelector('th[data-key="user"] .fkhs-filter-icon').click();
  const input=f.d.querySelector('dialog input');input.value='Old user';input.dispatchEvent(new f.w.Event('input'));
  [...f.d.querySelectorAll('dialog button')].find(b=>b.textContent==='Apply').click();
  assert.equal(f.requests[2].url.searchParams.get('user'),'Old user');
  assert.equal(f.requests[2].url.searchParams.get('page'),'1');
  reply(f.requests[2],{total:1});await tick();
  assert.equal(f.d.querySelector('#fkhs-next').disabled,true);f.dom.window.close();
});
test('late responses cannot replace newer sort results; HTTP failures clear stale data and expose retry',async()=>{
  const f=fixture();await tick();
  f.d.querySelector('th[data-key="user"] .fkhs-sort-btn').click();
  assert.equal(f.requests[0].options.signal.aborted,true);
  reply(f.requests[1],{total:1,html:'<tr><td>New result</td></tr>'});await tick();
  reply(f.requests[0],{html:'<tr><td>Stale</td></tr>'});await tick();
  assert.equal(f.d.querySelector('tbody').textContent,'New result');
  f.d.querySelector('th[data-key="date"] .fkhs-sort-btn').click();
  reply(f.requests[2],{ok:false});await tick();
  assert.equal(f.d.querySelector('tbody').textContent,'');
  assert.match(f.d.querySelector('#fkhs-page-info').textContent,/Could not load/);
  assert.equal(f.d.querySelector('#fkhs-report-retry').hidden,false);
  assert.equal(f.d.querySelector('#fkhs-next').disabled,true);
  f.d.querySelector('#fkhs-report-retry').click();reply(f.requests[3],{total:0,html:''});await tick();
  assert.equal(f.d.querySelector('#fkhs-page-info').textContent,'0 / 0');
  assert.equal(f.d.querySelector('table').hasAttribute('aria-busy'),false);f.dom.window.close();
});
test('keyboard pagination retains focus at the last page and offers a focused recovery after failure',async()=>{
  const f=fixture();await tick();reply(f.requests[0],{total:51});await tick();
  const next=f.d.querySelector('#fkhs-next');next.focus();next.click();
  assert.equal(f.d.activeElement.id,'fkhs-page-info','Loading state retains a focus location');
  reply(f.requests[1],{total:51,page:2});await tick();
  assert.equal(next.disabled,true);assert.equal(f.d.activeElement.id,'fkhs-page-info');
  const prev=f.d.querySelector('#fkhs-prev');prev.focus();prev.click();
  reply(f.requests[2],{ok:false});await tick();
  assert.equal(f.d.activeElement.id,'fkhs-report-retry');
  f.d.activeElement.click();assert.equal(f.d.activeElement.id,'fkhs-page-info');
  reply(f.requests[3],{total:0,html:''});await tick();
  assert.equal(f.d.activeElement.id,'fkhs-page-info','Successful recovery does not focus a hidden retry button');f.dom.window.close();
});
