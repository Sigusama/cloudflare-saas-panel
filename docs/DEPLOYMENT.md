# 部署与安装

本文随实现同步维护。当前实际验证范围见[开发进度](IMPLEMENTATION_STATUS.md)。

## Docker Compose

准备能够运行 Linux 容器的 Docker Engine 与 Compose 2.20+，复制 `.env.example` 为 `.env`，填写两个不同的强随机数据库密码。执行：

```sh
docker compose up -d --build
docker compose logs web
docker compose ps
```

Web 启动日志显示首次安装口令。访问 `http://localhost:8080/install`，填写口令、数据库参数和管理员信息。Docker 默认数据库地址是 `db`，数据库名和用户来自 `.env`。

安装向导完成数据库初始化并生成应用密钥、写入安装锁；Worker 自动从等待安装转为处理任务。再次打开安装页不能重置站点。

`mysql_data` 保存 MySQL 数据，`panel_storage` 保存应用配置、密钥及运行日志。普通 `docker compose down` 不删除卷；升级时不要使用删除数据卷的参数。

容器默认仅暴露 Web 端口。对外使用时由反向代理或 Web 服务配置 HTTPS，正确设置站点 URL。安装口令属于一次性初始化凭据，完成后作废。

Web、MySQL 和 Worker 均提供健康检查。Web 等待数据库健康，Worker 等待 Web 健康；尚未安装时 Worker 处于等待状态，安装完成后执行任务。不要把“健康且等待安装”误认为已经创建管理员。

环境参数：`PANEL_PORT` 为 Web 端口，`MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` 为应用数据库，`MYSQL_ROOT_PASSWORD` 为独立管理员密码，`API_RATE_LIMIT` 为每个 API Key 每分钟请求限制。MySQL 不默认公开端口。

## 普通 PHP / MySQL 环境

需要 PHP 8.3+，扩展 PDO、pdo_mysql、curl、openssl、mbstring、intl，及 MySQL 8.0+。Web 根目录设为 `public/`，允许 PHP 用户写入 storage。

```sh
php bin/console.php install:token
php -S 127.0.0.1:8080 -t public public/router.php
```

访问 `/install`，用已有数据库和一次性口令完成安装。PHP 内置服务器只供本地开发；生产环境使用 Apache / Nginx 与相应 PHP 配置。

