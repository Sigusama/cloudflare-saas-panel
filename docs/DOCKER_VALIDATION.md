# Docker 安装验收

日期：2026-11-10。版本：`0.1.0-beta.17`。

GitHub 首轮远程验收：[Actions #42764378119](https://github.com/Sigusama/cloudflare-saas-panel/actions/runs/42764378119) 已成功，提交为 `5da8656`。远程同样通过 169 / 167 / 167 项 HTTP 和 413 项集成检查。

在隔离 Linux 虚拟机内使用实际 Docker Engine 运行干净源码包。未挂载日常 Demo、真实 Cloudflare 安装的数据库或 storage，测试只使用模拟凭据和独立 Compose 项目。

## 实测环境

| 组件 | 版本 / 配置 |
| --- | --- |
| 系统 | Alpine Linux 3.24.4，Linux amd64 |
| Docker Engine | 29.9.3，cgroup v2 |
| Docker Compose | 5.6.1 |
| PHP | 8.4.27，官方 `php:8.4-apache` 构建 |
| MySQL | 8.4.13，官方 `mysql:8.4` |
| 服务 | Web、MySQL、www-data 身份的 Worker |

## 结果

| 检查 | 结果 |
| --- | --- |
| 从源码构建 PHP / Apache 镜像及扩展 | 通过 |
| MySQL → Web → Worker 健康依赖与首次等待安装 | 通过 |
| Docker 主机访问随机映射的 Web 端口 | 通过 |
| 空库 Web 安装、登录、鉴权、公开 API、DNS 操作等 HTTP 检查 | 169 项通过 |
| Web / Worker 重启、应用密钥保持与心跳恢复 | 通过；167 项 HTTP 检查通过 |
| 普通 `compose down` 后保留卷，重新创建全部三个容器 | 通过；167 项 HTTP 检查通过 |
| 重建前后数据库、管理员、凭据密文、应用密钥、安装锁 | 摘要一致 |
| MySQL 数据库配合模拟 CF 的集成检查 | 413 项通过 |
| 测试结束的容器、网络、数据卷清理 | 只清理本次项目，未遗留测试卷 |

`tests/docker-state.php` 只读生成配置、安装锁、各业务表及设置的综合摘要，排除正常更新的 Worker 心跳。检查前要求专用测试库和 `preview_mode=1`，不会输出管理员密码、云端凭据或应用密钥。

HTTP 验收在写入模拟配置前禁用该测试安装的云端执行。集成套件驱动自己的模拟 Worker，开始前停止测试安装的常驻 Worker，避免竞争 MySQL 操作锁。CLI 会话检查使用输出缓冲，确保异安装会话测试实际创建 PHP 会话。

## 复现

在具备 Docker Engine、Compose 和 curl 的 Linux Docker 主机执行：

```sh
sh tests/docker-smoke.sh
```

脚本自动创建专用项目、环境文件和两个数据卷；成功或失败均清理本次容器和卷，不要将其改为生产项目名称。脚本在失败时显示尾部容器日志，仅可用于一次性隔离安装。

正常安装和升级见[部署说明](DEPLOYMENT.md)。正常安装的数据库和 storage 需要保留，普通 `docker compose down` 不删除卷；测试脚本末尾的卷清理步骤不适用于日常部署。

GitHub Actions 调用同一脚本，支持 push、pull request 和手动触发，结果见[仓库工作流](https://github.com/Sigusama/cloudflare-saas-panel/actions/workflows/ci.yml)。本轮验证覆盖上述 Linux amd64 环境，不代表已测试 ARM 或所有源站配置。真实 Cloudflare 测试结果另见[开发进度](IMPLEMENTATION_STATUS.md)。
