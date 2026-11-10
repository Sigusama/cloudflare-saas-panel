# 公开 API

本文说明当前接口契约，已验证范围见[验证状态](IMPLEMENTATION_STATUS.md)。基础路径为 `/api/v1`，响应使用 JSON。

对应应用版本 `0.1.0-beta.20`。鉴权、权限、限流、幂等、配置生命周期、域名切换、批量处理及导入导出由专用隔离 HTTP 安装检查；真实账号测试另见[验证状态](IMPLEMENTATION_STATUS.md)。

## OpenAPI 规范与导入

[OpenAPI 3.1.1 JSON](../public/openapi.json) 随版本提供，安装后的 `/openapi.json` 无需登录即可下载，仅包含接口结构和示例，不包含账号、凭据或运行数据。覆盖 `/health` 及全部 `/api/v1` 的 21 个公开操作，管理员接口仍见本文单独说明。

Swagger Editor 选择导入本地文件；Postman 选择 Import → File 或安装地址的规范 URL。导入后将 server 改为自己的面板地址，Authorize / Bearer Token 填写在面板生成的 API Key。首次绑定 CF、创建 Key 与预设由管理员在 Web 完成。浏览器跨站调用仍受同源策略限制，规范导入不自动放开 CORS。

规范包含权限范围、分页、语言、幂等键、异步任务、错误响应、批量预览和确认。接口成功接收不代表 DNS 或证书已经生效；批量与多域名创建还需逐条检查 items 的失败结果。

CI 使用固定版本校验器验证规范，并将隔离安装实际的 HTTP 请求 / 响应逐条对照；全部 21 个操作必须有成功用例，含 DNS 任务详情及重试。实际通过计数见[验证状态](IMPLEMENTATION_STATUS.md)。

## 面板自身的访问地址

「系统设置 → 面板访问地址」使用管理员专用接口，公开 API Key 即使具有全部权限也不能调用：

- `GET /api/admin/panel-settings` 只返回 `app_url`，不返回数据库配置或应用密钥。
- `PATCH /api/admin/panel-settings` 要求管理员会话与 `X-CSRF-Token`，正文示例：`{"app_url":"https://panel.example.com","expected_app_url":"http://localhost:8080"}`。`expected_app_url` 必须与读取值一致，旧值冲突返回 409。

地址支持 HTTP / HTTPS、域名、IP 与端口，统一大小写、根路径斜线和默认端口；拒绝账号信息、非根路径、查询参数和片段。保存只更新私有配置中的 app_url，保留数据库、应用密钥、安装锁及 CF 凭据；变更记录 `panel.url_changed` 审计。保存不强制跳转，换域名后重新登录。新域名解析、Web 服务与 HTTPS 应先配置完成。

此设置针对面板本身，与业务域名、优选入口及 SaaS 回源分开维护。公开 OpenAPI 仍描述 `/health` 与 `/api/v1` 的 21 个操作。

## 管理员 Cloudflare 账号认证

仅管理员 Web 会话可调用以下接口，写操作要求 CSRF，公开 API Key 返回 403：

- `POST /api/admin/accounts`：`name` 与 `auth_type`。api_token（默认）填写 `token`；global_key 填写 `email` 与 `global_key`，两种凭据不能混填。
- `PATCH /api/admin/accounts/{id}`：同方式编辑时密钥留空保留现有值；切换方式必须重新填写凭据，Global Key 邮箱必填或保留已有邮箱。返回账号 ID，不返回凭据。
- `GET /api/admin/accounts/{id}/auth`：返回 `auth_type` 和 `email`（Token 为 null），仅供编辑，不返回密钥，已删除账号返回 404。
- `POST /api/admin/accounts/{id}/sync`：只读同步可访问 Zone。Web 保存后自动调用，并启动已有 SaaS 读取；API 直接添加账号后应自行调用同步与读取接口。

`/api/v1/config` 的账号仅增加 `auth_type` 元数据，不返回邮箱和密钥；导出格式和公开 API Key 的 Bearer 认证保持不变。账号 API Token 与面板公开 API Key 是不同凭据。

