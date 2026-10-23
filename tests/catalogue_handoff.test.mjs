import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
const catalogue=fs.readFileSync(new URL('../catalogue.js',import.meta.url),'utf8').replace(/^import[^\n]+\n/,'');
const products=JSON.parse(fs.readFileSync(new URL('../catalogue.json',import.meta.url),'utf8'));
function setup(){
 const nodes=new Map(),saved=new Map();let loads=0;
 const element=id=>{if(!nodes.has(id))nodes.set(id,{innerHTML:'',textContent:'',hidden:true,disabled:false,dataset:{},querySelector:()=>({}),showModal(){},close(){}});return nodes.get(id)};
 const context=vm.createContext({document:{getElementById:element,querySelectorAll:()=>[]},sessionStorage:{getItem:k=>saved.get(k),setItem:(k,v)=>saved.set(k,v)},location:{href:''},fetch:()=>new Promise(()=>{}),loadAvailability:()=>loads++,withPreferredStart:d=>d});
 vm.runInContext(catalogue,context);
 vm.runInContext('products='+JSON.stringify(products),context);
 return {context,nodes,saved,loads:()=>loads,run:s=>vm.runInContext(s,context)};
}
test('selected services carry their names, prices, descriptions and calculation items into the cabinet',()=>{
 const t=setup();t.run("cart=['logo','website'];render();document.getElementById('cart-account').onclick()");
 const draft=JSON.parse(t.saved.get('elimora-order-draft'));
 assert.equal(draft.source,'catalogue');assert.equal(draft.answers.services.length,2);
 assert(draft.brief.includes('Логотип — 3'));assert(draft.brief.includes('Создание сайта — нужен расчёт'));
 assert(draft.brief.includes('Состав, итоговую стоимость и сроки согласуем до оплаты'));
 assert.equal(t.run('location.href'),'account.html?new=1');
});
test('availability appears after selection and remains available when editing a request',()=>{
 const t=setup();t.run('render()');
 assert.equal(t.nodes.get('availability-card').hidden,true);assert.equal(t.nodes.get('cart-account').disabled,true);assert.equal(t.loads(),0);
 t.run("cart=['creative'];render()");assert.equal(t.nodes.get('availability-card').hidden,false);assert.equal(t.loads(),1);
 t.run("cart=['creative','logo'];render()");assert.equal(t.loads(),1);
 t.run("cart=[];render()");assert.equal(t.nodes.get('availability-card').hidden,true);
});
test('a storage failure keeps the selection on the page and explains the next action',()=>{
 const t=setup();t.run("cart=['logo'];render();sessionStorage.setItem=()=>{throw Error('blocked')};document.getElementById('cart-account').onclick()");
 assert.equal(t.run('location.href'),'');assert(t.nodes.get('cart-account-status').textContent.includes('Не удалось сохранить'));
});
const availability=fs.readFileSync(new URL('../availability.js',import.meta.url),'utf8').replace(/export /g,'');
function calendarSetup(){
 const saved=new Map(),controls=new Map();let ok=true;
 const card={isConnected:true,innerHTML:'',querySelector:()=>({focus(){}}),querySelectorAll(selector){
  const key=selector.includes('data-start')?'start':'month';
  return [...this.innerHTML.matchAll(new RegExp('data-'+key+'="([^"]+)"','g'))].map(m=>{const b={dataset:{[key]:m[1]},focus(){}};controls.set(key+':'+m[1],b);return b});
 }};
 const calendar=Array.from({length:40},(_,i)=>{const d=new Date('2026-10-26T12:00:00');d.setDate(d.getDate()+i);return {date:d.toISOString().slice(0,10),occupied:1,free:2}});
 const context=vm.createContext({AbortController,setTimeout,clearTimeout,sessionStorage:{getItem:k=>saved.get(k),setItem:(k,v)=>saved.set(k,v),removeItem:k=>saved.delete(k)},fetch:async()=>({ok,json:async()=>({start:'2026-10-26',capacity:3,duration_workdays:5,active_projects:1,calendar,available_starts:[{start:'2026-10-26',finish:'2026-10-30'},{start:'2026-10-28',finish:'2026-11-03'}]})}),card});
 vm.runInContext(availability,context);
 return {card,controls,saved,run:s=>vm.runInContext(s,context),fail:()=>ok=false};
}
test('calendar selects only available start dates and carries the choice into either request',async()=>{
 const t=calendarSetup();await t.run('loadAvailability(card)');
 assert(t.card.innerHTML.includes('Октябрь')||t.card.innerHTML.includes('октябрь'));
 assert.equal(JSON.parse(t.saved.get('elimora-start-choice')).start,'2026-10-26');
 t.controls.get('start:2026-10-28').onclick();
 assert(t.card.innerHTML.includes('28 октября'));assert(t.card.innerHTML.includes('3 ноября'));
 let result=t.run("withPreferredStart({brief:'Logo',answers:{services:[{id:'logo'}]}})");
 assert.equal(result.answers.preferred_start,'2026-10-28');assert.equal(result.answers.services[0].id,'logo');
 assert(result.brief.includes('2026-10-28'));
 t.controls.get('start:2026-10-27').onclick();
 assert.equal(JSON.parse(t.saved.get('elimora-start-choice')).start,'2026-10-28');
 t.controls.get('month:1').onclick();assert(t.card.innerHTML.includes('ноябрь'));
});
test('calendar failure clears stale dates and keeps requests possible',async()=>{
 const t=calendarSetup();await t.run('loadAvailability(card)');t.fail();await t.run('loadAvailability(card)');
 assert(t.card.innerHTML.includes('оставить заявку'));assert(!t.saved.has('elimora-start-choice'));
 assert.equal(t.run("withPreferredStart({brief:'Original'}).brief"),'Original');
});
