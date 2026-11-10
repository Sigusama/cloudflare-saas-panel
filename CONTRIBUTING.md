# 参与开发

先阅读 [README](README.md)、[需求](docs/REQUIREMENTS.md)与[技术方案](docs/TECHNICAL_DESIGN.md)。当前处于首版开发阶段，提交功能时同步更新实际实现状态。

## 本地环境

PHP 8.3+ 与 MySQL 8.0+。所需扩展为 pdo_mysql、curl、openssl、mbstring 和 intl。项目运行不依赖 Composer 安装包或前端打包。

Web 根目录使用 public，私有运行文件位于 storage。开发工具、临时数据库和测试输出放入 work，均不提交仓库。

## 测试

`tests/run.php` 使用真实 MySQL 和模拟 Cloudflare 客户端，不修改真实 DNS。测试前创建一个专用空数据库，名称必须以 `_test` 结尾，然后设置：

```sh
export TEST_DB_HOST=127.0.0.1
export TEST_DB_PORT=3306
export TEST_DB_NAME=cf_panel_test
export TEST_DB_USER=your_test_user
export TEST_DB_PASSWORD=your_test_password
php tests/run.php
```

PowerShell 使用 `$env:TEST_DB_NAME='cf_panel_test'` 等形式设置相同变量。测试不会删除已有表，重复运行请另建空测试库。每次安装测试的私有配置写入 work/test-storage-*。

`tests/http.php` 只用于一次性的隔离安装。它会创建配置、任务、日志和 API Key，不能对日常 Demo 或生产库运行。脚本要求显式设置 `TEST_HTTP_ISOLATED=1`，数据库名以 `_test` 或 `_preview` 结尾；已安装站点的 URL 必须与 `TEST_HTTP_URL` 一致。测试前会在私有 settings 写入 `preview_mode=1`，阻止 Worker 执行模拟凭据产生的任务，同时保留健康心跳。

先建立专用空数据库，再在两个终端使用相同的 `PANEL_STORAGE` 启动 Web 和测试；示例：

```sh
mkdir -p work/http-test/storage
export PANEL_STORAGE="$PWD/work/http-test/storage"
# 终端一（同一项目目录）：
php -S 127.0.0.1:18081 -t public public/router.php
# 终端二，设置相同的 PANEL_STORAGE 后：
export PANEL_TEST_WORK="$PWD/work/http-test"
export TEST_HTTP_ISOLATED=1
export TEST_HTTP_URL=http://127.0.0.1:18081
export TEST_INSTALL_DB_HOST=127.0.0.1
export TEST_INSTALL_DB_PORT=3306
export TEST_INSTALL_DB_NAME=cf_panel_http_test
export TEST_INSTALL_DB_USER=your_test_user
export TEST_INSTALL_DB_PASSWORD=your_test_password
php tests/http.php
```

保留该测试目录可重跑已安装检查；要重测安装，请使用另一套空库与私有目录。脚本退出时会尝试撤销本轮创建的测试 Key，但不删除审计与任务记录；结束后自行清理本轮测试库和目录。该隔离配置不应用于真实 DNS 联调。

有 Docker Engine、Compose 和 curl 的 Linux 环境执行 `sh tests/docker-smoke.sh`。脚本使用独立 Compose 项目、测试密码与随机端口，覆盖映射端口、空库安装、API、服务重启、全容器替换后数据库 / 管理员 / 凭据密文 / 应用密钥 / 安装锁保留、Worker 健康及集成测试；只清理本次创建的测试卷。测试登录信息保存在本次私有数据卷，供替换后的 Web 继续验收。CI 调用同一脚本，可在 Actions 中手动运行。

语法检查可使用 `php -l`；前端脚本使用 `node --check public/assets/app.js`。修改任务逻辑时重点检查 API 超时后的对账、TXT 归属、外部改动保护和回滚。

## 提交要求

- 新 DNS 服务商实现统一接口，不复制整套接入流程。
- 将云端对象状态与本地任务状态分开处理。
- 给有副作用的变更保留快照及审计日志。
- 不在配置示例、日志、截图或 Issue 中包含真实凭据。
- README 的功能状态与实际代码一致，未完成内容写入规划。
- 提交自己有权提供的代码；贡献按项目 AGPL-3.0-only 许可发布，保留版权和许可声明并注明修改，详见 [LICENSE](LICENSE) 和 [NOTICE](NOTICE)。