界面和接口错误提示支持简体中文 / English。通过 `Accept-Language: en` 请求英文；已保存的 `panel_locale` Cookie 优先。响应 `Content-Language` 标识本次语言。HTTP 状态、error.code、字段名与枚举值保持稳定，持久化日志及原始诊断仍保留来源文本。

## 主域名记录快速接入

### 载入全部原有 DNS

`GET /api/v1/zones` 默认返回全部账号的主域名，支持 `?account_id=2` 只读筛选指定的本地账号 ID；需要 domains:read。每个主域名返回其所属的本地账号 account_id / account_name；前台可先选择账号，再选择它的主域名。未知或已删除账号返回空 items，停用账号保留 account_active=0；非法参数（含 0、非整数或数组）返回 422。此参数是列表筛选，不改变 Key 权限。Web 在概览和 DNS 页共用账号 / 主域名选择，切换时清空旧批量选择。

`GET /api/v1/zones/{id}/records?include_existing=1&page=1&per_page=50&search=TXT`，需要 domains:read。Web 默认使用此模式；未传 include_existing 时继续返回面板接入记录，保持旧客户端兼容。

返回 `dns_snapshot.loaded / synced_at / record_count`。`managed=true` 为本地纳管记录，整数 id 沿用原接口，`observed_dns_records` 为真实读取的 A / AAAA / CNAME；`managed=false` 为原有 DNS，id 为 `cf:<CF_RECORD_ID>`，`record` 保存 type、content、ttl、proxied、priority、data 等字段，并返回 expected_fingerprint 与未完成的 operation。cf: 标识提交至下述批量入口，不能用于整数域名详情路径。列表保留同名无关 TXT / MX 等，搜索原记录支持名称、类型和内容。

管理员会话调用 `POST /api/admin/zones/{id}/dns-sync`，带 CSRF，只读同步该 Zone 全部 DNS，返回 count、synced_at 和 message；公开 API Key 返回 403。页面首次打开未加载的主域名会自动调用。成功覆盖本地完整快照；读取失败、重复记录或超限保留上次结果，不修改 CF。原有 DNS 可编辑、删除及批量处理，下面的确认与任务接口负责执行。

### 原有 DNS 编辑、删除与三种模式

共用 `POST /api/v1/zones/{zone_id}/records/bulk`，需要 domains:write，Web 会话带 CSRF，每次 1 至 50 条。preview 默认 true。原有记录使用字符串 id 和列表返回的 expected_fingerprint，已纳管记录仍使用整数 id / expected_version，可混合请求。示例为预览一条 MX 的修改：

```json
{"action":"update","preview":true,"records":[{"id":"cf:CF_RECORD_ID","expected_fingerprint":"64_HEX_FROM_LIST","changes":{"content":"mail.example.com","priority":10,"ttl":600}}]}
```

action=update 的 changes 允许 name、type、content、ttl、proxied、priority、comment、tags、data、settings，只修改提交字段；也可在顶层传公共 changes。原有记录的删除使用 action=delete、mode=delete_records；不适用恢复原解析或仅移除面板管理。切换使用 action=set_mode、dns_mode=dns_only / proxied / preferred，仅 A / AAAA / CNAME 可用。开云设置 ttl=1，普通切换保留现有内容。

原生记录进入优选可指定 entry_id、profile_id，默认使用全局设置；可显式覆盖 source_ip 或 source_hostname，也可传 check 设置路径、预期状态码及关键字。已纳管记录的批量优选可切换 entry_id，其余沿用自身配置；修改回源与检查条件使用域名编辑接口。已有 SaaS 优先按原配置纳管再切换。单一原记录默认取当前源站；同名多地址必须明确回源并传 include_same_name=true，完整地址范围在 before 中展示，同名选择合并为一项任务。优选预览返回 expected_rrset，确认必须带回，防止同名范围在确认间变化。

预览只有 GET 核对和本地校验，不写 CF、不创建执行任务。确认传 preview=false 和 Idempotency-Key，返回 202，逐项 status 为 queued / unchanged / failed；queued 项返回 operation_id（原记录）或 job_id（已有纳管域名）。原记录返回 before、after、references 与 message，after=null 表示删除。确认后仍由 Worker 再次核对原始基线，发现外部变化返回需处理，不覆盖。选择或预览不授权后续自动提交。

