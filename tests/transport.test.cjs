const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const tick = async () => { await new Promise(setImmediate); await new Promise(setImmediate); };
const status = (passed = true) => ({ ok: true, items: [], required: true, passed });
const response = (json, code = 200) => ({ ok: code >= 200 && code < 300, status: code, json: async () => json });
function deferred() { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return { promise, resolve, reject }; }

function setup(readyState, options = {}) {
  const nodes = new Map(), requests = [], timers = new Map();
  const delays = new Map();
  const documentListeners = new Map(), bindings = [];
  let sequence = 0, timerSequence = 0;
  let now = 0;
  function element(tag) {
    const attrs = new Map();
    return { tag, children: [], style: {}, classList: {add(){},remove(){}},
      set id(v) { this._id=v; nodes.set(v,this); }, get id() {return this._id;},
      setAttribute(k,v){ attrs.set(k,v); }, getAttribute(k){return attrs.get(k) ?? null;},
      appendChild(el){ if(el.parentNode) el.parentNode.children=el.parentNode.children.filter(x=>x!==el); this.children.push(el); el.parentNode=this; return el; },
      insertBefore(el){ return this.appendChild(el); }, replaceChildren(){ this.children=[]; },
      addEventListener(name, fn){ this[name]=fn; }, querySelectorAll(){return [];}
    };
  }
  const body=element('body');
  if(options.redirect) { const notice=element('div'); notice.id='fkhs-redirect-notice'; body.appendChild(notice); }
  const document={ body, readyState, head:element('head'), createElement:element,
    addEventListener(type,fn){ documentListeners.set(type,fn); },
    getElementById(id){ return id.endsWith('-css') ? {} : nodes.get(id); },
    querySelector(selector){return selector.startsWith('main,') ? body : null;}, querySelectorAll(){return [];} };
  const window={ fkH5P:{lessonId:10,restUrl:'/wp-json/fkhs/v1/h5p-xapi',nonce:'nonce',require:true,loginUrl:'/login'},
    H5P:{externalDispatcher:{on(type,fn){bindings.push({type,fn});}}},
    crypto:{randomUUID:()=>`00000000-0000-4000-8000-${String(++sequence).padStart(12,'0')}`}, addEventListener(){} };
  const runtime=window.H5P;
  if(options.runtime === false) delete window.H5P;
  if(options.require === false) window.fkH5P.require=false;
  const context={window,document,H5P:window.H5P,Date:{now:()=>now},console,AbortController,Uint8Array,MutationObserver:class {observe(){}},getComputedStyle:()=>({display:'block'}),
    setTimeout(fn,delay){ const id=++timerSequence; timers.set(id,fn); delays.set(id,delay); return id; }, clearTimeout(id){timers.delete(id); delays.delete(id);},
    fetch(url,options){ const d=deferred(); requests.push({url,options,...d}); return d.promise; } };
  const source=fs.readFileSync(path.join(__dirname,'../h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js'),'utf8');
  vm.runInNewContext(source.replace('  //bind();', `window.test = { post: postXAPI, refresh: fetchAndRenderH5PStatus, retry: retryTransport,
    extract: extractContentId, onXAPI, startRuntime:startH5PTracking, state: () => ({runtime:fkhsRuntimeState,pending:fkhsQueue.length, locked:fkhsGateLocked,
    saveError:fkhsSaveError, statusError:fkhsStatusError, inputError:fkhsInputError, lastStored:fkhsLastStored}) };`),context);
  return { ...window.test, window, requests, timers, bindings, documentListeners, runtime,
    advance(ms){now+=ms;},
    runTimer(delay){ const entry=[...timers].find(([id])=>delays.get(id)===delay); assert.ok(entry,`Pending ${delay}ms timer`); const [id,fn]=entry; timers.delete(id); delays.delete(id); fn(); },
    panel:()=>nodes.get('fkhs-status-panel'), notice:()=>nodes.get('fkhs-save-status'),
    redirect:()=>nodes.get('fkhs-redirect-notice'),
    message:()=>nodes.get('fkhs-save-status')?.children.map(x=>x.textContent||'').join(' ') };
}
const statement={verb:{id:'http://adlnet.gov/expapi/verbs/completed'},result:{completion:true}};

test('startup works both before and after DOMContentLoaded',async()=>{
  for (const ready of ['loading','complete']) {
    const f=setup(ready);
    if(ready==='loading') {
      assert.equal(f.requests.length,0);
      f.documentListeners.get('DOMContentLoaded')();
    }
    assert.equal(f.bindings.length,1);
    assert.equal(f.bindings[0].type,'xAPI');
    assert.equal(f.requests.length,1);
    f.requests[0].resolve(response(status())); await tick();
    assert.equal(f.state().locked,false);
  }
});

