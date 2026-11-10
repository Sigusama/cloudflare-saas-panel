// SPDX-License-Identifier: AGPL-3.0-only
'use strict';
const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const csrf = $('meta[name="csrf-token"]')?.content;
let toastTimer;
function toast(message, error = false) {
    const element = $('#toast'); element.textContent = message; element.classList.toggle('error', error); element.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { element.hidden = true; }, error ? 15000 : 6000);
}
async function api(path, method = 'GET', data, extraHeaders = {}) {
    const response = await fetch(path, {method, credentials: 'same-origin', headers: {'X-CSRF-Token': csrf, 'Accept': 'application/json', ...(data === undefined ? {} : {'Content-Type': 'application/json'}), ...extraHeaders}, ...(data === undefined ? {} : {body: JSON.stringify(data)})});
    let body;
    try { body = await response.json(); } catch { throw new Error('服务器未返回 JSON，请检查运行日志'); }
    if (!response.ok || !body.success) throw new Error(body.error?.message ?? '请求失败');
    return body.data;
}
function fields(form) {
    const data = Object.fromEntries(new FormData(form));
    if ($('[name="replace_existing"]', form)) data.replace_existing = $('[name="replace_existing"]', form).checked;
    if ($('[name="check_path"]', form)) {
        data.check = {path: data.check_path, statuses: data.check_statuses, keyword: data.check_keyword};
        delete data.check_path; delete data.check_statuses; delete data.check_keyword;
    }
    return data;
}
async function busy(button, operation) {
    if (button) button.disabled = true;
    try { return await operation(); } catch (error) { toast(error.message, true); }
    finally { if (button) button.disabled = false; }
}
$$('form[data-auth]').forEach(form => form.addEventListener('submit', event => {
    event.preventDefault(); busy($('button[type="submit"],button.primary', form), async () => {
        const result = await api(form.dataset.auth, 'POST', fields(form)); window.location.assign(result.redirect);
    });
}));
if (document.body.hasAttribute('data-panel')) {
    let config = {accounts: [], profiles: [], zones: [], entries: [], origins: []};
    let currentPage = 'domains'; let domainPage = 1; let domainTotal = 0;let logPage=1;let logTotal=0;let logFilters={};
    let zoneItems=[], selectedZoneId=Number(location.hash.match(/^#zones\/(\d+)$/)?.[1]??0), recordSearch='', domainLoad=0;
    let importDocument=null, importRequest=null, changeSourceOrigin=null, importRevision=0;
    const selectedRecords=new Map();let visibleRecords=new Map(), bulkContext=null, bulkBusy=false;
    let observedRecords=new Map();const pendingDnsSyncs=new Map(),dnsSyncTimes=new Map();
    let rawEditor=null;
    let inventoryState=null,inventoryItems=[],inventoryPage=1,inventoryTotal=0,inventorySearch='',inventoryBusy=false,inventoryPause=false,adoptionBusy=false,adoptionContext=null,inventoryRevision=0,inventoryItemsSeq=0;
    const inventorySelection=new Map();
    const titles = {domains: ['域名管理', '集中管理接入状态、优选目标和回源预设。'], logs: ['任务与日志', '每次解析变更、验证和 TXT 清理都有记录。'], accounts: ['Cloudflare 账号', '管理云端凭据，同步可操作的 DNS Zone。'], profiles: ['CF SaaS服务设置', '优选接入使用的 Cloudflare 站点，保存标准 CNAME 目标和默认回源。'], entries: ['优选目标', '选择入口主域名和自定义前缀，再填写优选 CNAME 或多个 IP。'], origins: ['回源预设', '网站内容从哪里获取：保存可复用的回源域名，如 dns.xxx.com、cn.xxx.com。'], tokens: ['公开 API', '使用有权限范围的 API Key 集成外部程序。'], settings: ['安全设置', '维护管理员访问凭据。']};
    function renderBreadcrumb(name, zone = null) {
        const inZone = name === 'domains' && Boolean(zone);
        $('#breadcrumb-domains').hidden = !inZone;
        $('#breadcrumb-zone-separator').hidden = !inZone;
        $('#breadcrumb-title').textContent = inZone ? zone.name : titles[name][0];
    }
    const labels = {queued: '排队中', waiting: '等待验证 / 重试', active: '接入完成', completed: '已完成', failed: '执行失败', needs_attention: '待处理', paused: '已暂停', rolled_back: '已回滚', rollback_failed: '回滚失败', cleanup_pending: '清理待完成', pending: '等待中', precheck: '预检', hostname: '创建主机名', routing: '配置入口', diagnose: '访问检查', cleanup: '清理 TXT', rollback: '回滚', cancelled: '已取消',changing:'配置切换中',change_precheck:'切换预检',change_origin:'切换回源',change_routing:'切换入口',change_diagnose:'检查新配置',change_restore:'恢复本次变更',applying:'应用中',applied:'已生效',restoring:'恢复中',removing:'移除中',remove:'恢复解析并移除',unmanaged:'已移除管理'};
    const badge = state => `<span class="badge ${escapeHtml(state)}">${escapeHtml(labels[state] ?? state)}</span>`;
    titles.inventory=['导入已有 SaaS','首次读取、纳管已有配置时使用；日常新增、编辑和模式切换在域名管理中完成。'];
    Object.assign(labels,{inspect:'只读检查已有配置',not_managed:'原有验证记录保留'});
    const inventoryPhase={pending:'待读取',reading:'读取中',completed:'已读取',failed:'读取失败',retry:'等待重试',not_configured:'未配置（普通 DNS 不受影响）'};
    const inventoryCloudStatus={active:'正常',pending:'待验证',pending_validation:'待验证',initializing:'初始化中',pending_issuance:'签发中',pending_deployment:'部署中',active_redeploying:'重新部署中'};
    function renderInventoryState(data){
        inventoryState=data;const run=data.run,reading=run&&run.state!=='completed';
        const issues=(run?.cursor?.sync_errors?.length??0)+(data.zones??[]).reduce((count,zone)=>count+Object.keys(zone.errors).length,0);
        $('#inventory-start').disabled=inventoryBusy;$('#inventory-resume').hidden=!reading||inventoryBusy;$('#inventory-pause').hidden=!inventoryBusy;
        $('#inventory-source').textContent=run?`${run.source==='demo'?'本地演示数据（未连接 Cloudflare）':'Cloudflare API'} · 批次 #${run.id} · ${run.created_at} UTC`:'';
        $('#inventory-progress').textContent=!run?'尚未读取。添加账号并同步主域名后，会自动读取已有 SaaS。':(run.state==='completed'?(issues?`读取结束，已关联 ${run.total} 条；有 ${issues} 处读取问题，结果可能不完整，请展开查看。`:`读取完成，已关联 ${run.total} 条 SaaS 主机名。`):(run.state==='linking'?`正在关联 DNS 与回源，已处理 ${run.cursor.link} 条。`:`${inventoryBusy?'正在读取':'读取已暂停'}：主域名 ${Math.min(run.cursor.zone+1,data.zones.length)} / ${data.zones.length}，${{dns:'DNS 记录',fallback:'默认回源',hosts:'SaaS 域名'}[run.cursor.phase]}，第 ${run.cursor.page} 页。`));
        $('#inventory-zones').innerHTML=(run?.cursor?.sync_errors??[]).map(error=>`<p class="inventory-warning">${escapeHtml(error)}</p>`).join('')+(data.zones??[]).map(zone=>`<div class="inventory-zone"><strong>${escapeHtml(zone.zone_name)}</strong><span>${escapeHtml(zone.account_name)} · DNS ${escapeHtml(inventoryPhase[zone.dns_status]??zone.dns_status)}（${zone.dns_count}）${Number(zone.read_saas)?` · SaaS ${escapeHtml(inventoryPhase[zone.hostnames_status]??zone.hostnames_status)}（${zone.hostname_count}） · 默认回源 ${escapeHtml(inventoryPhase[zone.fallback_status]??zone.fallback_status)}`:' · 仅关联 DNS'}</span>${Object.values(zone.errors).map(error=>`<small class="inventory-warning">${escapeHtml(error)}</small>`).join('')}</div>`).join('');
    }
    async function loadInventoryItems(){
        if(!inventoryState?.run){inventoryItems=[];inventoryTotal=0;renderInventoryItems();return;}
        const sequence=++inventoryItemsSeq,run=inventoryState.run.id;
        const data=await api(`/api/admin/saas-inventory/${run}/items?page=${inventoryPage}&per_page=20&search=${encodeURIComponent(inventorySearch)}`);
        if(sequence!==inventoryItemsSeq||run!==inventoryState?.run?.id)return;
        inventoryItems=data.items;inventoryTotal=data.total;renderInventoryItems();
    }
    function renderInventoryItems(){
        const names={confirmed:'已关联',pending:'待确认',blocked:'需处理'};
        $('#inventory-rows').innerHTML=inventoryItems.map(item=>{
            const selectable=!item.managed&&item.confidence!=='blocked'&&inventoryState?.run?.state==='completed';
            const reasons=item.managed?[]:[...item.blockers,...item.reasons,...item.routes.flatMap(route=>[...route.reasons,...route.blockers])];
            const chains=[...new Set(item.routes.map(route=>route.chain.join(' → ')))],routes=item.routes.filter(route=>!route.blockers.length);
            const choices=!item.managed&&routes.length>1?`<small>候选：${routes.map(route=>escapeHtml(route.account_name)+(route.entry_account_name?' / '+escapeHtml(route.entry_account_name):'')).join('；')}</small>`:'';
            return `<tr><td><input type="checkbox" aria-label="选择纳管 ${escapeHtml(item.hostname)}" data-inventory-key="${item.key}" ${inventorySelection.has(item.key)?'checked':''} ${selectable?'':'disabled'}></td><td><strong>${escapeHtml(item.hostname)}</strong><small>${escapeHtml(item.account_name)} · ${escapeHtml(item.zone_name)}</small><small>CF 主机名：${escapeHtml(inventoryCloudStatus[item.remote.status]??item.remote.status)} · 证书：${escapeHtml(item.remote.ssl.status==='active'?'有效':(inventoryCloudStatus[item.remote.ssl.status]??item.remote.ssl.status))}</small></td><td>${chains.map(chain=>`<span class="inventory-chain">${escapeHtml(chain)}</span>`).join('')||'DNS 未关联'}${choices}</td><td>${item.origin?`<strong>${escapeHtml(item.origin.hostname)}</strong><small>${item.origin.targets.map(escapeHtml).join(' / ')}</small>`:'回源未确认'}</td><td><span class="badge ${item.managed?'active':item.confidence==='confirmed'?'completed':'needs_attention'}">${item.managed?'已纳管':names[item.confidence]}</span>${[...new Set(reasons)].map(reason=>`<small>${escapeHtml(reason)}</small>`).join('')}</td></tr>`;
        }).join('');
        $('#inventory-empty').hidden=inventoryItems.length>0;$('#inventory-empty').textContent=inventoryState?.run?.state==='completed'?'没有匹配的已有 SaaS 域名，可展开读取结果查看权限或服务配置问题。':'读取完成后显示已有 SaaS；每次最多选择 20 条纳管。';
        $('#inventory-page').textContent=`第 ${inventoryPage} 页 · 共 ${inventoryTotal} 条`;$('#inventory-prev').disabled=inventoryPage<=1;$('#inventory-next').disabled=inventoryPage*20>=inventoryTotal;
        updateInventorySelection();
    }
    function updateInventorySelection(){
        $('#inventory-adopt').disabled=!inventorySelection.size||inventoryBusy;$('#inventory-adopt').textContent=`预览纳管选中项（${inventorySelection.size} / 20）`;
        const enabled=$$('[data-inventory-key]:not(:disabled)');$('#inventory-select-page').checked=enabled.length>0&&enabled.every(input=>input.checked);$('#inventory-select-page').indeterminate=enabled.some(input=>input.checked)&&!enabled.every(input=>input.checked);
        $('#inventory-select-page').disabled=enabled.length===0;
    }
    async function loadInventory(){if(inventoryBusy)return;const revision=++inventoryRevision,data=await api('/api/admin/saas-inventory');if(revision!==inventoryRevision)return;if(data.run?.id!==inventoryState?.run?.id)inventorySelection.clear();renderInventoryState(data);await loadInventoryItems();}
    async function resumeInventory(){
        if(inventoryBusy||!inventoryState?.run||inventoryState.run.state==='completed')return;
        inventoryBusy=true;inventoryPause=false;renderInventoryState(inventoryState);updateInventorySelection();
        try{while(inventoryState.run.state!=='completed'&&!inventoryPause&&currentPage==='inventory'){
            const retryUntil=Date.now()+(inventoryState.run.retry_after??0)*1000;
            while(Date.now()<retryUntil&&!inventoryPause&&currentPage==='inventory'){$('#inventory-progress').textContent=`Cloudflare 读取需等待 ${Math.ceil((retryUntil-Date.now())/1000)} 秒，随后自动重试。`;await new Promise(resolve=>setTimeout(resolve,Math.min(1000,retryUntil-Date.now())));}
            if(inventoryPause||currentPage!=='inventory')break;
            renderInventoryState(await api(`/api/admin/saas-inventory/${inventoryState.run.id}/step`,'POST',{}));
        }if(inventoryState.run.state==='completed'){await loadInventoryItems();}}
        finally{inventoryBusy=false;renderInventoryState(inventoryState);updateInventorySelection();}
    }
    async function startInventory(accountIds=[]){
        if(inventoryBusy)throw new Error('已有批次正在读取，请等待或暂停后重新读取。');
        page('inventory');inventoryRevision++;inventoryBusy=true;inventoryPause=false;inventorySelection.clear();inventoryPage=1;inventorySearch='';$('#inventory-search').reset();$('#inventory-start').disabled=true;$('#inventory-resume').hidden=true;$('#inventory-pause').hidden=false;
        try{
            const syncErrors=[],accounts=config.accounts.filter(account=>Number(account.active));
            for(const account of accounts){if(inventoryPause)break;$('#inventory-progress').textContent=`正在同步授权主域名：${account.name}`;try{await api(`/api/admin/accounts/${account.id}/sync`,'POST',{});}catch(error){syncErrors.push(Array.from(account.name+'：'+error.message).slice(0,512).join(''));}}
            await loadConfig();const state=await api('/api/admin/saas-inventory','POST',{account_ids:accountIds,sync_errors:syncErrors},{'Idempotency-Key':crypto.randomUUID()});renderInventoryState(state);inventoryItems=[];inventoryTotal=0;renderInventoryItems();
        }finally{inventoryBusy=false;if(inventoryState)renderInventoryState(inventoryState);else{$('#inventory-start').disabled=false;$('#inventory-pause').hidden=true;}}
        if(!inventoryPause&&currentPage==='inventory')await resumeInventory();
    }
    $('#inventory-start').addEventListener('click',()=>busy(null,()=>startInventory()));$('#inventory-resume').addEventListener('click',()=>busy(null,resumeInventory));
    $('#inventory-pause').addEventListener('click',()=>{inventoryPause=true;$('#inventory-progress').textContent='正在保存本页读取结果，随后暂停…';});
    $('#inventory-search').addEventListener('submit',event=>{event.preventDefault();inventorySearch=fields(event.currentTarget).search;inventoryPage=1;inventorySelection.clear();busy(null,loadInventoryItems);});
    $('#inventory-prev').addEventListener('click',()=>{if(inventoryPage>1){inventoryPage--;busy(null,loadInventoryItems);}});$('#inventory-next').addEventListener('click',()=>{if(inventoryPage*20<inventoryTotal){inventoryPage++;busy(null,loadInventoryItems);}});
    document.addEventListener('change',event=>{const input=event.target;if(!input.matches('[data-inventory-key]'))return;const item=inventoryItems.find(row=>row.key===input.dataset.inventoryKey);if(input.checked){if(inventorySelection.size>=20){input.checked=false;toast('每次最多选择 20 条纳管',true);return;}inventorySelection.set(item.key,item);}else{inventorySelection.delete(item.key);}updateInventorySelection();});
    $('#inventory-select-page').addEventListener('change',event=>{for(const input of $$('[data-inventory-key]:not(:disabled)')){if(event.currentTarget.checked&&inventorySelection.size>=20&&!inventorySelection.has(input.dataset.inventoryKey))continue;input.checked=event.currentTarget.checked;const item=inventoryItems.find(row=>row.key===input.dataset.inventoryKey);if(input.checked)inventorySelection.set(item.key,item);else inventorySelection.delete(item.key);}updateInventorySelection();});
    function adoptionInvalidate(){if(adoptionBusy)return;if(adoptionContext){adoptionContext.ready=[];}for(const text of $$('[data-adoption-status]'))text.textContent='';$('#inventory-commit').disabled=true;$('#inventory-adopt-result').textContent='核对信息后，先检查纳管计划。';}
    function adoptionRoute(card){
        const item=inventorySelection.get(card.dataset.adoptionKey),route=item.routes.find(route=>route.id===$('[data-adoption-route]',card).value);
        $('[data-adoption-chain]',card).textContent=route?route.chain.join(' → '):'请选择 DNS 关联方案';
        const confirm=$('[data-adoption-confirm]',card);confirm.required=Boolean(route&&!route.known);confirm.disabled=!route||route.known;confirm.checked=Boolean(route?.known);confirm.closest('label').hidden=Boolean(route?.known);adoptionInvalidate();
    }
    $('#inventory-adopt').addEventListener('click',()=>{
        adoptionContext={run:inventoryState.run.id,ready:[]};$('#inventory-adopt-plans').innerHTML=[...inventorySelection.values()].map(item=>{
            const routes=item.routes.filter(route=>!route.blockers.length);
            return `<section class="inventory-plan" data-adoption-key="${item.key}"><h3>${escapeHtml(item.hostname)}</h3><p class="hint">回源：${escapeHtml(item.origin.hostname)} → ${item.origin.targets.map(escapeHtml).join(' / ')}</p><div class="form-grid"><label>DNS 关联方案<select data-adoption-route required>${routes.length>1?'<option value="">请选择所属账号</option>':''}${routes.map(route=>`<option value="${escapeHtml(route.id)}">${escapeHtml(route.account_name)} · ${escapeHtml(route.zone_name)}${route.entry_zone_ref?' · '+escapeHtml(route.entry_account_name)+' / '+escapeHtml(route.entry.hostname):''}</option>`).join('')}</select></label><label ${item.profile.cname_candidates.length?'':'hidden'}>标准 SaaS CNAME<select data-adoption-standard ${item.profile.cname_candidates.length?'required':'disabled'}>${item.profile.cname_target?'':'<option value="">请选择服务入口</option>'}${item.profile.cname_candidates.map(name=>`<option value="${escapeHtml(name)}" ${name===item.profile.cname_target?'selected':''}>${escapeHtml(name)}</option>`).join('')}</select></label></div><p class="inventory-chain" data-adoption-chain></p><label class="checkbox"><input type="checkbox" data-adoption-confirm>确认当前解析用于优选（此项不代表测速结果）</label><p class="hint" data-adoption-status></p></section>`;
        }).join('');
        for(const card of $$('[data-adoption-key]'))adoptionRoute(card);adoptionInvalidate();$('#inventory-adopt-dialog').showModal();
    });
    $('#inventory-adopt-fields').addEventListener('change',event=>{if(event.target.matches('[data-adoption-route]'))adoptionRoute(event.target.closest('[data-adoption-key]'));else adoptionInvalidate();});
    function setAdoptionBusy(value){adoptionBusy=value;$('#inventory-adopt-fields').disabled=value;for(const button of $$('button',$('#inventory-adopt-dialog')))button.disabled=value;$('#inventory-commit').disabled=value||!adoptionContext?.ready.length;}
    $('#inventory-adopt-dialog').addEventListener('cancel',event=>{if(adoptionBusy)event.preventDefault();});
    $('#inventory-preview').addEventListener('click',async()=>{
        if(adoptionBusy||!adoptionContext)return;adoptionContext.ready=[];setAdoptionBusy(true);let failed=0;
        try{for(const card of $$('[data-adoption-key]')){
            const request={key:card.dataset.adoptionKey,route_id:$('[data-adoption-route]',card).value,cname_target:$('[data-adoption-standard]',card).value,confirm_preferred:$('[data-adoption-confirm]',card).checked};
            try{const result=await api(`/api/admin/saas-inventory/${adoptionContext.run}/adopt`,'POST',{...request,preview:true});adoptionContext.ready.push({request,key:crypto.randomUUID(),card});$('[data-adoption-status]',card).textContent=result.reused?'检查通过，复用已有相同配置。':`检查通过：${result.chain.join(' → ')}；纳管后只读检查。`;}
            catch(error){failed++;$('[data-adoption-status]',card).textContent=error.message;}
        }$('#inventory-adopt-result').textContent=`检查完成：${adoptionContext.ready.length} 条可纳管，${failed} 条需处理。确认仅提交检查通过的条目。`;}
        finally{setAdoptionBusy(false);}
    });
    $('#inventory-commit').addEventListener('click',async()=>{
        if(adoptionBusy||!adoptionContext?.ready.length)return;setAdoptionBusy(true);const retry=[];let saved=0;
        try{for(const item of adoptionContext.ready){try{const result=await api(`/api/admin/saas-inventory/${adoptionContext.run}/adopt`,'POST',{...item.request,preview:false},{'Idempotency-Key':item.key});$('[data-adoption-status]',item.card).textContent=result.reused?'已复用原有管理记录。':`已纳管，任务 #${result.job_id} 将只读检查现有配置与 HTTPS。`;inventorySelection.delete(item.request.key);saved++;}catch(error){$('[data-adoption-status]',item.card).textContent=error.message;retry.push(item);}}
            adoptionContext.ready=retry;$('#inventory-adopt-result').textContent=`已完成 ${saved} 条，${retry.length} 条待处理。重试提交沿用原请求编号。`;await loadConfig();await loadInventoryItems();
        }finally{setAdoptionBusy(false);}
    });
    Object.assign(labels,{not_required:'不需要',change_provision:'准备 SaaS 主机名',change_validation:'验证 SaaS 主机名与证书'});
    const dnsModeNames={dns_only:'关云 · 仅 DNS',proxied:'开小黄云',preferred:'优选'};
    const modeIcon=mode=>mode==='preferred'?'<svg class="mode-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="m11 2-7 9h5l-1 7 8-10h-5l1-6Z"/></svg>':'<svg class="mode-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 15a4 4 0 0 1-.5-8 5.5 5.5 0 0 1 10.4-1.2A4.6 4.6 0 0 1 15 15H5Z"/></svg>';
    function modeButtons(item){return `<div class="dns-mode-switch compact" role="group" aria-label="${escapeHtml(item.hostname)} DNS 模式">${Object.entries(dnsModeNames).map(([mode,name])=>`<button type="button" class="${mode===item.dns_mode?'selected':''}" aria-label="${name}" aria-pressed="${mode===item.dns_mode}" data-record-mode="${mode}" data-record-id="${item.id}" data-record-version="${item.config_version}" ${item.status!=='active'||mode===item.dns_mode||mode!=='preferred'&&!item.applied_config.origin.source_ip&&!item.applied_config.origin.source_hostname?'disabled':''} title="${mode!=='preferred'&&!item.applied_config.origin.source_ip&&!item.applied_config.origin.source_hostname?'请编辑记录，填写回源 IP 或 CNAME':name}">${modeIcon(mode)}<span>${{dns_only:'关云',proxied:'开云',preferred:'优选'}[mode]}</span></button>`).join('')}</div>`;}
    $$('[data-dns-mode]').forEach(button=>{const mode=button.dataset.dnsMode;button.innerHTML=modeIcon(mode)+`<span>${dnsModeNames[mode]}</span>`;button.setAttribute('aria-label',dnsModeNames[mode]);});
    const empty = '<p class="muted">暂无配置，添加后可在接入时选择。</p>';
    function options(select, items, value = 'id', text = 'name') {
        const previous = select.value;
        select.innerHTML = '<option value="">请选择</option>' + items.map(item => `<option value="${escapeHtml(item[value])}">${escapeHtml(item[text])}</option>`).join('');
        if (items.some(item => String(item[value]) === previous)) select.value = previous;
        else if (items.length) select.value = items[0][value];
    }
    function linkedOptions() {
        options($('#profile-zone'), config.zones.filter(zone => String(zone.account_id) === $('#profile-account').value), 'cf_id');
        options($('#domain-origin'), config.origins.filter(origin => String(origin.profile_id) === $('#domain-profile').value));
    }
    function entryHostname(){
        const zone=config.zones.find(item=>String(item.id)===$('#entry-zone').value),name=$('#entry-record').value.trim().toLowerCase().replace(/\.$/,'');
        if(!zone||!name)return '';
        return name===zone.name||name.endsWith('.'+zone.name)?name:name+'.'+zone.name;
    }
    function entryPreview(){
        const enabled=$('#entry-use-alias').checked;
        $('#entry-kind option[value="standard"]').disabled=enabled;
        if(enabled&&$('#entry-kind').value==='standard')$('#entry-kind').value='auto';
        $('#entry-alias-fields').hidden=!enabled;
        for(const input of [$('#entry-zone'),$('#entry-record')]){input.disabled=!enabled;input.required=enabled;}
        const target=$('[name="targets"]',$('form[data-resource="entries"]')).value.trim().split(/[\s,]+/).filter(Boolean).join(' / ');
        $('#entry-alias-preview').textContent=`${entryHostname()||'自定义入口 xxx.aaa.com'} → ${target||'优选 CNAME 或 IP 组'}`;
    }
    $('#entry-zone').addEventListener('change',entryPreview);$('#entry-record').addEventListener('input',entryPreview);
    $('#entry-use-alias').addEventListener('change',entryPreview);
    $('[name="targets"]',$('form[data-resource="entries"]')).addEventListener('input',entryPreview);
    let discoveryRevision = 0;
    async function readSaas() {
        const form=$('form[data-resource="profiles"]'),account=$('#profile-account').value,zone=$('#profile-zone').value;
        const revision=++discoveryRevision;
        $('#profile-candidate-label').hidden=true;$('#profile-candidates').required=false;
        if(!form.dataset.editId){for(const name of ['cname_target','fallback_origin'])$(`[name="${name}"]`,form).value='';const selected=config.zones.find(item=>String(item.account_id)===account&&item.cf_id===zone);$('[name="name"]',form).value=selected?selected.name+' SaaS':'';}
        if(!account||!zone)return;
        $('#profile-discover').disabled=true;$('#profile-discovery-status').textContent='正在从 CF 读取…';
        try {
            const data=await api(`/api/admin/accounts/${account}/saas-discovery?zone_id=${encodeURIComponent(zone)}`);
            if(revision!==discoveryRevision)return;
            if(!form.dataset.editId){const existing=config.profiles.find(profile=>String(profile.account_id)===account&&profile.zone_id===zone);$('[name="name"]',form).value=existing?.name??data.name;}
            $('[name="fallback_origin"]',form).value=data.fallback_origin;
            $('[name="cname_target"]',form).value=data.cname_target??'';
            const choices=$('#profile-candidates');choices.innerHTML='<option value="">请选择一个接入域名</option>'+data.cname_candidates.map(name=>`<option value="${escapeHtml(name)}">${escapeHtml(name)}</option>`).join('');
            choices.value=data.cname_target??'';choices.required=data.cname_candidates.length>1;
            $('#profile-candidate-label').hidden=data.cname_candidates.length<2;
            $('#profile-manual').open=data.cname_candidates.length===0;
            $('#profile-discovery-status').textContent=(data.source==='demo'?'已读取本地演示配置（未连接 CF）。':`已读取 CF 默认回源：${data.fallback_origin}（${data.fallback_status}）。`)+(data.cname_target?'接入域名已自动填好，直接保存即可。':(data.cname_candidates.length?'找到多个接入域名，请选一个后保存。':'未找到指向该默认回源的橙云 CNAME，请在高级设置补充标准接入域名。'));
        }catch(error){if(revision===discoveryRevision){$('#profile-discovery-status').textContent=error.message;$('#profile-manual').open=true;}}
        finally{if(revision===discoveryRevision)$('#profile-discover').disabled=false;}
    }
    function fillCheck(form, check) {
        $('[name="check_path"]', form).value = check?.path ?? '/';
        $('[name="check_statuses"]', form).value = (check?.statuses ?? [200,201,202,204,301,302,303,307,308]).join(',');
        $('[name="check_keyword"]', form).value = check?.keyword ?? '';
    }
    function loadDefaultsForm() {
        options($('#default-profile'),config.profiles.filter(r=>Number(r.active)));
        options($('#default-account'),config.accounts.filter(r=>Number(r.active)));
        options($('#default-entry'),config.entries.filter(r=>Number(r.active)));
        $('#default-profile').value = config.defaults.profile_id ?? '';
        options($('#default-origin'),config.origins.filter(r=>Number(r.active)&&String(r.profile_id)===$('#default-profile').value));
        $('#default-account').value=config.defaults.dns_account_id ?? '';
        $('#default-entry').value=config.defaults.entry_id ?? '';
        $('#default-origin').value=config.defaults.origin_id ?? '';
        fillCheck($('#defaults-form'),config.defaults.check);
    }
    function accountAuthFields() {
        const form=$('form[data-resource="accounts"]');const global=$('#account-auth-type').value==='global_key';
        const keep=Boolean(form.dataset.editId)&&form.dataset.previousAuth===$('#account-auth-type').value;
        $('#account-token-label').hidden=global;$('#account-token-help').hidden=global;
        $('#account-email-label').hidden=!global;$('#account-key-label').hidden=!global;$('#account-key-help').hidden=!global;
        const token=$('[name="token"]',form),email=$('[name="email"]',form),key=$('[name="global_key"]',form);
        token.disabled=global;token.required=!global&&!keep;email.disabled=!global;email.required=global;key.disabled=!global;key.required=global&&!keep;
        token.placeholder=keep?'留空保留现有 Token，填写后轮换':'';key.placeholder=keep?'留空保留现有密钥，填写后轮换':'';
        $('button.primary',form).textContent=form.dataset.editId?'保存修改':(global?'保存并连接账号':'保存并同步 Zone');
    }
    $('#account-auth-type').addEventListener('change',()=>{const form=$('form[data-resource="accounts"]');$('[name="token"]',form).value='';$('[name="global_key"]',form).value='';accountAuthFields();});
    function resetEditor(form) {
        const resource=form.dataset.resource; delete form.dataset.editId; form.reset();
        $('h2',form).textContent={accounts:'添加 Cloudflare 账号',profiles:'添加 CF SaaS服务设置',entries:'添加优选目标',origins:'添加回源预设'}[resource];
        $('button.primary',form).textContent={accounts:'保存并同步 Zone',profiles:'保存接入配置',entries:'保存优选目标',origins:'保存回源预设'}[resource];
        $('[data-cancel-edit]',form).hidden=true;
        if(resource==='accounts') {delete form.dataset.previousAuth;accountAuthFields();}
        if(resource==='entries'){$('#entry-legacy-option').hidden=true;$('#entry-shared-help').hidden=true;entryPreview();}
        if(resource==='profiles'){discoveryRevision++;$('#profile-discover').disabled=false;$('#profile-candidate-label').hidden=true;$('#profile-candidates').required=false;$('[name="cname_target"]',form).required=true;$('#profile-manual').open=false;$('#profile-discovery-status').textContent='选择主域名后自动读取默认回源与标准接入域名。';}
    }
    function configActions(resource, row) {
        return `<div class="actions config-actions"><span class="badge">${Number(row.active)?'启用':'停用'} · ${row.references} 处引用</span><button class="secondary small" data-config-edit="${resource}" data-config-id="${row.id}">编辑</button><button class="secondary small" data-config-toggle="${resource}" data-config-id="${row.id}">${Number(row.active)?'停用':'启用'}</button><button class="secondary small" data-config-delete="${resource}" data-config-id="${row.id}" ${Number(row.references)?'disabled title="先处理引用关系"':''}>删除</button></div>`;
    }
    async function loadConfig() {
        config = await api('/api/v1/config');
        options($('#entry-zone'),config.zones.filter(zone=>zone.status==='active'&&config.accounts.some(account=>Number(account.id)===Number(zone.account_id)&&Number(account.active))).map(zone=>({...zone,name:zone.name+' · '+(config.accounts.find(account=>Number(account.id)===Number(zone.account_id))?.name??'')})));
        entryPreview();
        $('#demo-banner').hidden=!config.demo;
        $$('.account-select').forEach(select => options(select, config.accounts.filter(a => Number(a.active))));
        $$('.profile-select').forEach(select => options(select, config.profiles.filter(a => Number(a.active))));
        options($('#domain-entry'), config.entries.filter(a => Number(a.active))); linkedOptions();
        const heartbeat = config.worker_heartbeat ? new Date(config.worker_heartbeat).getTime() : 0;
        $('#worker-state').textContent = heartbeat && Date.now() - heartbeat < 120000 ? '后台执行器运行中' : '后台尚未运行或已离线';
        $('#accounts-list').innerHTML = config.accounts.map(item => `<div class="list-item"><strong>${escapeHtml(item.name)}</strong> <span class="badge">${item.auth_type==='global_key'?'Global API Key':'API Token'}</span><p>${config.zones.filter(z => z.account_id === item.id).map(z => escapeHtml(z.name)).join(' · ') || '尚未同步 Zone'}</p><button class="secondary small" data-sync="${item.id}" ${Number(item.active)?'':'disabled'}>同步 Zone</button></div>`).join('') || empty;
        $('#profiles-list').innerHTML = config.profiles.map(item => `<div class="list-item"><strong>${escapeHtml(item.name)}</strong><p>${escapeHtml(item.zone_name)}<br>标准入口：${escapeHtml(item.cname_target||'按已有优选配置纳管')}<br>默认回源：${escapeHtml(item.fallback_origin)}</p><div class="actions"><button class="secondary small" data-profile-check="${item.id}">检查状态</button><button class="secondary small" data-profile-setup="${item.id}">设置入口与默认回源</button></div></div>`).join('') || empty;
        $('#entries-list').innerHTML = config.entries.map(item => `<div class="list-item"><strong>${escapeHtml(item.name)}</strong><p>${escapeHtml((item.hostname?item.hostname+' → ':'')+(item.kind === 'standard' ? '使用所属 SaaS 标准 CNAME' : item.targets.join(' · ')))}</p><span class="badge">${escapeHtml(item.hostname?(item.route_state==="applied"?"入口已配置":"待首次接入创建"):item.kind)}</span></div>`).join('') || empty;
        $('#origins-list').innerHTML = config.origins.map(item => `<div class="list-item"><strong>${escapeHtml(item.name)}</strong><p>${escapeHtml(item.hostname)}<br>${escapeHtml(config.profiles.find(p => p.id === item.profile_id)?.name ?? '')} · ${item.mode === 'custom' ? '独立回源' : 'SaaS 默认回源'}</p></div>`).join('') || empty;
        ['accounts','profiles','entries','origins'].forEach(resource=>$$(`#${resource}-list .list-item`).forEach((node,index)=>node.insertAdjacentHTML('beforeend',configActions(resource,config[resource][index]))));
        loadDefaultsForm();
    }
    function observedRecordRow(item,zone){
        const record=item.record,name=item.hostname===zone.name?'@':(item.hostname.endsWith('.'+zone.name)?item.hostname.slice(0,-zone.name.length-1):item.hostname);
        const content=record.content||(record.data?JSON.stringify(record.data):'—');
        const pending=item.operation&&['queued','waiting','waiting_domain'].includes(item.operation.status);
        const modes=['A','AAAA','CNAME'].includes(record.type)?`<div class="dns-mode-switch compact" role="group" aria-label="${escapeHtml(item.hostname)} DNS 模式">${Object.keys(dnsModeNames).map(mode=>`<button type="button" class="${mode===item.dns_mode?'selected':''}" aria-label="${dnsModeNames[mode]}" aria-pressed="${mode===item.dns_mode}" data-raw-mode="${mode}" data-raw-id="${escapeHtml(item.id)}" ${pending||mode===item.dns_mode?'disabled':''}>${modeIcon(mode)}<span>${{dns_only:'关云',proxied:'开云',preferred:'优选'}[mode]}</span></button>`).join('')}</div>`:'<span class="badge">仅 DNS</span>';
        return `<tr><td><span class="badge">${escapeHtml(record.type)}</span></td><td><strong>${escapeHtml(name)}</strong><small>${escapeHtml(item.hostname)}</small></td><td><span class="muted">CF 原有记录</span></td><td>${modes}</td><td><span class="dns-target">${escapeHtml(content)}</span>${record.priority!==undefined?`<small>优先级 ${escapeHtml(record.priority)}</small>`:''}</td><td><span class="badge">${item.operation?escapeHtml({queued:'修改已排队',waiting:'等待重试',waiting_domain:'优选切换中',needs_attention:'需处理',failed:'执行失败'}[item.operation.status]??item.operation.status):'已载入'}</span><small>TTL ${Number(record.ttl)===1?'自动':escapeHtml(record.ttl)}</small>${item.operation?.last_error?`<small class="record-error">${escapeHtml(item.operation.last_error)}</small>`:''}</td><td><div class="actions"><button class="secondary small" data-raw-edit="${escapeHtml(item.id)}" ${pending?'disabled':''}>编辑</button><button class="text-button small danger-button" data-raw-delete="${escapeHtml(item.id)}" ${pending?'disabled':''}>删除</button><button class="text-button small" data-observed-detail="${escapeHtml(item.id)}">详情</button>${item.operation?`<button class="text-button small" data-dns-operation="${item.operation.id}">查看任务</button>`:''}</div></td></tr>`;
    }
    async function loadDomains() {
        const sequence=++domainLoad;
        const [all,zones]=await Promise.all([api('/api/v1/domains?per_page=1'),api('/api/v1/zones')]);
        if(sequence!==domainLoad)return; zoneItems=zones.items;
        $('#stat-total').textContent=all.total;$('#stat-active').textContent=all.stats.active??0;$('#stat-running').textContent=all.stats.running??0;$('#stat-attention').textContent=all.stats.attention??0;
        const zone=zoneItems.find(z=>Number(z.id)===selectedZoneId);
        $('#zone-overview').hidden=Boolean(zone);$('#zone-records').hidden=!zone;
        $('#new-domain').textContent=zone?'＋ 添加记录':'＋ 添加主域名';
        if(currentPage==='domains'){$('#page-title').textContent=zone?zone.name:'域名管理';$('#page-description').textContent=zone?'管理 DNS 记录、代理模式和优选目标。':'管理主域名及其 DNS 接入记录。';renderBreadcrumb('domains',zone);}
        renderZones(); if(!zone)return;
        $('#zone-title').textContent='DNS 记录';$('#zone-account').textContent=`${zone.account_name} · ${zone.status==='active'?'Zone 正常':'Zone 待同步'}`;
        const defaultEntry=config.entries.find(e=>Number(e.id)===Number(config.defaults.entry_id));
        $('#zone-defaults').textContent=`默认优选目标：${defaultEntry?.name??'尚未设置'}。源站与优选目标独立配置。`;
        const recordUrl=`/api/v1/zones/${zone.id}/records?`+new URLSearchParams({page:domainPage,per_page:50,search:recordSearch,include_existing:1});
        let data=await api(recordUrl),syncError='';
        const newerManaged=data.dns_snapshot.loaded&&data.items.some(item=>item.managed!==false&&item.status==='active'&&(!item.observed_dns_records.length||item.updated_at>data.dns_snapshot.synced_at));
        if((!data.dns_snapshot.loaded||newerManaged)&&Number(zone.account_active)&&zone.status==='active'&&Date.now()-(dnsSyncTimes.get(zone.id)??0)>30000){
            if(!pendingDnsSyncs.has(zone.id)){dnsSyncTimes.set(zone.id,Date.now());pendingDnsSyncs.set(zone.id,api(`/api/admin/zones/${zone.id}/dns-sync`,'POST',{}).finally(()=>pendingDnsSyncs.delete(zone.id)));}
            try{await pendingDnsSyncs.get(zone.id);data=await api(recordUrl);}catch(error){syncError=error.message;}
        }
        if(sequence!==domainLoad)return;domainTotal=data.total;
        $('#zone-dns-sync-state').textContent=data.dns_snapshot.loaded?`已载入 ${data.dns_snapshot.record_count} 条 Cloudflare DNS · ${data.dns_snapshot.synced_at} UTC · 同步只读取，保留原解析`:`DNS 尚未载入${syncError?'：'+syncError:'，请点击同步全部 DNS'}`;
        $('#domain-rows').innerHTML = data.items.map(item => item.managed===false?observedRecordRow(item,zone): `<tr><td><span class="badge">${escapeHtml(item.observed_dns_records?.length?[...new Set(item.observed_dns_records.map(record=>record.type))].join(' / '):item.record_type)}</span></td><td><strong>${escapeHtml(item.hostname===zone.name?'@':item.hostname.slice(0,-zone.name.length-1))}</strong><small>${escapeHtml(item.hostname)}</small></td><td><code>${escapeHtml(item.source)}</code><small>${escapeHtml((item.applied_config.origin.source_ip||item.applied_config.origin.source_hostname)?'独立源站':item.origin_name)}</small></td><td>${modeButtons(item)}</td><td>${(item.observed_dns_records??item.dns_records).map(r=>`<span class="dns-target">${escapeHtml(r.type)} → ${escapeHtml(r.content)}</span>`).join('')}<small>${escapeHtml(item.dns_mode==='preferred'?item.entry_name:(item.dns_mode==='proxied'?'Cloudflare 代理 · TTL 自动':'直连源站 · 仅 DNS'))}</small></td><td>${item.status==='active'&&item.dns_mode!=='preferred'?'<span class="badge active">DNS 已保存</span>':badge(item.status)}<small>${escapeHtml(labels[item.job_stage]??item.job_stage)} · TXT ${escapeHtml(labels[item.cleanup_status]??item.cleanup_status)}</small>${item.last_error?`<small class="record-error">${escapeHtml(item.last_error)}</small>`:''}</td><td><div class="actions"><button class="secondary small" data-edit-record="${item.id}" ${item.status!=='active'?'disabled title="接入完成后可编辑；未完成任务请在详情中处理"':''}>编辑</button><button class="text-button small" data-detail="${item.id}">详情</button></div></td></tr>`).join('');
        $('#empty-domains').hidden = data.items.length > 0;
        $('#domain-page').textContent = `第 ${domainPage} 页 · 共 ${data.total} 项记录`;
        $('#domain-prev').disabled = domainPage <= 1; $('#domain-next').disabled = domainPage * 50 >= domainTotal;
        visibleRecords=new Map(data.items.map(item=>[String(item.id),item]));observedRecords=new Map(data.items.filter(item=>item.managed===false).map(item=>[String(item.id),item]));
        $$('#domain-rows tr').forEach((row,index)=>{const item=data.items[index];row.insertAdjacentHTML('afterbegin',`<td class="record-select-cell"><input type="checkbox" data-record-select="${escapeHtml(item.id)}" aria-label="选择 ${escapeHtml(item.hostname)}" ${selectedRecords.has(String(item.id))?'checked':''}></td>`);});
        updateSelection();
    }
    function updateSelection(){
        const count=selectedRecords.size;$('#selection-count').textContent=`已选 ${count} / 50 条`;
        $('.bulk-toolbar').classList.toggle('has-selection',count>0);
        $$('[data-bulk-mode],#bulk-delete,#clear-selection').forEach(button=>button.disabled=count===0);
        const checkboxes=$$('[data-record-select]');checkboxes.forEach(input=>{input.checked=selectedRecords.has(input.dataset.recordSelect);input.closest('tr').classList.toggle('is-selected',input.checked);});
        const selected=checkboxes.filter(input=>input.checked).length;$('#select-page').checked=checkboxes.length>0&&selected===checkboxes.length;$('#select-page').indeterminate=selected>0&&selected<checkboxes.length;$('#select-page').disabled=checkboxes.length===0;
    }
    function clearSelection(){selectedRecords.clear();updateSelection();}
    function selectRecord(id,checked){
        if(!checked){selectedRecords.delete(id);return;}
        if(selectedRecords.has(id))return;
        if(selectedRecords.size>=50)throw new Error('每批最多选择 50 条记录');
        const item=visibleRecords.get(id);if(item)selectedRecords.set(id,recordReference(item));
    }
    function recordReference(item){return {id:item.managed===false?item.id:Number(item.id),hostname:item.hostname,...(item.managed===false?{expected_fingerprint:item.expected_fingerprint}:{expected_version:Number(item.config_version)})};}
    function requestReference(item){return {id:item.id,...(typeof item.id==='string'?{expected_fingerprint:item.expected_fingerprint,...(item.expected_rrset?{expected_rrset:item.expected_rrset}:{}),...(item.changes?{changes:item.changes}:{})}:{expected_version:item.expected_version})};}
    const bulkDeleteNames={delete_records:'删除当前接入解析及面板创建的 SaaS 资源',restore:'恢复原 DNS，保留主机名',delete_remote:'恢复原 DNS 并删除自有主机名',forget:'仅移除面板管理'};
    function setBulkBusy(value){
        bulkBusy=value;$$('#bulk-dialog button,#bulk-delete-mode').forEach(element=>element.disabled=value);
        if(!value){$('#bulk-confirm').disabled=!bulkContext?.ready?.length||bulkContext.submitted;$('#bulk-preview').disabled=Boolean(bulkContext?.submitted);$('#bulk-delete-mode').disabled=Boolean(bulkContext?.submitted);}
        for(const input of $$('#bulk-preferred-options input,#bulk-preferred-options select'))input.disabled=value||Boolean(bulkContext?.submitted);
        $('#raw-back-edit').disabled=value||Boolean(bulkContext?.submitted);
    }
    function renderBulk(items,submitted=false){
        const names={checking:'检查中',ready:'可执行',unchanged:'无需变更',queued:'任务已排队',removed:'已移除管理',failed:'未提交'};
        const original=new Map(bulkContext.records.map(r=>[r.id,r]));
        $('#bulk-result-rows').innerHTML=items.map(item=>`<tr><td><strong>${escapeHtml(item.hostname??original.get(item.id)?.hostname??'#'+item.id)}</strong>${item.references?.length?`<small class="record-error">关联纳管域名：${item.references.map(escapeHtml).join('、')}</small>`:''}</td><td>${item.expected_version?'v'+item.expected_version:'当前记录'}</td><td><span class="badge ${item.status==='failed'?'failed':(item.status==='removed'?'completed':item.status)}">${names[item.status]??item.status}</span></td><td>${item.operation_id?`<button class="text-button small" data-dns-operation="${item.operation_id}">查看任务 #${item.operation_id}</button>`:item.job_id?`<button class="text-button small" data-detail="${item.id}">查看任务 #${item.job_id}</button>`:escapeHtml(item.error?.message??item.message??'—')}${item.before?`<details><summary>核对修改前后</summary><pre>${escapeHtml(JSON.stringify({修改前:item.before,修改后:item.after??'删除所选记录'},null,2))}</pre></details>`:''}</td></tr>`).join('');
        const count=status=>items.filter(item=>item.status===status).length;
        $('#bulk-summary').textContent=submitted?`提交结果：${count('queued')} 条已排队，${count('removed')} 条已移除管理，${count('unchanged')} 条无变化，${count('failed')} 条失败。云端执行结果请查看任务详情。`:`检查结果：${count('ready')} 条可执行，${count('unchanged')} 条无需变更，${count('failed')} 条需处理。确认后只提交可执行记录。`;
    }
    async function previewBulk(){
        if(!bulkContext||bulkBusy)return;setBulkBusy(true);bulkContext.ready=[];bulkContext.key=null;
        const context=bulkContext;
        context.payload={action:context.action,records:context.records.map(requestReference),...(context.action==='set_mode'?{dns_mode:context.mode}:context.action==='delete'?{mode:context.hasRaw?'delete_records':$('#bulk-delete-mode').value}:{})};
        if(context.action==='set_mode'&&context.mode==='preferred'){
            if($('#bulk-entry').value)context.payload.entry_id=Number($('#bulk-entry').value);if($('#bulk-profile').value)context.payload.profile_id=Number($('#bulk-profile').value);
            const source=$('#bulk-source').value.trim();if(source)context.payload[source.includes(':')||/^[\d.]+$/.test(source)?'source_ip':'source_hostname']=source;context.payload.include_same_name=$('#bulk-same-name').checked;
        }
        $('#bulk-title').textContent=context.action==='update'?'确认 DNS 修改':context.action==='set_mode'?`${context.records.length===1?'':'批量'}${dnsModeNames[context.mode]}`:'删除所选记录';
        $('#bulk-description').textContent=`已选择 ${context.records.length} 条记录。确认前逐条检查版本和任务状态；失败记录会跳过。`;
        $('#bulk-mode-help').textContent=context.action==='update'?'请核对字段差异，确认后才执行修改。':context.action==='set_mode'?(context.mode==='preferred'?'原有记录按所选 SaaS 与优选目标转换，验证就绪后切换；未提交前保留原解析。':'原有记录仅调整代理状态；已纳管记录使用其当前源站配置。确认后执行。'):(context.hasRaw?'仅删除所选原记录 ID；已纳管记录按既有归属规则清理，无关 DNS 保留。':bulkDeleteNames[context.payload.mode]);
        $('#bulk-summary').textContent='正在检查选中的记录…';
        try{
            const result=await api(`/api/v1/zones/${context.zoneId}/records/bulk`,'POST',{...context.payload,preview:true});
            if(context!==bulkContext)return;context.previewItems=result.items;context.ready=result.items.filter(item=>item.status==='ready');context.key=crypto.randomUUID();renderBulk(result.items);
            $('#bulk-confirm').textContent=context.action==='update'?'确认提交修改':context.action==='delete'?`确认删除 ${context.ready.length} 条记录`:`确认切换 ${context.ready.length} 条记录`;
        }catch(error){$('#bulk-summary').textContent=`检查失败：${error.message}`;toast(error.message,true);}
        finally{setBulkBusy(false);}
    }
    async function openBulk(action,mode,records=null){
        records=records??[...selectedRecords.values()];if(!records.length)return;
        bulkContext={zoneId:selectedZoneId,action,mode,records,hasRaw:records.some(row=>typeof row.id==='string'),ready:[],submitted:false};$('#bulk-delete-mode').value='delete_records';$('#bulk-delete-options').hidden=action!=='delete'||bulkContext.hasRaw;
        $('#bulk-preferred-options').hidden=action!=='set_mode'||mode!=='preferred';$('#raw-back-edit').hidden=action!=='update';
        options($('#bulk-entry'),config.entries.filter(row=>Number(row.active)));options($('#bulk-profile'),config.profiles.filter(row=>Number(row.active)));$('#bulk-entry').value=config.defaults.entry_id??'';$('#bulk-profile').value=config.defaults.profile_id??'';$('#bulk-source').value='';$('#bulk-same-name').checked=false;
        $('#bulk-result-rows').replaceChildren();$('#bulk-dialog').showModal();await previewBulk();
    }
    function rawEditFields(){const form=$('#raw-dns-form'),type=$('[name="type"]',form).value.trim().toUpperCase();$('#raw-priority-label').hidden=!['MX','URI'].includes(type);$('#raw-proxy-label').hidden=!['A','AAAA','CNAME'].includes(type);if($('#raw-proxy-label').hidden)$('[name="proxied"]',form).checked=false;}
    function openRawEditor(id){const item=observedRecords.get(id);if(!item)return;const form=$('#raw-dns-form'),r=item.record;form.reset();rawEditor={item,initial:{}};$('#raw-dns-title').textContent='编辑 '+item.hostname;
        const values={type:r.type,name:r.name,content:r.content,ttl:r.ttl,priority:r.priority??0,comment:r.comment??'',data:JSON.stringify(r.data??{},null,2),tags:JSON.stringify(r.tags??[],null,2),settings:JSON.stringify(r.settings??{},null,2)};
        for(const [key,value]of Object.entries(values)){$(`[name="${key}"]`,form).value=value;rawEditor.initial[key]=String(value);}$('[name="proxied"]',form).checked=Boolean(r.proxied);rawEditor.initial.proxied=Boolean(r.proxied);rawEditFields();$('#raw-dns-dialog').showModal();}
    $('#raw-dns-form').addEventListener('input',rawEditFields);
    $('#raw-dns-form').addEventListener('submit',event=>{event.preventDefault();busy($('button.primary',event.currentTarget),async()=>{const changes={},form=event.currentTarget;
        for(const name of ['name','type','content','ttl','priority','comment','data','tags','settings']){if(name==='priority'&&$('#raw-priority-label').hidden)continue;let value=$(`[name="${name}"]`,form).value;if(value===rawEditor.initial[name])continue;if(['ttl','priority'].includes(name))value=Number(value);if(['data','tags','settings'].includes(name))value=JSON.parse(value);if(name==='type')value=value.trim().toUpperCase();changes[name]=value;}
        const proxied=$('[name="proxied"]',form).checked;if(proxied!==rawEditor.initial.proxied)changes.proxied=proxied;
        const ref={...recordReference(rawEditor.item),changes};$('#raw-dns-dialog').close();await openBulk('update',null,[ref]);});});
    $('#raw-back-edit').addEventListener('click',()=>{$('#bulk-dialog').close();$('#raw-dns-dialog').showModal();});
    for(const input of $$('#bulk-preferred-options input,#bulk-preferred-options select'))input.addEventListener('input',()=>{if(bulkBusy||!bulkContext)return;bulkContext.ready=[];bulkContext.key=null;$('#bulk-confirm').disabled=true;$('#bulk-summary').textContent='设置已改变，请重新检查后确认提交。';});
    function renderZones(){
        const search=$('#zone-search').value.trim().toLowerCase();const items=zoneItems.filter(z=>z.name.includes(search)||z.account_name.toLowerCase().includes(search));
        $('#zone-list').innerHTML=items.map(z=>`<button class="zone-card" data-zone="${z.id}"><span class="zone-icon">◎</span><span><strong>${escapeHtml(z.name)}</strong><small>${escapeHtml(z.account_name)}</small></span><span class="zone-count">${z.record_count} 条记录<small>${z.status==='active'&&Number(z.account_active)?'可管理':'待同步 / 账号停用'}</small></span><span class="zone-arrow">→</span></button>`).join('')||(zoneItems.length?'<p class="muted">没有匹配的主域名。</p>':'');
        $('#empty-zones').hidden=zoneItems.length>0;
    }
    function recordOrigins(){
        const origins=config.origins.filter(r=>Number(r.active)&&String(r.profile_id)===$('#record-profile').value);
        $('#record-origin-suggestions').innerHTML=origins.map(origin=>`<option value="${escapeHtml(origin.hostname)}"></option>`).join('');
        options($('#record-origin'),origins.map(origin=>({...origin,name:origin.hostname+(origin.mode==='fallback'?'（CF 默认回源）':'')})));
        const preferred=config.origins.find(origin=>Number(origin.id)===Number(config.defaults.origin_id)&&String(origin.profile_id)===$('#record-profile').value&&Number(origin.active));
        if(!$('#record-origin').value&&preferred)$('#record-origin').value=preferred.id;
    }
    function recordPreview(){
        const form=$('#record-form'),type=$('#record-type').value,ip=type==='auto',cname=type==='CNAME';
        const mode=$('[name="dns_mode"]',form).value;
        $$('[data-dns-mode]',form).forEach(button=>{button.classList.toggle('selected',button.dataset.dnsMode===mode);button.setAttribute('aria-pressed',String(button.dataset.dnsMode===mode));});
        $('#record-source-label').hidden=!ip;$('#record-hostname-label').hidden=!cname;$('#record-preset-label').hidden=type!=='preset';
        $('[name="source_ip"]',form).disabled=!ip;$('[name="source_ip"]',form).required=ip;
        $('[name="source_hostname"]',form).disabled=!cname;$('[name="source_hostname"]',form).required=cname;
        $('#record-origin').disabled=type!=='preset';$('#record-origin').required=type==='preset';
        $('#record-entry-label').hidden=mode!=='preferred';
        $('[value="preset"]',$('#record-type')).disabled=mode!=='preferred';
        const entry=config.entries.find(e=>String(e.id)===$('#record-entry').value),profile=config.profiles.find(p=>String(p.id)===$('#record-profile').value);
        const destinations=entry?.kind==='standard'?profile?.cname_target:(entry?.targets??[]).join(' / ');
        const targets=entry?.hostname?`${entry.hostname} → ${destinations}`:destinations;
        const origin=config.origins.find(item=>String(item.id)===$('#record-origin').value);
        const source=ip?($('[name="source_ip"]',form).value||'回源 IP'):(cname?($('[name="source_hostname"]',form).value||'回源 CNAME 域名'):origin?.hostname);
        $('#record-preview').textContent=mode==='preferred'?`业务 CNAME → ${targets||'请选择优选目标'}；网站回源 → ${source||'请选择回源地址'}。`:`${mode==='proxied'?'开云':'关云'}：${cname?'CNAME':'A / AAAA'} 指向 ${source}。`;
        $('button.primary',form).textContent=$('[name="domain_id"]',form).value?'保存修改':(mode==='preferred'?'保存并自动接入':'保存 DNS 记录');
    }
    async function openRecord(id){
        const zone=zoneItems.find(z=>Number(z.id)===selectedZoneId);if(!zone)return;
        if(!id&&!config.profiles.some(profile=>Number(profile.active))){page('profiles');toast('首次接入：选择已开通 SaaS 的主域名，从 CF 读取并保存一次接入配置。');return;}
        const form=$('#record-form');form.reset();const detail=id?await api(`/api/v1/domains/${id}`):null;
        $('[name="dns_mode"]',form).value=detail?.applied_config.dns_mode??'preferred';
        $('#record-zone-label').textContent=zone.name;$('#record-form-title').textContent=detail?'编辑 DNS 记录':'添加 DNS 记录';
        $('[name="domain_id"]',form).value=detail?.id??'';$('[name="expected_version"]',form).value=detail?.config_version??'';
        options($('#record-profile'),config.profiles.filter(r=>Number(r.active)));options($('#record-entry'),config.entries.filter(r=>Number(r.active)).map(entry=>({...entry,name:entry.name+(entry.kind==='ip'?` · ${entry.targets.length} 个 IP`:'')})));
        if(detail||config.defaults.profile_id)$('#record-profile').value=detail?.profile_id??config.defaults.profile_id;
        if(detail||config.defaults.entry_id)$('#record-entry').value=detail?.entry_id??config.defaults.entry_id;
        $('#record-profile').disabled=Boolean(detail);recordOrigins();
        if(!detail&&config.defaults.origin_id)$('#record-origin').value=config.defaults.origin_id;
        const source=detail?.applied_config.origin;
        $('[value="preset"]',$('#record-type')).hidden=!source||Boolean(source.source_ip||source.source_hostname);
        if(source){$('#record-type').value=source.source_ip?'auto':(source.source_hostname?'CNAME':'preset');$('[name="source_ip"]',form).value=source.source_ip??'';$('[name="source_hostname"]',form).value=source.source_hostname??'';if(!source.source_ip&&!source.source_hostname)$('#record-origin').value=detail.origin_id;}
        else{$('#record-type').value='auto';}
        $('[name="name"]',form).value=detail?(detail.hostname===zone.name?'@':detail.hostname.slice(0,-zone.name.length-1)):'';
        $('[name="name"]',form).disabled=Boolean(detail);$('#record-name-hint').textContent=`主域名：${zone.name}`;
        fillCheck(form,detail?.applied_config.check??config.defaults.check);
        $('#record-advanced').open=config.profiles.filter(p=>Number(p.active)).length>1&&!config.defaults.profile_id;
        $('button.primary',form).textContent=detail?'保存修改':'保存并自动接入';recordPreview();$('#record-dialog').showModal();
    }
    async function loadLogs() {
        const data = await api('/api/v1/logs?'+new URLSearchParams({per_page:100,page:logPage,...logFilters}));logTotal=data.total;
        $('#logs-rows').innerHTML = data.items.map(item => `<tr><td>${escapeHtml(item.created_at)}</td><td>${escapeHtml(item.actor)}</td><td><code>${escapeHtml(item.event)}</code></td><td>${escapeHtml(item.message)}</td><td>${escapeHtml(item.domain_id ?? '—')} / ${escapeHtml(item.job_id ?? '—')}</td></tr>`).join('') || '<tr><td colspan="5" class="muted">还没有操作记录。</td></tr>';
        $('#logs-page').textContent=`第 ${logPage} 页 · 共 ${data.total} 条`;$('#logs-prev').disabled=logPage<=1;$('#logs-next').disabled=logPage*100>=data.total;
    }
    async function loadTokens() {
        const data = await api('/api/admin/tokens');
        $('#tokens-list').innerHTML = data.items.map(item => `<div class="list-item"><strong>${escapeHtml(item.name)}</strong><p>${escapeHtml(JSON.parse(item.scopes).join(' · '))}<br>有效期：${escapeHtml(item.expires_at ?? '未设定')} · 最近使用：${escapeHtml(item.last_used_at ?? '尚未使用')}</p>${item.revoked_at ? '<span class="badge">已撤销</span>' : `<button class="secondary small" data-revoke="${item.id}">撤销 Key</button>`}</div>`).join('') || empty;
    }
    function page(name, preserveZone = false) {
        if (!titles[name]) return;
        if(name==='domains'&&!preserveZone){
            domainLoad++;clearSelection();visibleRecords.clear();selectedZoneId=0;domainPage=1;recordSearch='';
            $('#record-search-form').reset();$('#zone-search').value='';$('#zone-overview').hidden=false;$('#zone-records').hidden=true;$('#new-domain').textContent='＋ 添加主域名';
            if(location.hash)history.pushState(null,'',location.pathname+location.search);
        }
        if(name!=='domains')clearSelection();
        if(name!=='inventory')inventoryPause=true;
        currentPage = name;
        $$('.page-section').forEach(element => element.hidden = element.id !== `page-${name}`);
        $$('[data-page]').forEach(button => button.classList.toggle('selected', button.dataset.page === name||(['profiles','origins'].includes(name)&&button.dataset.page==='accounts')));
        $('#page-title').textContent = titles[name][0]; renderBreadcrumb(name); $('#page-description').textContent = titles[name][1];
        $('#new-domain').hidden = name !== 'domains';
        busy(null, async () => { if (name === 'domains') await loadDomains(); if (name === 'logs') await loadLogs(); if (name === 'tokens') await loadTokens(); if(name==='inventory')await loadInventory(); });
    }
    $('#profile-account').addEventListener('change',()=>{linkedOptions();readSaas();}); $('#domain-profile').addEventListener('change', linkedOptions);
    $('#profile-zone').addEventListener('change',readSaas);
    $('#profile-discover').addEventListener('click',readSaas);
    $('#profile-candidates').addEventListener('change',()=>{$('[name="cname_target"]',$('form[data-resource="profiles"]')).value=$('#profile-candidates').value;});
    $('[name="cname_target"]',$('form[data-resource="profiles"]')).addEventListener('input',()=>{$('#profile-candidates').required=false;});
    $('#log-filter-form').addEventListener('submit',event=>{event.preventDefault();logFilters=fields(event.currentTarget);for(const key of ['since','until'])if(logFilters[key])logFilters[key]=new Date(logFilters[key]).toISOString();logPage=1;busy(null,loadLogs);});
    $('#logs-prev').addEventListener('click',()=>{if(logPage>1){logPage--;busy(null,loadLogs);}});$('#logs-next').addEventListener('click',()=>{if(logPage*100<logTotal){logPage++;busy(null,loadLogs);}});
    $('#domain-prev').addEventListener('click', () => { if (domainPage > 1) { domainPage--; busy(null, loadDomains); } });
    $('#domain-next').addEventListener('click', () => { if (domainPage * 50 < domainTotal) { domainPage++; busy(null, loadDomains); } });
    $('#new-domain').addEventListener('click', () => {
        if(selectedZoneId){busy(null,()=>openRecord());return;}page('accounts');
    });
    $('#bulk-domain').addEventListener('click', () => {
        const form=$('#domain-form');
        for(const field of ['profile_id','dns_account_id','entry_id']) if(config.defaults[field]) $(`[name="${field}"]`,form).value=config.defaults[field];
        linkedOptions(); if(config.defaults.origin_id) $('[name="origin_id"]',form).value=config.defaults.origin_id;
        const zone=zoneItems.find(z=>Number(z.id)===selectedZoneId);if(zone)$('[name="dns_account_id"]',form).value=zone.account_id;
        fillCheck(form,config.defaults.check);
        $('#domain-dialog').showModal();
    });
    $('#back-zones').addEventListener('click',()=>page('domains'));
    $('#sync-zone-dns').addEventListener('click',event=>busy(event.currentTarget,async()=>{const result=await api(`/api/admin/zones/${selectedZoneId}/dns-sync`,'POST',{});await loadDomains();toast(result.message+`（${result.count} 条）`);}));
    $$('#breadcrumb-home, #breadcrumb-domains').forEach(link => link.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        page('domains');
    }));
    window.addEventListener('hashchange',()=>{clearSelection();visibleRecords.clear();selectedZoneId=Number(location.hash.match(/^#zones\/(\d+)$/)?.[1]??0);domainPage=1;recordSearch='';$('#record-search-form').reset();page('domains',true);});
    $('#zone-search').addEventListener('input',renderZones);
    $('#record-search-form').addEventListener('submit',event=>{event.preventDefault();clearSelection();recordSearch=$('[name="search"]',event.currentTarget).value;domainPage=1;busy(null,loadDomains);});
    $('#clear-selection').addEventListener('click',clearSelection);
    $('#select-page').addEventListener('change',event=>{try{for(const id of visibleRecords.keys())selectRecord(id,event.currentTarget.checked);}catch(error){toast(error.message,true);}updateSelection();});
    document.addEventListener('change',event=>{const input=event.target;if(!input.matches('[data-record-select]'))return;try{selectRecord(input.dataset.recordSelect,input.checked);}catch(error){toast(error.message,true);}updateSelection();});
    $$('[data-bulk-mode]').forEach(button=>button.addEventListener('click',()=>busy(null,()=>openBulk('set_mode',button.dataset.bulkMode))));
    $('#bulk-delete').addEventListener('click',()=>busy(null,()=>openBulk('delete')));
    $('#bulk-delete-mode').addEventListener('change',previewBulk);$('#bulk-preview').addEventListener('click',previewBulk);
    $('#bulk-dialog').addEventListener('cancel',event=>{if(bulkBusy)event.preventDefault();});
    $('#bulk-confirm').addEventListener('click',async()=>{
        const context=bulkContext;if(bulkBusy||!context?.ready.length||context.submitted)return;setBulkBusy(true);
        try{
            const originals=new Map(context.records.map(row=>[row.id,row]));const payload={...context.payload,records:context.ready.map(row=>requestReference({...originals.get(row.id),...row})),preview:false};
            const result=await api(`/api/v1/zones/${context.zoneId}/records/bulk`,'POST',payload,{'Idempotency-Key':context.key});
            const submitted=new Map(result.items.map(item=>[item.id,item]));const items=context.previewItems.map(item=>submitted.get(item.id)??item);context.submitted=true;renderBulk(items,true);
            for(const item of items)if(item.status!=='failed')selectedRecords.delete(String(item.id));
            $('#bulk-title').textContent='批量操作提交结果';$('#bulk-description').textContent=`批次 ${result.batch_id}。排队表示已提交后台任务，实际执行结果可在记录详情和日志中查看。`;
            toast(`批量操作已提交：${result.summary.queued} 个任务，${items.filter(item=>item.status==='failed').length} 条待处理`);await loadDomains();
        }catch(error){toast(context.submitted?'操作已提交，但刷新列表失败：'+error.message:error.message+'；可重试提交，同一批次不会重复创建任务',true);}
        finally{setBulkBusy(false);}
    });
    $('#record-profile').addEventListener('change',()=>{recordOrigins();recordPreview();});
    $('#record-type').addEventListener('change',recordPreview);$('#record-entry').addEventListener('change',recordPreview);
    $('#record-origin').addEventListener('change',recordPreview);
    $('[name="source_ip"]',$('#record-form')).addEventListener('input',recordPreview);
    $('[name="source_hostname"]',$('#record-form')).addEventListener('input',recordPreview);
    $('#record-form').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;busy($('button.primary',form),async()=>{const data=fields(form),id=data.domain_id;delete data.domain_id;
        if(data.type==='auto')data.type=data.source_ip.includes(':')?'AAAA':'A';
        if(data.type==='preset'){delete data.source_ip;delete data.source_hostname;}
        if(data.source_ip&&(data.type==='AAAA')!==data.source_ip.includes(':'))throw new Error('回源类型与源站 IP 不匹配');
        let changed=true;
        if(id){delete data.name;delete data.profile_id;const result=await api(`/api/v1/domains/${id}`,'PATCH',data,{'Idempotency-Key':crypto.randomUUID()});changed=result.changed;}
        else{delete data.expected_version;await api(`/api/v1/zones/${selectedZoneId}/records`,'POST',data,{'Idempotency-Key':crypto.randomUUID()});}
        $('#record-dialog').close();toast(id?(changed?'记录修改已提交，后台将检查并切换回源':'配置没有变化'):'记录已保存，后台将自动完成接入');await loadConfig();await loadDomains();});});
    async function exportFile(zone){const doc=await api('/api/admin/export'+(zone?`?zone_id=${zone}`:''));const url=URL.createObjectURL(new Blob([JSON.stringify(doc,null,2)],{type:'application/json'}));const link=document.createElement('a');link.href=url;link.download=`cf-saas-panel-${zone?'zone-'+zone:'all'}-${new Date().toISOString().slice(0,10)}.json`;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);toast('导出完成，文件不含 Token、密码与 API Key');}
    $('#export-zone').addEventListener('click',e=>busy(e.currentTarget,()=>exportFile(selectedZoneId)));
    function invalidateImport(){importRevision++;importRequest=null;$('#commit-import').disabled=true;$('#import-preview').hidden=true;}
    $('#import-form').addEventListener('change',invalidateImport);
    $('#import-file').addEventListener('change',event=>busy(null,async()=>{invalidateImport();importDocument=null;$('#import-bindings').replaceChildren();const file=event.currentTarget.files[0];if(!file)return;if(file.size>950000)throw new Error('导入文件不超过 950 KB，请按主域名分批导出');const doc=JSON.parse(await file.text());if(doc.format!=='cf-saas-panel'||![1,2,3,4,5].includes(doc.format_version)||!Array.isArray(doc.accounts))throw new Error('请选择面板导出的格式版本 1 / 2 / 3 / 4 / 5 JSON 文件');importDocument=doc;
        $('#import-defaults').disabled=!doc.defaults;$('#import-defaults').checked=false;
        $('#import-bindings').innerHTML=doc.accounts.map((a,index)=>`<label>导入账号 ${escapeHtml(a.name)} → 本地账号<select data-import-account="${escapeHtml(a.ref)}" id="import-account-${index}" required><option value="">请选择绑定账号</option>${config.accounts.filter(r=>Number(r.active)).map(r=>`<option value="${r.id}">${escapeHtml(r.name)}</option>`).join('')}</select></label>`).join('');
        doc.accounts.forEach((a,index)=>{const matched=config.accounts.filter(r=>r.name===a.name&&Number(r.active));if(matched.length===1)$(`#import-account-${index}`).value=matched[0].id;});
    }));
    $('#import-form').addEventListener('submit',event=>{event.preventDefault();busy($('#preview-import'),async()=>{invalidateImport();const revision=importRevision;if(!importDocument)throw new Error('请先选择导入文件');const bindings=Object.fromEntries($$('[data-import-account]').map(s=>[s.dataset.importAccount,Number(s.value)]));const request={document:importDocument,account_bindings:bindings,apply_defaults:$('#import-defaults').checked};const result=await api('/api/admin/import','POST',{...request,preview:true});if(revision!==importRevision)return;importRequest=request;$('#import-preview').textContent=`检查通过\n新增 CF SaaS服务设置 ${result.created.profiles} · 入口 ${result.created.entries} · 回源 ${result.created.origins}\n新建接入任务 ${result.new_tasks} · 复用已有记录 ${result.reused}\n默认配置：${request.apply_defaults?'导入':'保留本地'}\n\n${result.records.slice(0,30).map(r=>`${r.hostname} · ${r.reused?'复用':'待接入'}`).join('\n')}${result.records.length>30?'\n…':''}\n\n确认导入后，后台将自动执行新建的接入任务。`;$('#import-preview').hidden=false;$('#commit-import').disabled=false;});});
    $('#commit-import').addEventListener('click',event=>busy(event.currentTarget,async()=>{if(!importRequest)throw new Error('请先检查并预览');const result=await api('/api/admin/import','POST',{...importRequest,preview:false},{'Idempotency-Key':crypto.randomUUID()});invalidateImport();$('#import-dialog').close();toast(`导入完成，新建 ${result.new_tasks} 个接入任务`);await loadConfig();await loadDomains();}));
    $('#refresh').addEventListener('click', event => busy(event.currentTarget, async () => { await loadConfig(); await loadDomains(); }));
    $('#logout').addEventListener('click', () => busy(null, async () => { await api('/logout', 'POST', {}); location.assign('/login'); }));
    $$('form[data-resource]').forEach(form => form.addEventListener('submit', event => {
        event.preventDefault(); busy($('button.primary', form), async () => {
            const resource = form.dataset.resource; const data=fields(form);
            const editing=form.dataset.editId||(resource==='profiles'?config.profiles.find(profile=>String(profile.account_id)===String(data.account_id)&&profile.zone_id===data.zone_id)?.id:null);
            if(resource==='profiles'){
                if(!data.name||!data.cname_target||!data.fallback_origin)$('#profile-manual').open=true;
                if(!form.reportValidity())return;
            }
            const makeDefault=resource==='entries'&&$('[name="make_default"]',form).checked;delete data.make_default;
            if(resource==='entries'){
                const first=data.targets.trim().split(/[\s,]+/)[0]??'';
                if(data.kind==='auto')data.kind=first.includes(':')||/^\d{1,3}(\.\d{1,3}){3}$/.test(first)?'ip':'cname';
                if(data.kind!=='standard'&&!first)throw new Error('请填写一个优选域名，或至少一个优选 IP。');
                const count=data.targets.trim().split(/[\s,]+/).filter(Boolean).length;
                if(!data.name.trim())data.name=($('#entry-use-alias').checked?entryHostname():(data.kind==='standard'?'CF标准接入':(data.kind==='ip'&&count>1?`IP组（${count} 个） · ${first}`:first))).slice(0,100);
            }
            const result = await api(`/api/admin/${resource}${editing?'/'+editing:''}`,editing?'PATCH':'POST',data);
            resetEditor(form); await loadConfig();
            if (resource === 'accounts'&&Number(config.accounts.find(row=>Number(row.id)===Number(result.id))?.active)) {
                await api(`/api/admin/accounts/${result.id}/sync`, 'POST', {}); await loadConfig();
                await startInventory();
            }
            if(resource==='profiles'&&!config.defaults.profile_id){
                const fallback=config.origins.find(origin=>Number(origin.profile_id)===Number(result.id)&&origin.mode==='fallback');
                const defaults={profile_id:result.id,origin_id:fallback?.id??null};
                if(!config.defaults.dns_account_id)defaults.dns_account_id=Number(data.account_id);
                await api('/api/admin/defaults','PATCH',defaults);await loadConfig();
            }
            if(makeDefault){await api('/api/admin/defaults','PATCH',{entry_id:result.id});await loadConfig();}
            toast('配置已保存');
        });
    }));
    $$('form[data-resource]').forEach(form=>form.insertAdjacentHTML('beforeend',' <button type="button" class="secondary" data-cancel-edit hidden>取消编辑</button>'));
    $('#default-profile').addEventListener('change',()=>options($('#default-origin'),config.origins.filter(r=>Number(r.active)&&String(r.profile_id)===$('#default-profile').value)));
    $('#defaults-form').addEventListener('submit',event=>{event.preventDefault();busy($('button.primary',event.currentTarget),async()=>{await api('/api/admin/defaults','PATCH',fields(event.currentTarget));await loadConfig();toast('默认配置已保存，仅用于新任务');});});
    $('#change-form').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;busy($('button.primary',form),async()=>{const data=fields(form);const id=data.domain_id;delete data.domain_id;await api(`/api/v1/domains/${id}`,'PATCH',data,{'Idempotency-Key':crypto.randomUUID()});$('#change-dialog').close();toast('切换任务已提交');await loadDomains();});});
    $('#remove-form').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;busy($('button.primary',form),async()=>{if(!window.confirm('按所选方式移除域名？请确认是否包含恢复 DNS 和删除云端主机名。'))return;const data=fields(form);const id=data.domain_id;delete data.domain_id;await api(`/api/v1/domains/${id}`,'DELETE',data,{'Idempotency-Key':crypto.randomUUID()});$('#remove-dialog').close();toast('移除操作已受理');await loadDomains();});});
    $('#domain-form .dialog-actions').insertAdjacentHTML('beforebegin','<details><summary>访问检查选项</summary><div class="form-grid"><label>检查路径<input name="check_path" required value="/"></label><label>预期状态码<input name="check_statuses" required value="200,201,202,204,301,302,303,307,308"></label></div><label>响应关键字（可选）<input name="check_keyword" maxlength="512"></label></details>');
    function showChangeTarget() {
        const entry=config.entries.find(r=>String(r.id)===$('#change-entry').value);const origin=config.origins.find(r=>String(r.id)===$('#change-origin').value)??(String(changeSourceOrigin?.id)===$('#change-origin').value?changeSourceOrigin:null);
        $('#change-target').textContent=`目标入口：${entry?.kind==='standard'?'SaaS 标准目标':(entry?.targets??[]).join(' / ')}；目标回源：${origin?.source_ip??origin?.source_hostname??origin?.hostname??'请选择'}`;
    }
    $('#change-entry').addEventListener('change',showChangeTarget);$('#change-origin').addEventListener('change',showChangeTarget);
    $('#domain-form').addEventListener('submit', event => {
        event.preventDefault(); const form = event.currentTarget;
        busy($('button.primary', form), async () => {
            const result = await api('/api/v1/domains', 'POST', fields(form), {'Idempotency-Key': crypto.randomUUID()});
            const failures = result.items.filter(item => item.error);
            if (failures.length) toast(failures.map(i => `${i.hostname}：${i.error}`).join('\n'), true);
            else { toast('接入任务已提交，后台将自动处理'); $('#domain-dialog').close(); form.reset(); }
            await loadDomains();
        });
    });
    $('[data-token-form]').addEventListener('submit', event => {
        event.preventDefault(); const form = event.currentTarget;
        busy($('button.primary', form), async () => {
            const input = fields(form); input.scopes = new FormData(form).getAll('scopes[]');
            if(input.expires_at) input.expires_at=new Date(input.expires_at).toISOString();
            const result = await api('/api/admin/tokens', 'POST', input); $('#new-key').textContent = result.key; $('#key-dialog').showModal(); form.reset(); await loadTokens();
        });
    });
    $('#key-dialog').addEventListener('close', () => { $('#new-key').textContent = ''; });
    $('#copy-key').addEventListener('click', () => busy(null, async () => { await navigator.clipboard.writeText($('#new-key').textContent); toast('Key 已复制'); }));
    $$('form[data-endpoint]').forEach(form => form.addEventListener('submit', event => { event.preventDefault(); busy($('button', form), async () => { await api(form.dataset.endpoint, 'POST', fields(form)); form.reset(); toast('已保存'); }); }));
    document.addEventListener('click', event => {
        const button = event.target.closest('button'); if (!button) return;
        if (button.dataset.page) page(button.dataset.page);
        if (button.dataset.goto) page(button.dataset.goto);
        if (button.dataset.close) $(`#${button.dataset.close}`).close();
        if(button.dataset.zone){location.hash=`zones/${button.dataset.zone}`;}
        if(button.dataset.editRecord)busy(button,()=>openRecord(button.dataset.editRecord));
        if(button.dataset.rawEdit)openRawEditor(button.dataset.rawEdit);
        if(button.dataset.rawDelete){const item=observedRecords.get(button.dataset.rawDelete);if(item)busy(button,()=>openBulk('delete',null,[recordReference(item)]));}
        if(button.dataset.rawMode){const item=observedRecords.get(button.dataset.rawId);if(item)busy(button,()=>openBulk('set_mode',button.dataset.rawMode,[recordReference(item)]));}
        if(button.dataset.dnsOperation)busy(button,async()=>{if($('#bulk-dialog').open)$('#bulk-dialog').close();const op=await api(`/api/v1/dns-operations/${button.dataset.dnsOperation}`);$('#detail-title').textContent='DNS 修改任务 #'+op.id;$('#detail-content').innerHTML=`<p>${escapeHtml(op.hostname)} · ${escapeHtml({queued:'已排队',waiting:'等待重试',waiting_domain:'优选处理中',completed:'已完成',needs_attention:'需处理',failed:'执行失败'}[op.status]??op.status)}</p>${op.last_error?`<p class="record-error">${escapeHtml(op.last_error)}</p>`:''}<div class="actions">${['failed','needs_attention'].includes(op.status)?`<button class="secondary" data-dns-retry="${op.id}">重试任务</button>`:''}${op.domain_id?`<button class="secondary" data-detail="${op.domain_id}">查看纳管域名</button>`:''}</div>`;const pre=document.createElement('pre');pre.textContent=JSON.stringify({修改前:op.before,修改后:op.after??'删除所选记录'},null,2);$('#detail-content').append(pre);$('#detail-dialog').showModal();});
        if(button.dataset.dnsRetry)busy(button,async()=>{await api(`/api/v1/dns-operations/${button.dataset.dnsRetry}/retry`,'POST',{});$('#detail-dialog').close();toast('重试已提交，执行前仍会核对原记录');await loadDomains();});
        if(button.dataset.observedDetail){const item=observedRecords.get(button.dataset.observedDetail);if(item){$('#detail-title').textContent=item.hostname;$('#detail-content').replaceChildren();const hint=document.createElement('p');hint.className='hint';hint.textContent='从 Cloudflare 只读载入的原有 DNS 记录，解析与归属保持不变。已有 SaaS 可在「已有 SaaS」按当前配置纳管。';const pre=document.createElement('pre');pre.textContent=JSON.stringify(item.record,null,2);$('#detail-content').append(hint,pre);$('#detail-dialog').showModal();}}
        if(button.dataset.dnsMode){$('[name="dns_mode"]',$('#record-form')).value=button.dataset.dnsMode;if(button.dataset.dnsMode!=='preferred'&&$('#record-type').value==='preset')$('#record-type').value='auto';recordPreview();}
        if(button.dataset.recordMode){const item=visibleRecords.get(button.dataset.recordId);if(item)busy(button,()=>openBulk('set_mode',button.dataset.recordMode,[recordReference(item)]));}
        if(button.hasAttribute('data-export'))busy(button,()=>exportFile());
        if(button.hasAttribute('data-open-import')){$('#import-form').reset();importDocument=null;$('#import-bindings').replaceChildren();invalidateImport();$('#import-dialog').showModal();}
        if(button.hasAttribute('data-cancel-edit')) resetEditor(button.closest('form'));
        if(button.dataset.configEdit) {
            const resource=button.dataset.configEdit;const row=config[resource].find(r=>String(r.id)===button.dataset.configId);const form=$(`form[data-resource="${resource}"]`);page(resource);resetEditor(form);form.dataset.editId=row.id;$('h2',form).textContent='编辑 '+row.name;$('button.primary',form).textContent='保存修改';$('[data-cancel-edit]',form).hidden=false;
            if(resource==='profiles'){discoveryRevision++;$('#profile-discover').disabled=false;$('#profile-candidate-label').hidden=true;$('#profile-candidates').required=false;$('#profile-manual').open=true;options($('[name="account_id"]',form),config.accounts);$('[name="account_id"]',form).value=row.account_id;linkedOptions();}
            if(resource==='entries')$('[name="make_default"]',form).checked=Number(config.defaults.entry_id)===Number(row.id);
            if(resource==='origins') options($('[name="profile_id"]',form),config.profiles);
            for(const [key,value] of Object.entries(row)){const input=$(`[name="${key}"]`,form);if(input) input.value=Array.isArray(value)?value.join('\n'):(value??'');}
            if(resource==='profiles'&&!row.cname_target)$('[name="cname_target"]',form).required=false;
            if(resource==='entries'){
                $('#entry-legacy-option').hidden=Boolean(row.hostname);$('#entry-use-alias').checked=Boolean(row.hostname);
                if(row.hostname){const zone=config.zones.find(item=>Number(item.id)===Number(row.zone_ref));$('#entry-zone').value=row.zone_ref;$('#entry-record').value=zone?row.hostname.slice(0,-zone.name.length-1):row.hostname;}
                $('#entry-shared-help').hidden=!(row.hostname&&(Number(row.references)||row.route_state));entryPreview();
            }
            if(resource==='accounts'){
                form.dataset.previousAuth=row.auth_type;accountAuthFields();
                if(row.auth_type==='global_key')busy(null,async()=>{const metadata=await api(`/api/admin/accounts/${row.id}/auth`);const email=$('[name="email"]',form);if(String(form.dataset.editId)===String(row.id)&&$('#account-auth-type').value==='global_key'&&!email.value)email.value=metadata.email??'';});
            }
            form.scrollIntoView({behavior:'smooth',block:'start'});
        }
        if(button.dataset.configToggle) busy(button,async()=>{const resource=button.dataset.configToggle;const row=config[resource].find(r=>String(r.id)===button.dataset.configId);await api(`/api/admin/${resource}/${row.id}`,'PATCH',{active:!Number(row.active)});await loadConfig();toast('状态已更新；停用会暂停相关待执行任务');});
        if(button.dataset.configDelete) busy(button,async()=>{const resource=button.dataset.configDelete;const row=config[resource].find(r=>String(r.id)===button.dataset.configId);if(!window.confirm(`删除配置“${row.name}”？云端资源保持不变，历史记录保留。`))return;await api(`/api/admin/${resource}/${row.id}`,'DELETE',{});await loadConfig();toast('配置已删除');});
        if (button.dataset.sync) busy(button, async () => { const result = await api(`/api/admin/accounts/${button.dataset.sync}/sync`, 'POST', {}); await loadConfig(); toast(result.message); await startInventory(); });
        if (button.dataset.profileCheck || button.dataset.profileSetup) busy(button, async () => { const id = button.dataset.profileCheck ?? button.dataset.profileSetup; const result = await api(`/api/admin/profiles/${id}/${button.dataset.profileSetup ? 'setup' : 'check'}`, 'POST', {}); toast(`${result.message}\n${result.fallback.origin}：${result.fallback.status}`); });
        if (button.dataset.revoke) busy(button, async () => { await api(`/api/admin/tokens/${button.dataset.revoke}/revoke`, 'POST', {}); await loadTokens(); toast('Key 已撤销'); });
        if (button.dataset.detail) busy(button, async () => {
            if($('#bulk-dialog').open)$('#bulk-dialog').close();
            const detail = await api(`/api/v1/domains/${button.dataset.detail}`); $('#detail-title').textContent = detail.hostname;
            const actions = ['diagnose','retry','pause','resume','cancel','rollback'];
            const actionNames = {diagnose:'重新诊断',retry:'重试任务',pause:'暂停',resume:'继续',cancel:'取消任务',rollback:'回滚最近变更'};
            const verificationLabels = {present:'待清理',deleted:'已删除',retained:'保留原有记录',external_change:'发现外部修改'};
            const verificationRows = detail.verification_records.map(record => `<tr><td>${record.purpose === 'ownership' ? '域名所有权' : '证书验证'}</td><td>${escapeHtml(record.name)}</td><td>${Number(record.owned) ? '面板创建' : '原有记录'}</td><td>${escapeHtml(verificationLabels[record.status] ?? record.status)}</td></tr>`).join('');
            const jobRows = detail.jobs.map(job => `<tr><td>#${job.id}</td><td>${escapeHtml(labels[job.stage] ?? job.stage)}</td><td>${badge(job.status)}</td><td>${escapeHtml(job.last_error ?? '—')}</td></tr>`).join('');
            $('#detail-content').innerHTML = `<div class="actions">${actions.map(a => `<button class="secondary small" data-domain-action="${a}" data-domain-id="${detail.id}">${actionNames[a]}</button>`).join('')}</div><p class="hint">回滚将恢复本面板对该域名的接入变更，并移除本面板创建的云端主机名。</p><div class="stats"><div class="stat"><span>域名</span>${badge(detail.status)}</div><div class="stat"><span>主机名验证</span>${badge(detail.hostname_status)}</div><div class="stat"><span>证书</span>${badge(detail.ssl_status)}</div><div class="stat"><span>TXT 清理</span>${badge(detail.cleanup_status)}</div></div>${detail.last_error ? `<p class="hint">${escapeHtml(detail.last_error)}</p>` : ''}<h3>验证记录</h3><div class="table-wrap"><table><thead><tr><th>用途</th><th>记录名称</th><th>归属</th><th>处理结果</th></tr></thead><tbody>${verificationRows || '<tr><td colspan="4">暂无验证记录</td></tr>'}</tbody></table></div><h3>任务历史</h3><div class="table-wrap"><table><thead><tr><th>任务</th><th>步骤</th><th>状态</th><th>原因</th></tr></thead><tbody>${jobRows}</tbody></table></div><h3>最近日志</h3><div id="detail-logs"></div><details><summary>原始诊断与变更数据</summary><div id="detail-json"></div></details>`;
            const pre = document.createElement('pre'); pre.textContent = JSON.stringify({diagnostic: detail.diagnostic_json ? JSON.parse(detail.diagnostic_json) : null, changes: detail.changes}, null, 2); $('#detail-json').append(pre);
            const applied=detail.applied_config;
            $('.actions',$('#detail-content')).insertAdjacentHTML('afterbegin',`<button class="primary small" data-open-change="${detail.id}" ${detail.status!=='active'?'disabled':''}>切换入口与回源</button><button class="secondary small" data-open-remove="${detail.id}">移除管理</button>`);
            $('.hint',$('#detail-content')).textContent=Number(detail.adopted)?'此记录从 CF 原有配置纳管，后台定期只读检查；原有主机名、回源及验证记录保留外部归属。明确提交配置切换后进入常规管理流程。':'回滚优先恢复最近一次入口/回源变更；没有配置变更时撤销原始接入，并移除本面板创建的主机名。';
            const mode=applied.dns_mode??'preferred';const currentTarget=mode==='preferred'?(applied.entry.hostname?applied.entry.hostname+' → '+JSON.parse(applied.entry.targets).join(' / '):(applied.entry.kind==='standard'?applied.profile.cname_target:JSON.parse(applied.entry.targets).join(' / '))):(applied.origin.source_ip??applied.origin.source_hostname);
            const changeHistory=document.createElement('section');changeHistory.innerHTML=`<h3>当前生效配置 · 版本 ${detail.config_version}</h3><p>${escapeHtml(dnsModeNames[mode])} · ${escapeHtml(currentTarget)} → ${escapeHtml(applied.origin.source_ip??applied.origin.source_hostname??applied.origin.hostname)}</p><h3>配置变更历史</h3>`;
            const history=document.createElement('pre');history.textContent=detail.configuration_changes.map(change=>`#${change.id} ${labels[change.status]??change.status}  ${change.created_at}\n${change.last_error??''}`).join('\n')||'暂无配置变更';changeHistory.append(history);$('#detail-content').insertBefore(changeHistory,$('#detail-logs').previousElementSibling);
            const logs = await api(`/api/v1/logs?domain_id=${detail.id}&per_page=30`); const logPre = document.createElement('pre'); logPre.textContent = logs.items.map(i => `${i.created_at}  ${i.event}\n${i.message}`).join('\n\n') || '暂无日志'; $('#detail-logs').append(logPre); $('#detail-dialog').showModal();
        });
        if (button.dataset.domainAction) busy(button, async () => {
            if (button.dataset.domainAction === 'rollback' && !window.confirm('恢复最近一次配置变更？如果没有配置变更，将撤销原始接入。')) return;
            await api(`/api/v1/domains/${button.dataset.domainId}/${button.dataset.domainAction}`, 'POST', {},{'Idempotency-Key':crypto.randomUUID()}); toast('操作已受理'); $('#detail-dialog').close(); await loadDomains();
        });
        if(button.dataset.openChange) busy(button,async()=>{const detail=await api(`/api/v1/domains/${button.dataset.openChange}`);const form=$('#change-form');form.reset();$('[name="domain_id"]',form).value=detail.id;$('[name="expected_version"]',form).value=detail.config_version;options($('#change-entry'),config.entries.filter(r=>Number(r.active)));const origins=config.origins.filter(r=>Number(r.active)&&r.profile_id===detail.profile_id);changeSourceOrigin=detail.applied_config.origin;if(changeSourceOrigin.source_ip||changeSourceOrigin.source_hostname)origins.push(changeSourceOrigin);options($('#change-origin'),origins);$('#change-entry').value=detail.entry_id;$('#change-origin').value=detail.origin_id;fillCheck(form,detail.applied_config.check);$('#change-current').textContent=`${detail.hostname} · 当前入口：${detail.applied_config.entry.name}；当前回源：${detail.applied_config.origin.source_ip??detail.applied_config.origin.source_hostname??detail.applied_config.origin.hostname}`;showChangeTarget();$('#detail-dialog').close();$('#change-dialog').showModal();});
        if(button.dataset.openRemove){$('[name="domain_id"]',$('#remove-form')).value=button.dataset.openRemove;$('#detail-dialog').close();$('#remove-dialog').showModal();}
    });
    busy(null, async () => { await loadConfig(); await loadDomains(); });
    setInterval(() => { if (!document.hidden && currentPage === 'domains' && !$$('dialog[open]').length) busy(null, loadDomains); }, 5000);
}