`GET /api/v1/dns-operations/{id}` 需要 domains:read，返回 queued / waiting / waiting_domain / completed / needs_attention / failed、before / after、last_error 和委托的 domain_id / job_id。`POST /api/v1/dns-operations/{id}/retry` 需要 domains:write，Web 带 CSRF，仅失败任务可重试。提交、执行、冲突和重试记录审计；修改或删除响应丢失时读回对账，不盲目重复写入。

先配置全局默认 SaaS、访问入口和回源，调用 `GET /api/v1/zones` 获取本地 Zone ID（不是 Cloudflare Zone ID），然后提交：

```http
POST /api/v1/zones/12/records
Authorization: Bearer <API_KEY>
Content-Type: application/json
Idempotency-Key: <UNIQUE_KEY>

{"name":"aaa","type":"A","source_ip":"<PUBLIC_ORIGIN_IPV4>"}
```

如果 Zone 为 `aaa.com`，业务域名即为 `aaa.aaa.com`。`name` 可为相对名称、完整域名或 `@`；也可使用明确的 `hostname` 字段，但必须属于该 Zone。`type` 为 `A` / `AAAA` 时 `source_ip` 必填；选择 `preset` 时使用 `origin_id` 或默认回源预设。`profile_id`、`entry_id`、`check` 可覆盖全局默认值，DNS 账号始终取自所选 Zone。

源站 IP 绑定到 SaaS 账号的自动代理回源，访客侧 DNS 按默认优选入口生成。该接口返回 202 和 `items[].domain_id / job_id`。默认不授权替换已有 DNS；明确需要时传布尔 `replace_existing: true`。查询记录支持 `page`、`per_page`、`search`，返回 `source`、`record_type`、`dns_records` 和 `applied_config`。

修改已接入源站：`PATCH /api/v1/domains/{id}`，传 `source_ip` 和 `expected_version`；也可传 `type` 验证地址族。改回预设传 `origin_id`，不要同时传 `source_ip`。完成后从详情读取新生效快照，失败会恢复本次变更。

回源默认使用 IP：`{"name":"dns","type":"A","source_ip":"<PUBLIC_IP>","entry_id":12}`。手动选择 CNAME 回源时传 `{"name":"dns","type":"CNAME","source_hostname":"origin.example.com","entry_id":12}`；source_ip 与 source_hostname 互斥，CNAME 不接受 IP 或业务域名自身。PATCH 同样支持 type=CNAME、source_hostname 和 expected_version。面板为 CNAME 源站创建独立代理别名，原有 type=preset / origin_id 只作历史配置兼容。

entry_id 只表示优选目标，与回源地址分开。kind=cname 的目标只有一个域名，kind=ip 可保存 1 至 10 个不同 IP（支持混合 IPv4 / IPv6）。绑定自定义入口后，业务域名只生成一条 CNAME 指向入口，多个 A / AAAA 发布在入口主机名下；旧的未绑定入口配置保留直接解析行为。这不包含自动测速或故障切换。CNAME 回源需要迁移 004，自定义入口需要迁移 005。

`dns_mode`：`dns_only`（关云，A / AAAA 或 CNAME 直连源站）、`proxied`（开启小黄云并使用自动 TTL）、`preferred`（优选，默认）。创建与 PATCH 均可传入；普通 DNS 要求明确的 source_ip 或 source_hostname，不能直接把历史回源预设当源站。普通 DNS 保存以 CF DNS 内容和代理状态核对为准，不标记为 HTTPS 检查通过。

一键模式切换示例：`PATCH /api/v1/domains/123`，`{"dns_mode":"proxied","expected_version":2}`。只传模式时保留当前生效入口和回源快照。待任务完成后读取 `dns_mode`，处理中从 `configuration_changes[].to_config` 查看目标，不能把排队中的目标当作已经生效。普通 DNS 首次进入优选会自动补齐 SaaS 主机名及验证；离开优选保留 SaaS 资源以供恢复，明确移除时按归属处理。

## 批量切换与删除

`POST /api/v1/zones/{zone_id}/records/bulk`，需要 `domains:write`；Web 会话调用还需 CSRF。每批 1 至 50 条显式记录，同一主域名，重复 ID、缺失版本、非整数 ID / 版本拒绝整个请求。

