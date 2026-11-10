// SPDX-License-Identifier: AGPL-3.0-only
'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const dictionary=JSON.parse(fs.readFileSync('public/assets/locales/en.json','utf8'));
let checks=0;
for(const lang of ['en','zh-CN']){
    let changeLanguage,reloads=0;
    const document={documentElement:{lang},getElementById:()=>({textContent:JSON.stringify(lang==='en'?dictionary:{})}),addEventListener:(event,listener)=>{if(event==='change')changeLanguage=listener;}};
    const location={protocol:'https:',reload:()=>reloads++};
    const context=vm.createContext({document,location});
    vm.runInContext(fs.readFileSync('public/assets/i18n.js','utf8')+'\nglobalThis.translate=L;',context);
    const L=context.translate;
    assert.equal(L('域名管理'),lang==='en'?'Domains':'域名管理');checks++;
    assert.equal(L.tpl`<strong>${'域名管理 & 用户'} </strong><button>编辑</button>`,`<strong>域名管理 & 用户 </strong><button>${lang==='en'?'Edit':'编辑'}</button>`);checks++;
    assert.equal(L.tpl`<input title="选择纳管 ${'www.测试.test'}">`,`<input title="${lang==='en'?'Select www.测试.test for adoption':'选择纳管 www.测试.test'}">`);checks++;
    assert.equal(L.message('入口名称已有不匹配 DNS，面板不会覆盖：www.测试.test'),lang==='en'?'Conflicting DNS exists at the entry name. The panel will not overwrite it: www.测试.test':'入口名称已有不匹配 DNS，面板不会覆盖：www.测试.test');checks++;
    assert.equal(L.tpl`候选：${'<img src=x onerror=alert(1)>'}`,lang==='en'?'Candidate: <img src=x onerror=alert(1)>':'候选：<img src=x onerror=alert(1)>');checks++;
    assert.equal(L.message('我的账号：域名管理'),'我的账号：域名管理');checks++;
    for(const value of ['constructor','toString','__proto__','hasOwnProperty']){
        assert.equal(L(value),value);checks++;
        assert.equal(L.message(value),value);checks++;
    }
    assert.equal(L('<span>constructor</span>'),'<span>constructor</span>');checks++;
    assert.equal(L.tpl`Untranslated ${'{0} & $&'} and ${'用户值'}`,'Untranslated {0} & $& and 用户值');checks++;
    changeLanguage({target:{matches:()=>false,value:'en'}});
    assert.equal(reloads,0);checks++;
    changeLanguage({target:{matches:()=>true,value:'en'}});
    assert.equal(document.cookie,'panel_locale=en; Path=/; Max-Age=31536000; SameSite=Lax; Secure');checks++;
    assert.equal(reloads,1);checks++;
    changeLanguage({target:{matches:()=>true,value:'fr'}});
    assert.equal(reloads,1);checks++;
    location.protocol='http:';
    changeLanguage({target:{matches:()=>true,value:'zh-CN'}});
    assert.equal(document.cookie,'panel_locale=zh-CN; Path=/; Max-Age=31536000; SameSite=Lax');checks++;
    assert.equal(reloads,2);checks++;
}
console.log(`${checks} browser translation checks passed; user values retained.`);
