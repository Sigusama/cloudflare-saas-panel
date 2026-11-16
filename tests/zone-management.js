// SPDX-License-Identifier: AGPL-3.0-only
'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync('public/assets/app.js','utf8');
const start=source.indexOf('    let zoneAccountRevision='),end=source.indexOf("    $('#new-domain').addEventListener",start);
assert(start>=0&&end>start);
const nodes=new Map();let checks=0,requests=[],handler=async()=>({items:[],demo:false}),pending;
function node(selector){
    if(!nodes.has(selector)){
        const item={value:'',hidden:false,disabled:false,readOnly:false,textContent:'',dataset:{},handlers:{},open:false,
            addEventListener(type,listener){this.handlers[type]=listener;},showModal(){this.open=true;},close(){this.open=false;},reset(){},
            get innerHTML(){return this.html??'';},set innerHTML(value){this.html=value;this.value=value.match(/<option value="([^"]*)"/)?.[1]??'';}};
        nodes.set(selector,item);
    }
    return nodes.get(selector);
}
const context=vm.createContext({console,crypto:{randomUUID:()=>String(Math.random())},location:{hash:''},
    document:{documentElement:{lang:'en'},getElementById:()=>({textContent:fs.readFileSync('public/assets/locales/en.json','utf8')}),addEventListener:()=>{}},
    $:selector=>node(selector),$$:()=>[],escapeHtml:value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
    config:{accounts:[{id:1,name:'Saved credentials',active:1}],demo:false},zoneAccountFilter:0,selectedZoneId:1,
    loadConfig:async()=>{},loadDomains:async()=>{},page:()=>{},toast:()=>{},
    api:async(...args)=>{requests.push(args);return handler(...args);},
    busy:(button,operation)=>{pending=operation();return pending;}
});
const helper=source.slice(source.indexOf('    function options('),source.indexOf('    function linkedOptions('));
vm.runInContext(fs.readFileSync('public/assets/i18n.js','utf8')+'\n'+helper+'\n'+source.slice(start,end)+'\nglobalThis.zoneUi={openZoneCreate,loadZoneAccounts};',context);
const ui=context.zoneUi,accountA='a'.repeat(32),accountB='b'.repeat(32);
(async()=>{
    handler=async()=>({items:[{id:accountA,name:'Owner <example>'}],demo:false});
    await ui.openZoneCreate();
    assert.equal(node('#zone-create-dialog').open,true);checks++;
    assert.equal(node('#zone-credential').value,1);checks++;
    assert.equal(node('#zone-account-id').value,accountA);checks++;
    assert.equal(node('#zone-account-id-label').hidden,true);checks++;
    assert(node('#zone-cloud-account').innerHTML.includes('Owner &lt;example&gt;'));checks++;
    node('#zone-cloud-account').value='manual';node('#zone-cloud-account').handlers.change();
    assert.equal(node('#zone-account-id-label').hidden,false);checks++;
    assert.equal(node('#zone-account-id').readOnly,false);checks++;
    let resolveOld;
    handler=()=>new Promise(resolve=>{resolveOld=resolve;});node('#zone-credential').value='1';const old=ui.loadZoneAccounts();
    handler=async()=>({items:[{id:accountB,name:'New account'}],demo:false});node('#zone-credential').value='2';await ui.loadZoneAccounts();
    resolveOld({items:[{id:accountA,name:'Old account'}],demo:false});await old;
    assert.equal(node('#zone-account-id').value,accountB);checks++;
    assert.equal(node('#zone-cloud-account').innerHTML.includes('Old account'),false);checks++;
    const count=requests.length;node('#zone-credential').value='';await ui.loadZoneAccounts();
    assert.equal(requests.length,count);checks++;
    assert.equal(node('#zone-create-submit').disabled,true);checks++;
    context.config.demo=true;node('#zone-credential').value='1';handler=async()=>({items:[],demo:true});await ui.loadZoneAccounts();
    assert.equal(node('#zone-create-submit').disabled,true);checks++;
    assert(node('#zone-create-status').textContent.includes('Demo mode'));checks++;
    context.config.demo=false;
    node('#zone-account-id').value=accountA;node('[name="name"]').value='example.test';
    handler=async(path)=>{assert.equal(path,'/api/admin/zones');return {id:7,name:'example.test',status:'pending',created:true,name_servers:['alice.ns.cloudflare.com','bob.ns.cloudflare.com']};};
    node('#zone-create-form').handlers.submit({preventDefault(){}});await pending;
    const post=requests.at(-1);
    assert.equal(post[1],'POST');checks++;
    assert.equal(post[2].cf_account_id,accountA);checks++;
    assert.equal(post[2].name,'example.test');checks++;
    assert.equal(node('#zone-name-servers').textContent,'alice.ns.cloudflare.com\nbob.ns.cloudflare.com');checks++;
    assert(node('#zone-info-status').textContent.includes('Waiting for nameserver activation'));checks++;
    assert.equal(node('#zone-create-fields').disabled,false);checks++;
    assert.equal(node('#zone-info-dialog').open,true);checks++;
    const key=post[3]['Idempotency-Key'];
    node('#zone-create-form').handlers.submit({preventDefault(){}});await pending;
    assert.equal(requests.at(-1)[3]['Idempotency-Key'],key);checks++;
    node('#zone-info-open').handlers.click();
    assert.equal(context.location.hash,'zones/7');checks++;
    console.log(`${checks} zone creation UI checks passed; account selection, stale responses, NS and idempotency covered.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