```json
{
  "action": "set_mode",
  "dns_mode": "proxied",
  "preview": true,
  "records": [
    {"id": 12, "expected_version": 3},
    {"id": 15, "expected_version": 1}
  ]
}
```

`dns_mode` 可选 `dns_only` / `proxied` / `preferred`。`preview` 默认 true，返回 200；逐条校验并回滚临时本地写入，不创建持久任务或调用云端。API 请求审计正常记录。确认时传 `preview:false` 和新的 `Idempotency-Key`，返回 202。界面只提交预览结果为 ready 的条目，服务端再次验证版本。部分条目失败不会阻断其他有效记录，已是目标模式的条目不创建新任务。

返回 `batch_id`（预览为 null）、`summary` 与 `items`。summary 包含 total / ready / queued / removed / unchanged / failed；items 含 id、hostname、expected_version、status，以及已排队项的 job_id 或失败项的 error.code / error.message / error.status。不同 Zone 或已移除的 ID 不返回域名。queued 只表示已提交后台任务，不能解释为 DNS 已切换或已删除；后续通过记录详情、任务接口和日志查看执行结果。

批量删除将 action 改为 `delete`，通过 `mode` 明确处理方式：

| mode | 行为 |
| --- | --- |
| `delete_records`（批量默认） | 删除当前匹配的 A / AAAA / CNAME，不恢复原解析，清理自有 SaaS 与验证资源，归档管理记录 |
| `restore` | 恢复原 DNS，保留云端主机名 |
| `delete_remote` | 恢复原 DNS，删除面板确认创建的主机名 |
| `forget` | 仅移除面板管理，保留云端资源并取消未结束任务 |

其他删除方式与模式切换均要求先处理未完成任务。实际删除会检查 DNS 外部变化，保留无关 TXT / MX、复用或归属不明的云端主机名以及审计历史。失败条目可处理原因后重新读取版本、预览和提交。网络重试必须复用原请求体和幂等键，避免重复排队。

## 管理员导入导出与版本

以下接口仅支持管理员 Web 会话；写入还需 CSRF。公开 API Key 无法调用：

| 方法与路径 | 内容 |
| --- | --- |
| `GET /api/admin/export` | 全部配置与记录计划；不含凭据 |
| `GET /api/admin/export?zone_id=12` | 单 Zone 及其记录依赖，不含全局默认配置 |
| `POST /api/admin/import` | `document`、`account_bindings`、`preview`、`apply_defaults` |

`account_bindings` 示例：`{"exported-account-ref": 3}`。未显式绑定时，仅在本地同名、同服务商且启用的账号恰好一个时自动匹配。导入前必须同步 Zone。`preview` 默认为 true，预览与正式提交采用相同校验，预览不持久化任务或配置；确认时传 false。`apply_defaults` 默认为 false，只有全量文件包含默认配置时可启用。确认提交支持 `Idempotency-Key`。所有条目在同一事务中导入，记录冲突会拒绝整批提交。

导出 JSON 使用 format="cf-saas-panel"。无自定义入口时，基础优选为格式 1，普通 DNS 为格式 2，含 source_hostname 时为格式 3；自定义入口一般使用格式 4，保存入口域名与账号 / Zone 依赖。含标准 CNAME 为空的已有 SaaS 配置时使用格式 5：本地已纳管可复用，新安装必须先读取并纳管，不能从此文件重新创建接入。新版导入兼容 1 / 2 / 3 / 4 / 5，低版本拒绝未支持的字段。文件只包含配置和已纳管接入计划，不导出只读载入的其他 DNS。优选 IP 组完整保留，应用版本在 app_version 中，不含凭据或云端运行标识。请求最多 1 MiB、界面文件限 950 KB；每次最多 1000 条记录，各配置分组最多 2000 项。

应用版本还在 `GET /health` 的 `data.version`、`GET /api/v1/config` 的 `data.version` 和 CLI `version` 命令中提供。全量数据库及密钥备份另见部署说明。

## 管理员自动读取 CF 配置

`GET /api/admin/accounts/{account_id}/saas-discovery?zone_id=<CF_ZONE_ID>`，仅管理员 Web 会话可用，API Key 返回 403。Zone 必须属于该已同步且启用的账号。与记录接口的本地 Zone ID 不同，此处传账号下实际 CF Zone ID。

