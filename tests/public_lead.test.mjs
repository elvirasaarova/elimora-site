import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {sendLead} from '../public-lead.mjs';

const payload={service:'creative',contact:'private@example.test',brief:'Private brief',consent:true,request_key:'a'.repeat(48)};
test('a short enquiry succeeds only after a confirmed server save with CSRF',async()=>{
 const calls=[];
 const id=await sendLead(payload,async(url,options)=>{
  calls.push({url,options});
  return {ok:true,json:async()=>calls.length===1?{ready:true,csrf:'session-token'}:{ok:true,id:17}};
 });
 assert.equal(id,17);assert.equal(calls.length,2);
 assert.equal(calls[1].options.headers['X-CSRF-Token'],'session-token');
 assert.deepEqual(JSON.parse(calls[1].options.body),payload);
 assert.equal(calls[1].options.credentials,'same-origin');
});
test('unavailable storage and rejected submissions do not report success',async()=>{
 let calls=0;
 await assert.rejects(sendLead(payload,async()=>{calls++;return {ok:true,json:async()=>({ready:false})}}),/недоступен/);
 assert.equal(calls,1);
 calls=0;
 await assert.rejects(sendLead(payload,async()=>({ok:++calls===1,json:async()=>calls===1?{ready:true,csrf:'x'}:{error:'Проверьте контакт'}})),/Проверьте контакт/);
 calls=0;
 await assert.rejects(sendLead(payload,async()=>({ok:true,json:async()=>++calls===1?{ready:true,csrf:'x'}:{ok:true}})),/сохранить/);
});
test('every service and the homepage expose a consent-based form without an account wall',()=>{
 for(const name of ['index','maps','visual','logos','websites','services']){
  const page=fs.readFileSync(new URL('../'+name+'.html',import.meta.url),'utf8');
  assert.equal((page.match(/id="request"/g)||[]).length,1,name);
  assert(page.includes('name="consent" required'),name);
  assert(page.includes('data-public-lead'),name);
  assert(page.includes('src="public-lead.mjs?v=1"'),name);
  assert(page.includes('href="https://t.me/Elimorastudio"'),name);
 }
});
