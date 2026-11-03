const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync(`${__dirname}/../h5p-sensei-bridge/assets/js/h5p-sensei-bridge.js`, 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
const item = (id, extra = {}) => ({content_id:id,title:`Activity ${id}`,type:'',threshold:70,counts_for_gate:true,requirement_met:false,has_attempt:false,latest:{has_attempt:false},best:{pct:null},...extra});
async function fixture() {
  const dom = new JSDOM('<main><section data-fkhs-progress></section><div class="h5p-iframe-wrapper"><iframe class="h5p-iframe" data-content-id="1"></iframe></div><section data-fkhs-progress></section><div class="sensei-lesson-footer"></div></main>',{runScripts:'outside-only',url:'https://fixture.test/lesson'});
  const w=dom.window, requests=[], bindings=[];
  w.fkH5P={lessonId:5,restUrl:'https://fixture.test/wp-json/fkhs/v1/h5p-xapi',nonce:'fixture',require:true,loginUrl:'https://fixture.test/wp-login.php'};
  w.H5P={externalDispatcher:{on:(name,callback)=>bindings.push({name,callback})}};
  w.matchMedia=()=>({matches:true}); w.HTMLElement.prototype.scrollIntoView=function(){this.scrolled=true;};
  w.fetch=(url,options)=>new Promise(resolve=>requests.push({url,options,reply:(data,status=200)=>resolve({ok:status===200,status,json:async()=>data})}));
  w.eval(source.replace('  //bind();','window.progressTest={refresh:fetchAndRenderH5PStatus};'));
  await tick();
  const roots=[...w.document.querySelectorAll('[data-fkhs-progress]')];
  return {dom,w,d:w.document,roots,requests,bindings,reply:async(index,items)=>{requests[index].reply({ok:true,items,required:items.some(x=>x.counts_for_gate),passed:items.every(x=>!x.counts_for_gate||x.requirement_met)});await tick();}};
}
test('placements share one read/listener and render canonical mixed requirements safely',async t=>{
  const f=await fixture();t.after(()=>f.w.close());
  assert.equal(f.requests.length,1); assert.equal(f.bindings.length,1);
  await f.reply(0,[item(1,{title:'A <img src=x>',requirement_met:true,best:{pct:20}}),item(2,{threshold:0,counts_for_gate:false}),item(3,{threshold:0,has_attempt:true,latest:{has_attempt:true}})]);
  for(const root of f.roots){
    assert.equal(root.querySelector('progress').max,2); assert.equal(root.querySelector('progress').value,1);
    assert.equal(root.querySelectorAll('li').length,3); assert.equal(root.querySelector('img'),null);
    assert.match(root.textContent,/Task passed/); assert.match(root.textContent,/Optional/);assert.match(root.textContent,/Requires completion/);
    assert.equal(root.querySelector('.fkhs-progress-body').hidden,false);
  }
  const link=f.roots[0].querySelector('.fkhs-progress-link'); link.click();
  assert.equal(f.d.activeElement.tagName,'IFRAME');
  assert.equal(f.d.querySelector('#fkhs-status-panel').hidden,true);
  assert.equal(f.d.querySelector('#fkhs-save-status').hidden,true);
  assert.equal(f.requests.length,1); assert.equal(f.bindings.length,1);
});
test('save failure hides stale progress in every placement; shared retry retains event identity and recovers focus',async t=>{
  const f=await fixture();t.after(()=>f.w.close());
  await f.reply(0,[item(1,{requirement_met:true})]);
  f.bindings[0].callback({data:{contentId:1,statement:{verb:{id:'http://adlnet.gov/expapi/verbs/completed'},result:{completion:true,score:{raw:1,max:1}}}}});
  assert.equal(f.requests[1].options.method,'POST');
  for(const root of f.roots){assert.equal(root.querySelector('.fkhs-progress-body').hidden,true); assert.match(root.textContent,/Saving your activity/);}
  f.requests[1].reply({ok:false},503);await tick();
  for(const root of f.roots) assert.match(root.querySelector('.fkhs-progress-message').textContent,/not been confirmed saved/);
  const retry=f.roots[0].querySelector('.fkhs-progress-message button');retry.focus();retry.click();await tick();
  assert.equal(f.requests[2].options.body,f.requests[1].options.body);
  f.requests[2].reply({ok:true,stored:true,attempt_id:10});await tick();
  await f.reply(3,[item(1,{requirement_met:true,has_attempt:true,best:{pct:100}})]);
  for(const root of f.roots){assert.equal(root.querySelector('.fkhs-progress-body').hidden,false);assert.equal(root.querySelector('progress').value,1);}
  assert.ok(f.roots[0].contains(f.d.activeElement),'Retry focus remains in the visible placement');
  assert.equal(f.requests.length,4);assert.equal(f.bindings.length,1);
});
test('late status cannot overwrite a newer result; malformed/auth failures remain visible',async t=>{
  const f=await fixture();t.after(()=>f.w.close());
  f.w.progressTest.refresh();await f.reply(1,[item(1,{requirement_met:true})]);
  await f.reply(0,[item(1)]);assert.equal(f.roots[0].querySelector('progress').value,1);
  f.w.progressTest.refresh();await f.reply(2,[{content_id:1,threshold:70}]);
  assert.equal(f.roots[0].querySelector('.fkhs-progress-body').hidden,true);
  assert.match(f.roots[0].querySelector('.fkhs-progress-message').textContent,/could not be checked/);
  f.roots[0].querySelector('.fkhs-progress-message button').click();
  f.requests[3].reply({ok:false},401);await tick();
  for(const root of f.roots){assert.equal(root.querySelector('.fkhs-progress-message button'),null);assert.equal(root.querySelector('.fkhs-progress-message a').target,'_blank');}
});
test('empty and optional-only lessons never invent a percentage or claim Sensei completion',async t=>{
  const f=await fixture();t.after(()=>f.w.close());
  await f.reply(0,[]);assert.match(f.roots[0].textContent,/no H5P activities/);assert.equal(f.roots[0].querySelector('progress'),null);
  f.w.progressTest.refresh();await f.reply(1,[item(2,{threshold:0,counts_for_gate:false})]);
  assert.match(f.roots[0].textContent,/No H5P activities currently block/);assert.equal(f.roots[0].querySelector('progress'),null);
  assert.doesNotMatch(f.roots[0].textContent,/All H5P requirements are met|100%|lesson complete/i);
});