返回 `name`、`zone_id`、`zone_name`、`fallback_origin`、`fallback_status`、`cname_candidates`、`cname_target` 和 `source`。读取 CF 默认回源后，从该站点 DNS 筛选直接指向它的橙云 CNAME，排除通配符和其他站点。仅一个候选时自动返回 cname_target；多于一个或没有候选时为 null，交由用户选择或补充。权限 / 网络错误和未配置默认回源不会被当作成功。

发现过程只读，不创建配置或修改 CF。确认保存仍使用既有 profiles 管理接口；Web 表单会复用同账号 / Zone 的现有配置，避免重复创建。Web 保存首次 SaaS接入配置和默认优选入口后另行调用 defaults 接口；直接使用管理 API 时仍应显式保存默认值。Demo 的 source 为 demo，只读本地已有预设，不调用真实 CF。

## 鉴权与权限

管理员在面板创建 API Key，明文仅显示一次。调用时发送 `Authorization: Bearer <API_KEY>`。Key 可指定有效期并随时撤销，不在 URL 中传递。

| 权限 | 允许的操作 |
| --- | --- |
| `config:read` | 读取可供接入选择的 SaaS、入口、回源和 DNS 账号标识，不含凭据 |
| `domains:read` | 查询域名、任务和诊断结果 |
| `domains:write` | 创建接入、切换配置、诊断、任务控制、回滚及移除管理 |
| `logs:read` | 读取脱敏审计日志 |

凭据管理、安装和 API Key 管理使用管理员 Web 会话，不通过上述 Key 开放。

## 接口列表

| 方法与路径 | 作用 | 权限 |
| --- | --- | --- |
| `GET /api/v1/config` | 查询配置选项和状态 | config:read |
| `GET /api/v1/zones` | 主域名列表、所属账号和记录数，可选 account_id 筛选 | domains:read |
| `GET /api/v1/zones/{zone_id}/records` | Zone 下的接入记录，含源站与实际 DNS 目标 | domains:read |
| `POST /api/v1/zones/{zone_id}/records` | 使用记录名及源站 IP 创建接入任务 | domains:write |
| `POST /api/v1/zones/{zone_id}/records/bulk` | 预览 / 提交批量模式切换或删除 | domains:write |
| `GET /api/v1/domains` | 分页查询域名 | domains:read |
| `GET /api/v1/domains/{id}` | 域名、验证和变更详情 | domains:read |
| `POST /api/v1/domains` | 单个或批量创建接入任务 | domains:write |
| `PATCH /api/v1/domains/{id}` | 为已接入域名提交入口 / 回源 / 检查配置变更 | domains:write |
| `DELETE /api/v1/domains/{id}` | 按指定模式移除管理或恢复 DNS | domains:write |
| `GET /api/v1/dns-operations/{id}` | 原 DNS 修改任务及修改前后内容 | domains:read |
| `POST /api/v1/dns-operations/{id}/retry` | 重试失败或待处理的 DNS 修改，保留原基线 | domains:write |
| `GET /api/v1/jobs/{id}` | 查看任务状态 | domains:read |
| `POST /api/v1/domains/{id}/retry` | 从可恢复阶段重试 | domains:write |
| `POST /api/v1/domains/{id}/pause` | 暂停后续步骤 | domains:write |
| `POST /api/v1/domains/{id}/resume` | 恢复执行 | domains:write |
| `POST /api/v1/domains/{id}/cancel` | 取消后续步骤，保留已执行变更 | domains:write |
| `POST /api/v1/domains/{id}/diagnose` | 提交诊断任务 | domains:write |
| `POST /api/v1/domains/{id}/rollback` | 优先恢复最近一次配置变更；无变更时撤销原始接入 | domains:write |
| `GET /api/v1/logs` | 分页读取日志，支持 domain_id、job_id、hostname、event、actor、since、until | logs:read |

## 创建接入

```http
POST /api/v1/domains
Authorization: Bearer <API_KEY>
Content-Type: application/json
Idempotency-Key: onboarding-batch-001
```

```json
{
  "hostnames": ["app.example.com", "www.example.com"],
  "profile_id": 1,
  "dns_account_id": 2,
  "entry_id": 1,
  "origin_id": 3,
  "replace_existing": false
}
```