test('missing H5P stops polling after 15 seconds and explicit retry binds once',async()=>{
  const f=setup('complete',{runtime:false});
  f.requests[0].resolve(response({...status(),required:false})); await tick();
  assert.equal(f.state().locked,true,'Optional/server-passed status cannot hide a tracking failure');
  assert.match(f.message(),/Waiting for H5P/);
  for(let i=0;i<50;i++) f.runTimer(300);
  assert.equal(f.timers.size,0); assert.equal(f.state().runtime,'failed');
  assert.match(f.message(),/could not start/);
  f.notice().children[1].click();
  f.advance(16000); f.runTimer(300);
  assert.equal(f.state().runtime,'failed','A throttled/background tab stops on the first callback past its deadline');
  assert.equal(f.timers.size,0);
  f.window.H5P=f.runtime;
  assert.equal(f.bindings.length,0,'Arrival after timeout does not resume automatically');
  f.notice().children[1].click();
  f.startRuntime(true);
  assert.equal(f.bindings.length,1); assert.equal(f.requests.length,2);
  assert.equal(f.state().locked,true,'Refresh remains pending');
  f.requests[1].resolve(response(status())); await tick();
  assert.equal(f.state().locked,false); assert.equal(f.timers.size,0);
});

test('late H5P arrival refreshes progress without duplicate listeners or timers',async()=>{
  const f=setup('complete',{runtime:false});
  f.requests[0].resolve(response(status())); await tick();
  f.runTimer(300); f.startRuntime(true);
  assert.equal(f.timers.size,1,'Retry while waiting does not start another loop');
  f.window.H5P=f.runtime; f.runTimer(300);
  assert.equal(f.bindings.length,1); assert.equal(f.state().runtime,'ready');
  f.requests[1].resolve(response(status(false))); await tick();
  assert.equal(f.state().locked,true,'A ready dispatcher alone does not pass the activity');
  assert.equal(f.timers.size,0);
});

test('lessons without H5P do not wait for a dispatcher',async()=>{
  const f=setup('complete',{runtime:false,require:false});
  f.requests[0].resolve(response({...status(),required:false})); await tick();
  assert.equal(f.state().runtime,'unused'); assert.equal(f.state().locked,false);
  assert.equal(f.timers.size,0);
});

test('failed POST is visible, blocks continuation, and retries an identical frozen payload', async()=>{
  const f=setup(undefined,{redirect:true}); const mutable=structuredClone(statement); const p=f.post(mutable,1);
  mutable.result.completion=false;
  assert.equal(f.state().locked,true);
  const body=f.requests[0].options.body;
  assert.equal(JSON.parse(body).statement.result.completion,true);
  f.requests[0].resolve(response({ok:false,reason:'storage_failed'},500));
  assert.equal(await p,false); await tick();
  assert.match(f.message(),/not been confirmed saved/);
  assert.equal(f.panel().hidden,true, 'Do not show stale permission to continue during a write failure');
  assert.equal(f.redirect().hidden,false);
  assert.equal(f.redirect().parentNode,f.panel().parentNode,'Redirect warning belongs with feedback, not over the activity');
  assert.equal(f.notice().children[1].tag,'button');
  const retry=f.retry();
  assert.equal(f.requests[1].options.body,body);
  f.requests[1].resolve(response({ok:true,stored:true,duplicate:true,attempt_id:7})); await tick();
  assert.equal(f.state().pending,0); assert.equal(f.state().locked,true);
  f.requests[2].resolve(response(status())); await retry;
  assert.equal(f.state().locked,false); assert.match(f.message(),/activity is saved/);
  assert.equal(f.panel().hidden,false, 'Show progress again after a fresh status response');
  assert.equal(f.redirect().hidden,true,'Old blocked-quiz warning disappears once the gate is satisfied');
});

test('writes remain ordered and distinct events receive distinct identities',async()=>{
  const f=setup(); const a=f.post(statement,1); const b=f.post(statement,1);
  assert.equal(f.requests.length,1);
  f.requests[0].resolve(response({ok:true,stored:true,attempt_id:1})); await a; await tick();
  assert.equal(f.requests.length,2);
  assert.notEqual(JSON.parse(f.requests[0].options.body).event_id,JSON.parse(f.requests[1].options.body).event_id);
  f.requests[1].resolve(response({ok:true,stored:true,attempt_id:2})); await b; await tick();
  f.requests[2].resolve(response(status())); await tick();
  assert.equal(f.state().pending,0);
});