PHP cURL 必须能验证 Cloudflare 的 HTTPS 证书。Windows 独立 PHP 如出现 cURL 60 / unable to get local issuer certificate，从 [curl 官方 CA 页面](https://curl.se/docs/caextract.html)下载证书包并核对官方 SHA-256，在实际加载的 php.ini 中配置 `curl.cainfo="D:/php/cacert.pem"`，必要时同时设置 `openssl.cafile`，再重启 Web / Worker。路径必须是实际证书文件的绝对路径，见 [PHP curl.cainfo 说明](https://www.php.net/manual/en/curl.configuration.php)。不要通过关闭 TLS 校验解决连接问题。

单独启动后台进程：

```sh
php bin/console.php worker
```

也可以通过 Cron 周期调用 `php bin/console.php worker --once`。必须保证与 Web 使用同一个 storage 配置；只启动 Web 不会自动完成验证和 TXT 清理。

正式服务器应使用 Supervisor、systemd 或容器重启策略维持常驻 Worker。`php bin/console.php health:worker` 可检查心跳；安装前返回等待状态，安装后超过 90 秒没有心跳会失败。

## 备份与升级

当前版本 `0.1.0-beta.17`。页面页脚、/health 与 CLI version 可查询版本。升级前备份 MySQL 和私有配置，再运行 migrate；beta.16 / beta.17 均没有新增数据库迁移，最新为 009_dns_operations.sql（beta.15 原有 DNS 修改任务）。从 beta.13 或更早升级会先应用 008，beta.12 或更早会先应用 007；更旧版本按编号继续补齐。升级不自动转换或改写 DNS。Docker Web 启动时自动迁移。不要重装或更换应用密钥；早于 beta.7 的会话升级需要重新登录。CSS / JS 带版本参数，部署时同时替换模板与静态资源。

原有 DNS 的编辑、删除和模式切换采用「预览 → 确认提交 → 后台执行」。确认前不改解析，确认后需要 Worker 运行，Docker Compose 已包含该服务；普通安装需保持 `php bin/console.php worker` 运行。看见 queued / waiting 仅表示未完成，任务详情可查看原值、目标、冲突和重试。只读同步、页面轮询和演示 Worker 不会自动执行未提交的方案。

面板 JSON 导出只保存配置及接入计划，排除所有凭据和云端运行状态，不能替代下面的数据库与 storage 备份。跨安装导入前先添加 Cloudflare 账号并同步 Zone，再绑定文件中的账号；确认导入会提交真实接入任务。

格式 5 包含按现有优选配置纳管、未设置新建标准 CNAME 的站点。这类记录应在目标安装先读取和纳管，再导入复用；导入不会虚构标准入口或把原解析切换后重新接入。域名管理载入的其他原有 DNS 不包含在接入计划导出中，完整灾备仍使用数据库和私有配置。

同时备份数据库及 storage 中的应用配置和密钥，不上传公开仓库。丢失加密密钥会导致云端凭据无法解密。升级前备份，使用迁移命令更新数据库，保留安装锁和管理员。

Docker 升级：

```sh
docker compose up -d --build --wait
docker compose logs --tail=100 web worker
```

Web 容器在已安装状态下会先运行数据库迁移，再启动 Apache；Worker 等待健康检查通过。普通 PHP 环境在更新文件后执行 `php bin/console.php migrate`，然后重新启动 Worker。迁移保留原管理员和加密密钥，不通过 Web 安装页重装。

已有 HTTPS 反向代理时，安装地址填写实际 HTTPS 站点地址。PHP 与代理之间可以是内部 HTTP；会话安全属性依据安装时保存的站点地址。私有配置不可放到 public 目录中。

## Docker 验收与 CI

```sh
sh tests/docker-smoke.sh
```

需要 Linux Docker 主机及 curl。脚本创建独立测试 Compose 项目与随机 Web 端口，验证构建、主机映射端口、空库 Web 安装、公开 API、服务重启及全部容器替换后的数据库 / 应用配置持久化、Worker 健康及 MySQL 集成测试。结束时只清理该测试项目的容器与卷，不要用生产 Compose 项目替代该隔离测试。仓库的 GitHub Actions 运行同一脚本，并支持手动触发。

HTTP 测试显式启用 `TEST_HTTP_ISOLATED=1` 并在生成模拟任务前写入私有 `preview_mode=1`，禁止测试 Worker 使用模拟 Token 调用真实 Cloudflare；Worker 仍更新心跳，演示心跳不获取域名操作锁。这只用于测试库，正常安装不设置该值。脚本完成重启与心跳检查后停止该测试 Worker，再运行自己驱动的模拟云端集成测试；使用同一 MySQL 的集成 / HTTP 套件应顺序执行，避免操作锁竞争。

beta.17 已在隔离 Linux Docker Engine 内通过镜像构建、169 项首次安装、167 项重启、167 项容器重建 HTTP 检查及 413 项集成检查，实测环境与复现方式见[Docker 验收](DOCKER_VALIDATION.md)。真实 Cloudflare 联调另行记录在[开发进度](IMPLEMENTATION_STATUS.md)，Docker 测试不连接真实 CF 凭据。

Cloudflare for SaaS、客户 DNS 操作权限和公共入口兼容性在应用内分别检查。仅有可读取 Zone 的 Token 不代表有写 DNS 或管理证书的权限。

## Cloudflare Token 权限与真实联调

在 CF 创建自定义 API Token，完整联调使用以下 **Zone（区域）** 权限：

| 权限 | 级别 | 用途 |
| --- | --- | --- |
| Zone | Read | 同步授权的主域名 / Zone |
| DNS | Edit / Write | 读取和修改 CNAME、A / AAAA、TXT，以及 SaaS Zone 的自动回源记录 |
| SSL and Certificates | Edit / Write | 管理 SaaS Custom Hostnames、证书和 fallback origin |

依据 CF 官方 [Zone 列表权限](https://developers.cloudflare.com/api/resources/zones/methods/list/)、[DNS 写权限](https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/create/) 和 [Custom Hostnames 写权限](https://developers.cloudflare.com/api/resources/custom_hostnames/methods/create/)。以上为使用 API Token 时所需权限。

也可在账号管理选择 Global API Key，填写所属 Cloudflare 账号邮箱与全局密钥。Global Key 继承用户权限，不单独选择上述 Token 权限，实际访问范围取决于该用户在 CF 的权限与已开通功能。客户端使用 X-Auth-Email / X-Auth-Key，密钥与邮箱一起加密保存。参见 CF 官方 [Global API Key](https://developers.cloudflare.com/fundamentals/api/get-started/keys/) 和 [新旧凭据格式](https://developers.cloudflare.com/fundamentals/api/get-started/token-formats/)。

资源选择 Include → Specific zone，仅包含 SaaS 主域名及测试记录所在主域名；两者不同账号时分别添加 Token。仅管理业务域名 DNS 的账号只需 Zone Read 和 DNS Edit；SaaS 账号另需 SSL and Certificates Edit，自动源站 IP 功能还需该账号的 DNS Edit。额外回源或验证记录位于另一 Zone 时，也需包含对应 Zone。

先在 CF 开通 SaaS 并准备可用默认回源，再在面板添加账号、同步域名、自动读取 SaaS接入配置。真实联调使用独立安装和空数据库，不关闭带有模拟凭据 / 任务的 Demo 库的 preview_mode。Token / Global Key 只填入面板，不写入仓库、聊天或截图。

先做只读检查，确认 SaaS 站点、业务测试主域名、源站 IP / 回源预设和独立测试子域名后，再启动对应安装的 Worker。接入验证、临时 TXT 清理、入口 / 模式变更以及移除均记录云端对象归属；不把真实业务记录当作测试对象。验收结果另行记录，不能用模拟测试通过代替。

## 源站 IP 自动回源的条件

记录提供「关云 · 仅 DNS」「开小黄云」「优选」。前两种直接写客户 Zone 的源站 A / AAAA，分别关闭或开启代理；只有优选模式执行下面的 SaaS 回源接入。普通 DNS 记录保存后核对云端 DNS 内容和代理状态，不代表已验证网站 HTTPS 可用。Cloudflare 的代理语义参见[代理状态文档](https://developers.cloudflare.com/dns/proxy-status/)。

填写源站 IP 时，SaaS Token 还需能编辑 SaaS Zone 的 DNS。面板创建独立的橙云主机名作为 Custom Origin，客户侧 CNAME 指向自定义优选入口，入口再指向优选 CNAME / IP 组。自动回源与共享 fallback 分离，不会因为修改一条记录而改动其他域名。

Cloudflare 的独立回源要求有效的代理主机名。独立回源默认使用该回源主机名作为 SNI，Host 为业务域名；SNI 重写受账号能力限制。因此需核对源站的虚拟主机、证书及 TLS / SNI 配置，不能假定只填 IP 后任意源站都兼容。本项目不会关闭 TLS 验证或自动降低云端 SSL 安全等级；实际业务访问检查通过后才标记接入完成。相关条件见 [Cloudflare 独立回源说明](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/advanced-settings/custom-origin/)。

共享入口也需要其 Zone 的 DNS Edit 权限；如果入口使用第三个账号，在该账号同步 Zone 后选择。移除业务记录保留入口 DNS；在 CF 中手工清理前请核对其他使用者。

## 读取已有 SaaS

Web 添加 / 同步账号后，自动同步所有启用 CF 账号的 Zone 并开始读取；在「导入已有 SaaS」可重新读取或继续未完成批次。此页用于首次接管历史配置，日常在域名管理创建或编辑记录。业务 DNS Zone 可以与 SaaS 服务 Zone 不同，未配置 SaaS 默认回源时会显示「未配置（普通 DNS 不受影响）」，不需要为每个主域名都配置 SaaS。仅用于读取的 Token 可使用 Zone Read、DNS Read、SSL and Certificates Read；现有 DNS Edit / SSL Edit 权限也覆盖相应读取。Token 资源须包含 SaaS、业务 DNS、入口与回源涉及的 Zone。读取成功不代表已验证写权限，后续编辑仍需对应 Edit 权限。

读取、预览和纳管确认均不写 CF；确认重新 GET 核对快照，并创建本地 inspect 任务。正常安装需运行 Worker 才能完成只读访问检查。Demo 显示本地示例来源且禁止 Worker 执行，排队不等于真实 CF 已验收。已有源站、主机名和 TXT 归属保留；从其他服务器接管现有资源用读取 / 纳管，JSON 导入表示创建接入计划，不能代替原资源接管或完整灾备。