ID 均来自配置查询。`origin_id` 对应已保存的回源，例如管理员自定义的 `dns.xxx.com` 或 `cn.xxx.com`；这里不把入口地址和源站主机名混成一个字段。

返回 202，结果包含各域名的 domain_id、job_id 或独立错误。调用方再查询任务，不需要保持创建请求一直连接。

省略配置 ID 时使用面板保存的默认值。新建请求可传入 `check`，结构与下面的切换示例相同。布尔参数必须使用 JSON 的 true / false，不能用字符串 `"false"` 代替。

## 切换已接入域名的配置

```http
PATCH /api/v1/domains/12
Authorization: Bearer <API_KEY>
Content-Type: application/json
Idempotency-Key: switch-domain-12-001
```

```json
{
  "entry_id": 5,
  "origin_id": 7,
  "expected_version": 2,
  "check": {
    "path": "/health",
    "statuses": [200],
    "keyword": "healthy"
  },
  "replace_existing": false
}
```

仅支持同一 SaaS 配置内切换入口、回源和检查选项。`expected_version` 来自域名详情中的 `config_version`，版本不符返回 409。若所选预设 ID 未变但预设内容已经更新，仍会创建明确的变更任务；目标内容完全未变时返回 `changed: false`。

接入详情中的 `applied_config` 是当前生效快照，`configuration_changes` 是独立的变更历史。变更通过检查后才更新快照和版本；验证失败会自动恢复本次 DNS 和回源。外部修改导致恢复无法安全继续时，保留差异并显示 rollback_failed，不覆盖外部值。

`replace_existing: true` 表示预检允许以当前 DNS 为基线接管偏离面板配置的记录，不表示允许覆盖任务执行期间发生的外部修改。

## 回滚与移除

`POST /domains/{id}/rollback` 恢复最近一个未完成或已生效的配置变更，只影响该变更；没有配置变更时撤销原始接入。暂停、取消及重试不自动撤销已有云端修改。

`DELETE /domains/{id}` 必须明确传入以下模式之一：

| mode | 处理方式 |
| --- | --- |
| `forget` | 停止管理并归档，保留云端 DNS 和主机名 |
| `delete_records` | 删除当前匹配的接入解析，不恢复原 DNS，清理自有 SaaS 资源并保留历史 |
| `restore` | 后台恢复原 DNS，再归档，保留云端主机名 |
| `delete_remote` | 恢复原 DNS，并删除本面板确认创建的主机名；复用或创建归属不明的主机名保留 |

归档保留历史记录；用相同配置重新提交该域名可重新加入管理。已有未结束任务时，恢复类移除会返回 409，应先处理、取消或回滚原任务。

## 管理员配置接口

面板使用以下接口维护配置，只有管理员会话可调用，写入必须带 `X-CSRF-Token`。普通 API Key 即使拥有域名写权限也不能管理凭据。

| 接口 | 作用 |
| --- | --- |
| `POST /api/admin/{accounts|profiles|entries|origins}` | 新建配置 |
| `PATCH /api/admin/{accounts|profiles|entries|origins}/{id}` | 编辑或传入 active 布尔值启用 / 停用 |
| `DELETE /api/admin/{accounts|profiles|entries|origins}/{id}` | 删除无引用配置，保留审计历史 |
| `PATCH /api/admin/defaults` | 保存新任务默认配置与 check |

账号编辑时省略 token 或传空字符串保留原凭据，提供新 token 时加密轮换。入口与回源编辑不改变已接入域名的快照。已被使用的 SaaS 账号 / Zone / 共享回源映射禁止直接重写，防止影响其他域名；同一账号和 Zone 使用一份 SaaS 配置，多个回源通过回源预设管理。

### 自定义优选入口

`POST /api/admin/entries`，示例（管理员会话 + CSRF）：

```json
{"name":"国内入口","kind":"ip","targets":["1.1.1.1","2606:4700:4700::1111"],"zone_id":12,"record_name":"cn"}
```

