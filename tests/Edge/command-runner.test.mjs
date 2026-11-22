import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { verifyCommand,requireStationary,executeCommand,explainNormalDtcs } from '../../deploy/pi/command-runner.mjs';
const id='10000000-0000-4000-8000-000000000001';
const config={device_id:id,organization_id:id,auth_token:'a'.repeat(64)};
const command=()=>({id,device_id:id,organization_id:id,vehicle_id:id,installation_id:id,kind:'extended_dtc',issued_at:new Date().toISOString(),expires_at:new Date(Date.now()+30000).toISOString(),parameters:{read_only:true,local_stationary_check_required:true,max_duration_seconds:300}});
const signed=cmd=>{const wire_payload=JSON.stringify(cmd);return{wire_payload,signature:createHmac('sha256',config.auth_token).update(wire_payload).digest('hex')};};
function fixture(speed=0) {
  const calls=[], results=[];
  const adapter={withExclusiveObd:async fn=>{calls.push('lock');try{return await fn();}finally{calls.push('unlock');}},pauseTelemetry:async()=>calls.push('pause'),
    readObdSpeed:async()=>({source:'obd_pid_010d',speed_kmh:speed,observed_at:new Date().toISOString()}),send:async()=>'',restoreBaseline:async()=>calls.push('restore'),resumeTelemetry:async()=>calls.push('resume'),onExplanation:async()=>calls.push('transient-ui')};
  const api={result:async(id,body)=>results.push(body),explain:async()=>{calls.push('ai');return{description:'transient'};}};
  return{calls,results,adapter,api};
}
test('HMAC uses exact wire bytes and rejects tampering, identity mismatch and expiry',()=>{
  const cmd=command(), item=signed(cmd);assert.deepEqual(verifyCommand(item,config),cmd);
  assert.throws(()=>verifyCommand({...item,wire_payload:item.wire_payload+' '},config));
  assert.throws(()=>verifyCommand(signed({...cmd,device_id:'other'}),config));
  assert.throws(()=>verifyCommand(signed({...cmd,expires_at:new Date(0).toISOString()}),config));
  assert.throws(()=>verifyCommand(signed({...cmd,parameters:{...cmd.parameters,read_only:false}}),config));
});
test('stationary guard rejects GPS, numeric strings, movement, stale and future OBD',()=>{
  const now=Date.now(), valid={source:'obd_pid_010d',speed_kmh:0,observed_at:new Date(now).toISOString()};requireStationary(valid,now);
  for(const change of [{source:'gps'},{speed_kmh:'0'},{speed_kmh:1},{observed_at:new Date(now-3000).toISOString()},{observed_at:new Date(now+100).toISOString()}])assert.throws(()=>requireStationary({...valid,...change},now));
});
test('moving vehicle never scans and always restores polling',async()=>{
  const f=fixture(1);const status=await executeCommand(command(),f.adapter,f.api,{scanner:async()=>{throw new Error('must not scan');}});
  assert.equal(status,'not_stationary');assert.deepEqual(f.calls,['lock','pause','restore','resume','unlock']);assert.equal(f.results[0].state,'failed');
});
test('successful DUDU report sends only transient codes to AI after polling restoration',async()=>{
  const f=fixture();const scanner=async()=>({protocol:{number:6},attempts:[{responses:[{dtcs:[{code:'P0730'}]}]}]});
  assert.equal(await executeCommand(command(),f.adapter,f.api,{scanner}),'completed');
  assert.ok(f.calls.indexOf('resume')<f.calls.indexOf('ai'));assert.ok(f.calls.indexOf('unlock')<f.calls.indexOf('ai'));
  assert.ok(!JSON.stringify(f.results).includes('P0730'));assert.equal(f.results.at(-1).result_status,'completed');
});
test('transport failures and provider failures restore polling without opaque result persistence',async()=>{
  const f=fixture();assert.equal(await executeCommand(command(),f.adapter,f.api,{scanner:async()=>{throw new Error('secret raw P0730');}}),'transport_failed');
  assert.ok(f.calls.includes('resume'));assert.ok(!JSON.stringify(f.results).includes('P0730'));
});
test('read-only allowlist refuses clear-DTC and ECU writes',async()=>{
  const f=fixture();const scanner=async(elm,send)=>{await send(elm,'04');return{};};
  assert.equal(await executeCommand(command(),f.adapter,f.api,{scanner}),'unsupported');assert.ok(f.calls.includes('resume'));
});
test('bounded scan stops before subsequent transport calls after its deadline',async()=>{
  const f=fixture();const scanner=async(elm,send)=>{await new Promise(resolve=>setTimeout(resolve,10));await send(elm,'03');return{};};
  assert.equal(await executeCommand(command(),f.adapter,f.api,{scanner,maximumMs:5}),'timed_out');assert.ok(f.calls.includes('resume'));
});
test('a failed baseline restoration still invokes polling resume',async()=>{
  const f=fixture();f.adapter.restoreBaseline=async()=>{throw new Error('transport');};
  assert.equal(await executeCommand(command(),f.adapter,f.api,{scanner:async()=>({protocol:{number:6},attempts:[]})}),'transport_failed');assert.ok(f.calls.includes('resume'));
});
test('a partially failed pause still restores baseline and resumes polling',async()=>{
  const f=fixture();f.adapter.pauseTelemetry=async()=>{f.calls.push('partial-pause');throw new Error('transport');};
  assert.equal(await executeCommand(command(),f.adapter,f.api,{scanner:async()=>{throw new Error('must not scan');}}),'transport_failed');
  assert.deepEqual(f.calls,['lock','partial-pause','restore','resume','unlock']);
});
test('configuration commands verify bounds and apply before acknowledging completion',async()=>{
  const cmd={...command(),kind:'configuration',parameters:{telemetry_interval_seconds:5,cellular_enabled:false,safety_enabled:true}};
  assert.deepEqual(verifyCommand(signed(cmd),config),cmd);
  assert.throws(()=>verifyCommand(signed({...cmd,parameters:{...cmd.parameters,telemetry_interval_seconds:1}}),config));
  const f=fixture();f.adapter.applyConfiguration=async p=>{assert.deepEqual(p,cmd.parameters);f.calls.push('apply');};
  assert.equal(await executeCommand(cmd,f.adapter,f.api),'completed');
  assert.ok(f.calls.indexOf('apply')<f.calls.indexOf('resume'));assert.equal(f.results.at(-1).state,'completed');
});
test('normal DTC helper preserves non-MIL presence without publishing codes or explanations',async()=>{
  const packet={vehicle:{mil_on:false,dtc_codes:['P0730','P0730'],has_dtc_fault:false},dtc_ai:{description:'private'}};
  const seen=[], result=await explainNormalDtcs(packet,{explain:async code=>{seen.push(code);return{code,description:'transient'};}},answer=>seen.push(answer.description));
  assert.deepEqual(result.packet,{vehicle:{mil_on:false,has_dtc_fault:true}});
  assert.deepEqual(seen,['P0730','transient']);assert.equal(result.codes_discarded,1);
  assert.ok(!JSON.stringify(result).includes('P0730'));assert.deepEqual(packet.vehicle.dtc_codes,['P0730','P0730']);
});
test('normal DTC helper discards codes on AI failure and preserves explicit presence',async()=>{
  const result=await explainNormalDtcs({vehicle:{has_dtc_fault:true,dtc_codes:['P0730']}},{explain:async()=>{throw new Error('private response');}});
  assert.equal(result.explanations_failed,1);assert.deepEqual(result.packet,{vehicle:{has_dtc_fault:true}});
  assert.deepEqual((await explainNormalDtcs({vehicle:{dtc_codes:[]}},{explain:async()=>{throw new Error('must not call');}})).packet,{vehicle:{has_dtc_fault:false}});
});
