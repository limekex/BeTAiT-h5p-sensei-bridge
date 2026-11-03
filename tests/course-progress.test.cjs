const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');
const source=fs.readFileSync(`${__dirname}/../h5p-sensei-bridge/assets/js/h5p-sensei-course-progress.js`,'utf8');
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const row=(extra={})=>({lesson_id:1,title:'Lesson <img src=x>',url:'https://fixture.test/lesson',required_count:2,met_count:1,optional_count:1,activity_count:3,...extra});
const data=(lessons=[row()],limited=false)=>({ok:true,course_id:5,limited,lessons,...Object.fromEntries(['required_count','met_count','optional_count','activity_count'].map(key=>[key,lessons.reduce((sum,r)=>sum+r[key],0)]))});
function fixture(){
 const dom=new JSDOM('<section data-fkhs-course-progress></section><section data-fkhs-course-progress></section>',{runScripts:'outside-only',url:'https://fixture.test/course'}),w=dom.window,requests=[];
 w.fkhsCourseProgress={courseId:5,url:'https://fixture.test/wp-json/fkhs/v1/course-progress',nonce:'nonce',loginUrl:'/wp-login.php',labels:{title:'Course progress',count:'%1$d of %2$d requirements',optional:'%d optional',loading:'Loading',refresh:'Refresh',error:'Read failed',auth:'Sign in required',login:'Sign in',limited:'Accessible lessons only',scope:'Sensei tracks completion separately',noRequired:'No requirements',optionalHelp:'Started optional activities require completion'}};
 w.fetch=(url,options)=>new Promise(resolve=>requests.push({url,options,reply:async(body,status=200)=>{resolve({status,ok:status===200,json:async()=>body});await tick();}}));
 w.eval(source);
 return {w,requests,roots:[...w.document.querySelectorAll('section')]};
}
test('all placements share one private read and safely show lesson counts and links',async t=>{
 const f=fixture();t.after(()=>f.w.close());assert.equal(f.requests.length,1);
 assert.match(f.requests[0].url,/course_id=5/);assert.equal(f.requests[0].options.cache,'no-store');assert.equal(f.requests[0].options.headers['X-WP-Nonce'],'nonce');
 await f.requests[0].reply(data());
 for(const root of f.roots){assert.equal(root.querySelector('progress').value,1);assert.equal(root.querySelector('progress').max,2);assert.equal(root.querySelectorAll('li').length,1);assert.equal(root.querySelector('img'),null);assert.equal(root.querySelector('a').href,'https://fixture.test/lesson');assert.match(root.textContent,/1 optional/);assert.match(root.textContent,/Sensei tracks/);}
});
test('refresh hides old results, retains focus, rejects malformed totals, then recovers',async t=>{
 const f=fixture();t.after(()=>f.w.close());await f.requests[0].reply(data());
 const button=f.roots[0].querySelector('button');button.focus();button.click();
 for(const root of f.roots)assert.equal(root.querySelector('.fkhs-progress-body').hidden,true);
 await f.requests[1].reply({...data(),met_count:2});assert.match(f.roots[0].querySelector('[role=status]').textContent,/Read failed/);
 assert.equal(f.w.document.activeElement,button);button.click();await f.requests[2].reply(data([row({met_count:2})]));
 assert.equal(f.roots[0].querySelector('progress').value,2);assert.equal(f.w.document.activeElement,button);
});
test('late reads cannot replace newer snapshots and auth failure clears visible progress',async t=>{
 const f=fixture();t.after(()=>f.w.close());f.w.dispatchEvent(new f.w.PageTransitionEvent('pageshow',{persisted:true}));
 await f.requests[1].reply(data([row({met_count:2})]));await f.requests[0].reply(data());assert.equal(f.roots[0].querySelector('progress').value,2);
 f.roots[0].querySelector('button').click();await f.requests[2].reply({},403);
 for(const root of f.roots){assert.equal(root.querySelector('.fkhs-progress-body').hidden,true);assert.match(root.querySelector('[role=status]').textContent,/Sign in required/);assert.equal(root.querySelector('[role=status] a').target,'_blank');}
});
test('empty, optional-only and inaccessible lessons do not claim course completion',async t=>{
 const f=fixture();t.after(()=>f.w.close());await f.requests[0].reply(data([]));assert.equal(f.roots[0].hidden,true);
 f.w.dispatchEvent(new f.w.PageTransitionEvent('pageshow',{persisted:true}));await f.requests[1].reply(data([],true));assert.equal(f.roots[0].hidden,false);assert.equal(f.roots[0].querySelector('progress'),null);assert.match(f.roots[0].textContent,/Accessible lessons only/);
 f.roots[0].querySelector('button').click();await f.requests[2].reply(data([row({required_count:0,met_count:0,optional_count:3})]));assert.equal(f.roots[0].querySelector('progress'),null);assert.match(f.roots[0].textContent,/No requirements/);assert.doesNotMatch(f.roots[0].textContent,/100%|course complete/i);
});
test('untrusted links cannot navigate to javascript or an external origin; duplicate lessons fail closed',async t=>{
 const f=fixture();t.after(()=>f.w.close());await f.requests[0].reply(data([row({url:'javascript:alert(1)'})]));assert.equal(f.roots[0].querySelector('a'),null);
 f.roots[0].querySelector('button').click();await f.requests[1].reply(data([row({url:'https://foreign.test'})]));assert.equal(f.roots[0].querySelector('a'),null);
 f.roots[0].querySelector('button').click();await f.requests[2].reply(data([row(),row()]));assert.equal(f.roots[0].querySelector('.fkhs-progress-body').hidden,true);assert.match(f.roots[0].textContent,/Read failed/);
});
