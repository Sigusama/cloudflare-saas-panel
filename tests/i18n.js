// SPDX-License-Identifier: AGPL-3.0-only
'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const dictionary=JSON.parse(fs.readFileSync('public/assets/locales/en.json','utf8'));
let checks=0;
for(const lang of ['en','zh-CN']){
    const context=vm.createContext({document:{documentElement:{lang},getElementById:()=>({textContent:JSON.stringify(lang==='en'?dictionary:{})}),addEventListener:()=>{}}});
    vm.runInContext(fs.readFileSync('public/assets/i18n.js','utf8')+'\nglobalThis.translate=L;',context);
    const L=context.translate;
    assert.equal(L('域名管理'),lang==='en'?'Domains':'域名管理');checks++;
    assert.equal(L.tpl`<strong>${'域名管理 & 用户'} </strong><button>编辑</button>`,`<strong>域名管理 & 用户 </strong><button>${lang==='en'?'Edit':'编辑'}</button>`);checks++;
    assert.equal(L.tpl`<input title="选择纳管 ${'www.测试.test'}">`,`<input title="${lang==='en'?'Select www.测试.test for adoption':'选择纳管 www.测试.test'}">`);checks++;
    assert.equal(L.message('入口名称已有不匹配 DNS，面板不会覆盖：www.测试.test'),lang==='en'?'Conflicting DNS exists at the entry name. The panel will not overwrite it: www.测试.test':'入口名称已有不匹配 DNS，面板不会覆盖：www.测试.test');checks++;
    assert.equal(L.tpl`候选：${'<img src=x onerror=alert(1)>'}`,lang==='en'?'Candidate: <img src=x onerror=alert(1)>':'候选：<img src=x onerror=alert(1)>');checks++;
    assert.equal(L.message('我的账号：域名管理'),'我的账号：域名管理');checks++;
}
console.log(`${checks} browser translation checks passed; user values retained.`);