zone_id 是本地 Zone ID，record_name 是自定义前缀，也可填写该 Zone 内的完整名称；或使用明确的 hostname 完整域名。假设 Zone 为 aaa.com，入口为 cn.aaa.com。kind=cname 时 targets 为单个优选域名。创建业务记录传 entry_id，业务侧始终 CNAME 到 cn.aaa.com；source_ip / source_hostname 仍独立表示网站源站。

配置列表返回 zone_ref、hostname、route_state；生效快照 entry 保存 hostname、zone_name、dns_account_id、dns_zone_id。保存配置不执行云端写入，后台首次接入时创建并核对共享入口，route_state=applied 才表示该计划部署过。已有入口遭外部修改或删除时停止覆盖，需要管理员检查后恢复，再重试任务。

入口必须是授权主域名的子域名，不能使用根域名、重复入口、业务记录、标准接入或回源名称；拒绝已知的本地 CNAME 循环。入口 DNS 有冲突时不覆盖，即使业务记录传 replace_existing=true。外部完整匹配的 RRset 可只读复用，外部部分 IP 组不接管；自有未完成写入可对账重试。

入口有引用或 entry_routes 部署计划后，禁止改主域名、前缀、类型或目标，返回 409；名称和启用状态仍可改。换目标请新建入口，再 PATCH 业务记录的 entry_id。删除业务或入口本地配置保留共享入口 DNS。省略 zone_id / record_name 的旧接口调用仍支持历史直接目标行为，Web 新建默认绑定入口域名。

含自定义入口的导出使用 format_version=4，entries[].alias 包含 hostname、zone_name、account_ref。单 Zone 文件包含入口所需的其他账号依赖；导入先同步入口 Zone，再绑定账号，支持预览、复用和事务导入。仍兼容格式 1 / 2 / 3；旧格式不接受 alias 字段，避免静默丢失解析链路。

## 已有 SaaS 自动读取与纳管

Web 菜单为「导入已有 SaaS」，用于接管历史配置；日常操作在域名管理。扫描中的 fallback_status=not_configured 表示此 Zone 没有 SaaS 默认回源（404 / CF 1551），不是读取失败，DNS 与主机名继续读取。真实权限错误仍在 errors 返回。单站点服务发现遇到该情形返回 422 / saas_not_configured，提示选择已经配置 SaaS 的站点。

以下为管理员接口，要求登录会话；POST 还需 X-CSRF-Token。公开 API Key 不能读取库存或纳管，已纳管域名仍使用原 /api/v1 域名接口查询和管理。

| 接口 | 作用 |
| --- | --- |
| GET /api/admin/saas-inventory | 最近批次，无记录时 run=null |
| POST /api/admin/saas-inventory | 新建读取计划，返回 201；支持幂等键 |
| GET /api/admin/saas-inventory/{id} | 状态、游标、读取来源、各 Zone 进度和错误 |
| POST /api/admin/saas-inventory/{id}/step | 一步读取，至多一条 CF 请求，或关联 100 条本地结果 |
| GET /api/admin/saas-inventory/{id}/items | page、per_page、search；域名与关联候选 |
| POST /api/admin/saas-inventory/{id}/adopt | 单条纳管预览 / 确认；支持幂等键，返回 200 |

开始前使用已有 POST /api/admin/accounts/{id}/sync 同步 Zone；Web 自动对所有启用账号执行此步骤。begin 请求 `{ "account_ids": [] }` 表示读取全部 SaaS；指定本地账号 ID 数组时仅在这些账号读取 SaaS，但 DNS 关联仍覆盖所有已同步的启用账号。begin 只保存本地计划，API 调用方负责持续调用 step 至 run.state=completed。

run.source 为 cloudflare 或 demo，state 为 reading / linking / completed，retry_after 为再次允许远端请求前的等待秒数。遇到可恢复错误保持页码，尊重 CF Retry-After；最多重试两次，持续失败标明不完整并继续其他 Zone。同步 Zone 的失败信息保存在 cursor.sync_errors（Web 提供的最多 100 条、每条最多 512 字符文本）。批次模式变化或超过 24 小时不能纳管，需重新读取。

items[].confidence：confirmed（关系已确定）、pending（需确认）、blocked（需处理）。profile 包含默认回源、标准 CNAME 候选与可自动确定的值；origin 为显式 / 默认回源及源站目标；routes 提供 id、DNS 所属账号 / Zone、自定义入口、目标和 chain；reasons / blockers 说明歧义或不能纳管的原因，managed 指示本地已有记录。已知配置按 IP 集合匹配，CF 返回顺序不会产生新目标；同一 CF Zone 被多个凭据读取且记录相同会合并。