test('skipped events are not reported as saved, old server acknowledgements are not accepted',async()=>{
  const f=setup(); const p=f.post(statement,1);
  f.requests[0].resolve(response({ok:true,stored:false,skipped:true,reason:'completion_pending'})); await p; await tick();
  f.requests[1].resolve(response(status())); await tick();
  assert.equal(f.state().lastStored,false); assert.doesNotMatch(f.message(),/is saved/);
  const old=f.post(statement,1); f.requests[2].resolve(response({ok:true})); await old; await tick();
  assert.equal(f.state().pending,1); assert.equal(f.state().saveError.retryable,false);
  assert.equal(f.notice().children.some(x=>x.tag==='button'),false);
});

test('session expiry offers re-login and never silently drops the queued event',async()=>{
  const f=setup(); const p=f.post(statement,1);
  f.requests[0].resolve(response({code:'rest_cookie_invalid_nonce'},403)); await p; await tick();
  assert.equal(f.state().saveError.auth,true); assert.equal(f.state().pending,1);
  assert.equal(f.notice().children[1].href,'/login'); assert.match(f.message(),/Sign in/);
  f.retry(); assert.equal(f.requests.length,1);
});

test('newest status wins; stale success and stale failure cannot change controls',async()=>{
  const f=setup(); const old=f.refresh(); const fresh=f.refresh();
  f.requests[1].resolve(response(status(true))); await fresh;
  f.requests[0].resolve(response(status(false))); await old;
  assert.equal(f.window.fkhsGatePassed,true); assert.equal(f.state().locked,false);
  const staleFailure=f.refresh(); const newest=f.refresh();
  f.requests[3].resolve(response(status(true))); await newest;
  f.requests[2].reject(new Error('offline')); await staleFailure;
  assert.equal(f.state().statusError,null); assert.equal(f.state().locked,false);
});

test('a write invalidates earlier status reads and failed status can be retried without another POST',async()=>{
  const f=setup(); const old=f.refresh(); const p=f.post(statement,1);
  f.requests[0].resolve(response(status(true))); await old;
  assert.equal(f.state().locked,true); assert.equal(f.window.fkhsGatePassed,false);
  f.requests[1].resolve(response({ok:true,stored:true,attempt_id:3})); await p; await tick();
  f.requests[2].reject(new Error('offline')); await tick();
  assert.match(f.message(),/could not be checked/); assert.equal(f.state().locked,true);
  const retry=f.retry(); assert.equal(f.requests[3].options.method,'GET');
  f.requests[3].resolve(response(status())); await retry;
  assert.equal(f.state().locked,false);
});

test('content mapping uses exact identity, rejects conflicts and never chooses a DOM fallback',()=>{
  const f=setup();
  assert.equal(f.extract({},{}),null);
  assert.equal(f.extract({}, {object:{id:'https://example.test/123'}}),null);
  f.window.H5PIntegration={contents:{'cid-2':{url:'https://example.test/h5p/2'}}};
  assert.equal(f.extract({}, {object:{id:'https://example.test/h5p/2'}}),2);
  const stmt={object:{definition:{extensions:{'http://h5p.org/x-api/h5p-local-content-id':'2'}}}};
  assert.equal(f.extract({},stmt),2);
  assert.equal(f.extract({data:{contentId:1}},stmt),null);
  assert.equal(f.extract({data:{contentId:'2junk'}},stmt),null);
  f.onXAPI({data:{statement}});
  assert.equal(f.requests.length,0); assert.equal(f.state().inputError,true);
});

test('nested events are ignored and initialization never schedules synthetic completion',async()=>{
  const f=setup();
  f.onXAPI({data:{contentId:1,statement:{...statement,object:{id:'https://example.test/h5p/1?subContentId=child'}}}});
  assert.equal(f.requests.length,0);
  f.onXAPI({data:{contentId:1,statement:{verb:{id:'http://adlnet.gov/expapi/verbs/initialized'}}}});
  assert.equal(f.requests.length,1); assert.equal(f.timers.size,1); // Request timeout only.
  f.requests[0].resolve(response({ok:true,stored:false,skipped:true,reason:'completion_pending'})); await tick();
  f.requests[1].resolve(response(status())); await tick();
  assert.equal(f.timers.size,0); assert.equal(f.requests.filter(x=>x.options.method==='POST').length,1);
});

test('network timeout is retryable and a full queue does not silently evict older events',async()=>{
  const f=setup(); const p=f.post(statement,1);
  const req=f.requests[0]; req.options.signal.addEventListener('abort',()=>req.reject(new Error('aborted')));
  [...f.timers.values()][0](); assert.equal(await p,false); await tick();
  assert.equal(f.state().saveError.retryable,true);
  for(let i=0;i<100;i++) f.post(statement,1);
  assert.equal(f.state().pending,100); assert.equal(f.state().inputError,true);
  assert.equal(f.requests.length,1);
});
