// SPDX-License-Identifier: AGPL-3.0-only
'use strict';
const L=(()=>{
    const data=document.getElementById('locale-data');
    const dictionary=data?JSON.parse(data.textContent):{};
    const english=document.documentElement.lang==='en';
    const escape=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const text=key=>dictionary[key]??key;
    const markup=source=>{
        if(!english)return source;
        const replace=(value,attribute=false)=>{const key=value.trim(),translated=dictionary[key];return translated===undefined?value:value.replace(key,()=>escape(translated));};
        return source.split(/(<[^>]*>)/g).map(part=>part.startsWith('<')?part.replace(/\b(title|aria-label|placeholder|alt)=("([^"]*)"|'([^']*)')/g,(all,name,quoted,double,single)=>`${name}=${quoted[0]}${replace(double??single,true)}${quoted[0]}`):replace(part)).join('');
    };
    const translator=source=>/<[A-Za-z!/]/.test(source)?markup(source):text(source);
    translator.tpl=(strings,...values)=>{
        let source=strings[0];for(let i=0;i<values.length;i++)source+=`\uE000${i}\uE001`+strings[i+1];
        const keyed=source.replace(/\uE000(\d+)\uE001/g,'{$1}');
        const translated=/<[A-Za-z!/]/.test(keyed)?markup(keyed):text(keyed);
        return translated.replace(/\{(\d+)\}/g,(match,index)=>Number(index)<values.length?String(values[Number(index)]):match);
    };
    const patterns=Object.entries(dictionary).filter(([key])=>/\{\d+\}/.test(key)).sort((a,b)=>b[0].length-a[0].length).map(([key,value])=>{
        const slots=[];const source=key.split(/(\{\d+\})/).map(part=>{const match=part.match(/^\{(\d+)\}$/);if(match){slots.push(match[1]);return '(.*?)';}return part.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');}).join('');return {regex:new RegExp('^'+source+'$','su'),slots,value};
    });
    translator.message=source=>{source=String(source??'');if(!english)return source;if(dictionary[source]!==undefined)return dictionary[source];for(const pattern of patterns){const match=source.match(pattern.regex);if(!match)continue;const slots={};pattern.slots.forEach((slot,i)=>slots[slot]=match[i+1]);return pattern.value.replace(/\{(\d+)\}/g,(all,index)=>slots[index]??all);}return source;};
    document.addEventListener('change',event=>{if(!event.target.matches('[data-locale-switch]'))return;const value=event.target.value;if(!['zh-CN','en'].includes(value))return;document.cookie='panel_locale='+encodeURIComponent(value)+'; Path=/; Max-Age=31536000; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');location.reload();});
    return translator;
})();
