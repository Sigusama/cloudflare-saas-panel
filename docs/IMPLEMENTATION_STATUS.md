# 验证状态与后续规划

更新日期：2026-11-10。当前源码版本：`0.1.0-beta.19`，尚未发布稳定版本。发行包以 [GitHub Releases](https://github.com/Sigusama/cloudflare-saas-panel/releases) 为准。

## 验证范围

| 版本与环境 | 已记录的结果 | 范围 |
| --- | --- | --- |
| beta.18 Linux Docker | 436 项集成、182 / 180 / 180 项首次安装 / 重启 / 重建 HTTP 检查通过 | 真实 MySQL、模拟 Cloudflare，含账号筛选、多语言与参数校验 |
| beta.17 Linux Docker / GitHub Actions | 413 项集成、169 / 167 / 167 项安装 / 重启 / 重建 HTTP 检查通过 | 隔离 Compose 项目，含数据持久化与 Worker 心跳 |
| beta.16 真实 Cloudflare | 两个独立测试子域名完成新增、编辑、删除、三模式互切、批量操作与 SaaS / TXT 验证 | 仅覆盖指定账号、CNAME 优选目标及 IP / CNAME 回源组合 |

以上为各版本已记录的结果。当前提交的远程检查以 [GitHub Actions](https://github.com/Sigusama/cloudflare-saas-panel/actions/workflows/ci.yml) 为准；模拟集成通过不代表任意真实账号和源站都已验收。

## beta.19 多语言复核

- 修复浏览器翻译字典的未知文本回退，内置对象属性名保持原文；字典使用无原型对象，避免误读继承属性。
- 44 项浏览器翻译检查通过，覆盖中英文、切换记忆、未知文本、HTML 与插值；20 项 PHP 语言检查覆盖 Cookie 优先、语言权重、同权顺序和默认回退。
- 独立 MySQL 实例通过 440 项集成、182 项首次安装和 180 项重启 HTTP 检查，模拟安装的 Worker 心跳正常。云端操作仅使用模拟客户端。
- 中英文 README 补齐统一导航、截图、功能、接入流程、部署与许可说明；开发源码为 beta.19，默认安装仍使用已发布的 beta.18 镜像。

## beta.18 账号来源与主域名选择

- 概览和记录页共用全部账号 / 指定账号筛选、主域名快捷切换；全部账号时按账号分组展示各自主域名，空账号不显示其他账号的域名。
- 切换账号清空批量选择；切换主域名重置记录搜索与分页，前进 / 后退自动对齐来源账号。轮询保留未变化的选项与选择。
- `GET /api/v1/zones` 支持 `account_id` 正整数筛选；省略时读取全部账号，未知或已删除账号返回空列表，错误输入返回 422。停用账号仍显示状态，原有写入权限与归属校验保持有效。
- 新增集成和 HTTP 检查覆盖不同账号的独立主域名、未知 / 停用 / 删除账号与非法参数；HTTP 检查使用独立只读测试 Key，保留原限流行为。
- 已记录浏览器验证：不同账号的主域名选择、账号切换、清空选择、空账号与返回导航；控制台无 error / warn，478px 页面无整体横向溢出。示例见 [账号来源筛选](images/account-source-filter.jpg)，仅含隔离测试数据。
- 无新增数据库迁移；Docker 默认直接拉取 GHCR 版本镜像，源码构建通过独立覆盖配置启用。
- 新增简体中文 / English，包含浏览器偏好、Cookie 记忆、动态错误和用户值保留；16 项 PHP 语言检查及 12 项浏览器翻译检查通过。安装、登录、DNS 编辑弹窗和语言恢复已完成浏览器验证。

<a id="docker"></a>

## Docker 验收

beta.17 在隔离 Linux amd64 环境完成实际 Docker Engine 构建与安装。历史远程验收为 [Actions #42764378119](https://github.com/Sigusama/cloudflare-saas-panel/actions/runs/42764378119)，提交 `5da8656`；后续提交查看各自工作流结果。

| 组件 | 当次实测版本 / 配置 |
| --- | --- |
| 系统 | Alpine Linux 3.24.4，Linux amd64 |
| Docker Engine / Compose | 29.9.3 / 5.6.1 |
| PHP / MySQL | 8.4.27 / 8.4.13 |
| 服务 | Web、MySQL、www-data 身份的 Worker |

验收包括从源码构建镜像与扩展、健康依赖与首次安装、随机映射端口、API、Web / Worker 重启及全部容器重建。普通 `compose down` 后保留两个卷，数据库、管理员、凭据密文、应用密钥、安装锁与设置摘要一致，Worker 心跳恢复。

`tests/docker-state.php` 只读生成私有配置及业务数据摘要，排除正常变化的 Worker 心跳，不输出凭据。测试安装在生成模拟任务前设置 `preview_mode=1`，集成套件驱动自己的模拟 Worker。测试结束只清理本次 Compose 项目、网络和卷。

## 真实 Cloudflare 验证

- beta.16 使用两个独立测试子域名，验证原有 A / CNAME 编辑与精确删除、混合批量开关云、优选转换及回滚、批量删除。
- 两个 SaaS 主机名与证书达到 active，IP / CNAME 独立回源通过 HTTPS 检查。验证期间保留原解析，本轮创建的临时 TXT 按自有 ID 清理，无关 TXT 保留。
- 测试结束移除本轮自有业务 DNS、SaaS 和回源别名；重新读取确认原有 DNS 与主机名保持不变。
- 测试源站显式接受部分 403 / 404 状态，TLS 校验和 Cloudflare 5xx 检查保持开启；该结果不代表所有源站的 TLS / SNI 配置均兼容。

## 复现与限制

具备 Linux Docker Engine、Compose 和 curl 的环境执行：

```sh
sh tests/docker-smoke.sh
```

脚本在一次性项目中覆盖构建、安装、API、重启 / 重建持久化、Worker 与 MySQL 集成检查。不要将测试脚本的卷清理步骤用于日常部署。本地隔离数据库与 HTTP 测试方法见 [贡献指南](../CONTRIBUTING.md)，安装和备份见 [部署说明](DEPLOYMENT.md)。

尚未覆盖全部账号权限、优选 IP 组、源站 TLS / SNI 组合及 ARM 等其他架构。实际部署应使用自己的测试域名确认兼容性；配置保存、任务排队与真实业务接入成功分别显示。

## 后续规划

DNSPod、委托 DCV、多地区测速、自动线路切换、通知与日志长期归档仍为后续范围。当前 Worker 采用互斥的单步骤执行，尚未提供并发调度、自动测速或故障切换。
