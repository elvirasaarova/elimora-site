import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
const catalogue=fs.readFileSync(new URL('../catalogue.js',import.meta.url),'utf8').replace(/^import[^\n]+\n/,'');
const products=JSON.parse(fs.readFileSync(new URL('../catalogue.json',import.meta.url),'utf8'));
function setup(){
 const nodes=new Map(),saved=new Map();let loads=0;
 const element=id=>{if(!nodes.has(id))nodes.set(id,{innerHTML:'',textContent:'',hidden:true,disabled:false,dataset:{},querySelector:()=>({}),showModal(){},close(){}});return nodes.get(id)};
 const context=vm.createContext({document:{getElementById:element,querySelectorAll:()=>[]},sessionStorage:{getItem:k=>saved.get(k),setItem:(k,v)=>saved.set(k,v)},location:{href:''},fetch:()=>new Promise(()=>{}),loadAvailability:()=>loads++});
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
const availability=fs.readFileSync(new URL('../availability.js',import.meta.url),'utf8').replace('export async function','async function');
test('the same public calendar shows capacity and falls back without blocking requests',async()=>{
 const card={isConnected:true,innerHTML:''};
 let ok=true;
 const context=vm.createContext({AbortController,setTimeout,clearTimeout,fetch:async()=>({ok,json:async()=>({start:'2026-10-26',finish:'2026-10-30',capacity:3,duration_workdays:5,active_projects:1,calendar:[{date:'2026-10-26',occupied:1,free:2}]})}),card});
 vm.runInContext(availability,context);await vm.runInContext('loadAvailability(card)',context);
 assert(card.innerHTML.includes('2 из 3 свободно'));assert(card.innerHTML.includes('Ориентировочный старт'));
 ok=false;await vm.runInContext('loadAvailability(card)',context);
 assert(card.innerHTML.includes('Ближайшую дату начала уточним'));
});