纳管示例：

```json
{
  "key": "<items[].key>",
  "route_id": "<selected routes[].id>",
  "cname_target": "saas.example.com",
  "confirm_preferred": true,
  "preview": true
}
```

唯一有效关联、已确定标准 CNAME 可省略相应字段。没有标准 CNAME 候选时，cname_target 省略或为空字符串；按已读取的优选入口纳管，不猜测标准目标、不补建橙云记录。非已知优选用途必须 confirm_preferred=true，不能以确认绕过 blocked 或不属于快照的选择。preview 默认 true；预览使用读取快照并回滚本地配置 / 域名 / 任务，不调用云端写接口。确认传 preview=false 及新的幂等键，重新 GET 核对主机名、回源 / SNI、默认回源、已有标准候选及关联 DNS；变化返回 409。接口只接受快照中的方案，不能注入其他记录或覆盖本地不同的配置 / 归档历史。

确认保存 domain.adopted=1、hostname_created=0 和已观察的 DNS 快照，排队 inspect 任务。仅 SaaS 及证书 active 不代表业务访问成功；只读检查通过后才将域名设为 active，失败进入待处理 / 重试。完整验证 TXT 保持外部归属，本功能不会清理导入前的 TXT。job.context_json 和审计记录保存批次、关联方案、标准 CNAME、确认来源（automatic / manual）。

未修改纳管记录的定期 / 手动诊断为只读，外部 DNS 增删改会停止检查并提示核对。明确 PATCH 切换成功后 adopted=0，进入原变更及续期流程；共享原源站保持外部归属，改源站时创建独立的新回源。无配置变更的纳管记录不能执行“撤销原始接入”，可选择 forget；显式变更仍支持按次回滚。delete_records 仅按明确请求删除业务 DNS，原有 SaaS 主机名、外部回源、共享入口和原 TXT 不自动删除。

Web 每次最多选择 20 条，逐条预览并仅确认通过项；每条确认独立幂等，部分失败可修正后重试。读取最多 1000 个已同步 Zone、每批 DNS 50000 / SaaS 10000 条、单 Zone JSON 8 MB；超过上限显示不完整，不将部分数据当作完整清单。当前泛域名、开启代理的业务 DNS、缺少回源 / 标准入口或授权范围外的必要记录只读显示为需处理。

## 响应与错误

成功响应：

```json
{"success":true,"data":{"items":[]},"request_id":"request-identifier"}
```

错误响应：

```json
{"success":false,"error":{"code":"validation_error","message":"请检查域名或配置"},"request_id":"request-identifier"}
```

查询列表接受 `page`、`per_page`，每页最多 100 条，返回分页信息。日志不含完整凭据。

| 状态码 | 含义 |
| --- | --- |
| 200 / 201 | 查询或短操作成功 |
| 202 | 后台任务已受理 |
| 400 / 422 | 请求格式或字段错误 |
| 401 | Key 无效、过期或已撤销 |
| 403 | 权限不足 |
| 404 | 对象不存在 |
| 409 | 域名任务或幂等请求冲突 |
| 429 | 超过限制，按 Retry-After 重试 |
| 500 / 503 | 服务错误或未安装，不返回敏感内部配置 |

幂等键在同一 API Key 范围内生效，并绑定 HTTP 方法和路径。相同键、相同路径与内容返回原结果；跨路径或内容不同返回 409。创建、切换、任务控制和移除接口均支持幂等。API Key 默认每分钟限制 60 次，可用 `API_RATE_LIMIT` 调整（10 至 1000）；响应包含 X-RateLimit-Limit、X-RateLimit-Remaining、X-RateLimit-Reset，超限返回 Retry-After。GET 不修改云端业务资源，但会记录 API 调用审计。

## TXT 清理状态

域名详情返回 `cleanup_status` 与每条验证记录的归属、验证用途和清理结果。业务访问成功但删除失败时保留 `cleanup_pending`，后台继续重试；不会因删除失败重新创建业务 DNS。
