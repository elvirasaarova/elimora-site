import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs';
const source=fs.readFileSync(new URL('../cabinet.js',import.meta.url),'utf8').replace(/^import[^\n]+\n/,'').replace(/\ninit\(\);\s*$/,'');
function render(role,expression){const context=vm.createContext({URLSearchParams,location:{search:'?new=1'},document:{getElementById:()=>({querySelectorAll(){return []},querySelector(){return {scrollIntoView(){}}},addEventListener(){},innerHTML:''})},window:{addEventListener(){}},sessionStorage:{getItem(){return null}},console});vm.runInContext(source,context);vm.runInContext(`me={id:1,name:'Hidden administrator name',role:'${role}'};selected=7;projectTab='review';`,context);vm.runInContext(`globalThis.order={id:7,company:'Cafe',kind:'maps',status:'work',released:0,files:[],links:[],reviews:[],payments:[{id:18,title:'Deposit',amount_minor:120000,status:'pending'},{id:19,title:'Balance',amount_minor:120000,status:'pending'}],brief:'Task',owner:{name:'Client',email:'client@example.test'}}`,context);return vm.runInContext(expression,context);}
test('administrator sees management without a personal name; client keeps their identity',()=>{assert(!render('admin','toolbar()').includes('Hidden administrator name'));assert(render('admin','toolbar()').includes('Управление проектами'));assert.equal(render('admin',`viewTitle('files')`),'Файлы клиента');assert.equal(render('client',`viewTitle('files')`),'Мои файлы');assert(render('client','toolbar()').includes('Hidden administrator name'));});
test('a ready bank invoice is attached with one control and no repeated amount form',()=>{const html=render('admin','paymentPanel(order)');assert(html.includes('data-purpose="invoice"'));assert(html.includes('Прикрепить счёт'));assert(!html.includes('create-payment'));assert(!html.includes('name="amount"'));assert(!html.includes('file-payment'));assert(!html.includes('Логотип и брендбук'));assert(render('client','paymentPanel(order)').includes('Я оплатил(а)'));assert(!render('client','paymentPanel(order)').includes('data-upload='));assert(render('admin',"paymentCard({id:18,title:'invoice.pdf',amount_minor:0,status:'pending'})").includes('Сумма — в счёте'));});

test('map approval has separate work types and a multilink input; documents have their own controls',()=>{const html=render('admin','reviewPanel(order)');for(const asset of ['stories','showcase','publications','qr-layout','creative','logo'])assert(html.includes(`data-asset="${asset}"`));assert(html.includes('add-review-link'));const docs=render('admin','projectDocuments(order)');assert(docs.includes('data-purpose="contract"'));assert(docs.includes('data-purpose="act"'));assert(!docs.includes('file-payment'));});
test('client materials and creator deliverables are kept separate',()=>{const html=render('admin',`clientFilesGrouped([{id:1,order_id:7,company:'Cafe',title:'Maps',purpose:'material',category:'brand',original_name:'client-logo.pdf',bytes:200,mime:'application/pdf',user_id:2},{id:2,order_id:7,purpose:'review',category:'media',original_name:'private-preview.png',bytes:200,mime:'image/png',user_id:1}])`);assert(html.includes('client-logo.pdf'));assert(html.includes('Логотип и брендбук'));assert(!html.includes('private-preview.png'));});

test('administrator sidebar stays in the selected client project',async()=>{const opened=await render('admin',`(async()=>{selected=7;globalThis.opened=null;openOrder=async(id,tab)=>{globalThis.opened={id,tab}};dashboard=async()=>{throw Error('Unexpected global navigation')};await navigateCabinet('documents');return opened})()`);assert.equal(opened.id,7);assert.equal(opened.tab,'documents');const payment=await render('admin',`(async()=>{selected=7;globalThis.opened=null;openOrder=async(id,tab)=>{globalThis.opened={id,tab}};await navigateCabinet('payment');return opened})()`);assert.equal(payment.id,7);assert.equal(payment.tab,'payment');assert.equal(render('admin',"selected=7;viewTitle('projects')"),'Проект');assert.equal(render('admin',"selected=null;viewTitle('projects')"),'Клиенты');});
test('before selecting a client the administrator can open the global queue',async()=>{const result=await render('admin',`(async()=>{selected=null;globalThis.target=null;dashboard=async key=>{globalThis.target=key};await navigateCabinet('projects');return target})()`);assert.equal(result,'projects');const table=render('admin',"orders=[{id:7,company:'Cafe',name:'Anna',email:'a@example.test',title:'Maps',status:'new',created_at:'2026-10-23 08:00:00',updated_at:'2026-10-23 08:00:00'}];adminQueue();root.innerHTML");assert(table.includes('Очередь'));assert(table.includes('data-order="7"'));assert(!table.includes('Выбрать клиента'));});

test('project sections highlight the sidebar without a second tab bar',async()=>{
 for(const role of ['admin','client']){
  for(const tab of ['project','files','brief','review','payment','documents']){
   const html=await render(role,`(async()=>{
    order.created_at='2026-10-23 08:00:00';order.title='Maps';order.messages=[];
    api=async action=>action==='order'?{order}:action==='orders'?{orders:[order]}:{};
    loadNotifications=async()=>{};bindToolbar=()=>{};bindOrders=()=>{};bindForm=()=>{};bindDeletes=()=>{};
    await openOrder(7,'${tab}');
    return root.innerHTML;
   })()`);
   const nav=html.split('<nav aria-label="Разделы кабинета">')[1].split('</nav>')[0];
   const active=[...nav.matchAll(/<button data-view="([^"]+)" aria-current="page"/g)].map(m=>m[1]);
   assert.deepEqual(active,[tab==='project'?'projects':tab],role+': '+tab);
   assert(nav.includes('data-view="brief"'),'brief remains reachable');
   assert(!html.includes('class="project-tabs"'));
   assert(html.includes('id="back-queue"'),'project list remains reachable');
  }
 }
});
test('client sidebar sections stay scoped to the selected project',async()=>{
 const result=await render('client',`(async()=>{openOrder=async(id,tab)=>({id,tab});dashboard=async()=>{throw Error('Unexpected global navigation')};return navigateCabinet('brief')})()`);
 assert.equal(result.id,7);assert.equal(result.tab,'brief');
});

test('a services request opens the new-project form after login without submitting it',async()=>{
 const result=await render('client',`(async()=>{sessionStorage.getItem=()=>JSON.stringify({source:'catalogue',title:'Логотип',brief:'Логотип — 3 500 ₽',answers:{services:[{id:'logo'}]}});newOrder=()=>({});globalThis.opened=false;newOrder=()=>{globalThis.opened=draft()};dashboard=async()=>{throw Error('Draft should open before dashboard')};await enterCabinet();return opened})()`);
 assert.equal(result.source,'catalogue');assert.equal(result.title,'Логотип');assert.equal(result.answers.services[0].id,'logo');
});
test('administrators and ordinary logins keep their dashboard',async()=>{
 assert.equal(await render('admin',`(async()=>{draft=()=>({title:'Request'});newOrder=()=>{throw Error('Admin must not create a client request')};dashboard=async()=> 'queue';return enterCabinet()})()`),undefined);
 const result=await render('client',`(async()=>{location.search='';draft=()=>({title:'Request'});newOrder=()=>{throw Error('No explicit request')};globalThis.visited=false;dashboard=async()=>{visited=true};await enterCabinet();return visited})()`);
 assert.equal(result,true);
});
