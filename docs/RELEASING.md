# 源码打包与 GitHub 发布

当前候选版本为 `0.1.0-beta.17`，许可为 AGPL-3.0-only。请保留 LICENSE 与 NOTICE，并提供该版本完整对应源码及安装 / 构建说明；修改后通过网络提供服务时，在界面中向远程用户提供清晰的源码获取入口。运行自定义版本时，入口应对应实际运行代码及其修改。

## 干净的源码包

在项目根目录使用 PowerShell 7 执行：

```powershell
./scripts/package.ps1
```

输出到 `release/cloudflare-saas-panel-<VERSION>.zip`，同时生成 ZIP 的 SHA-256 和逐文件摘要。打包按目录与根文件白名单收集源码、文档、测试和 Docker 文件，文本统一为 UTF-8 / LF，以免 Windows 行尾影响 Linux 入口脚本。

源码包不含 work、release、私有 storage 内容、.env、数据库、备份、测试凭据、下载运行时或缓存。storage 只带忽略规则，首次安装时生成配置。发布目录已加入 Git 和 Docker 忽略规则。不要直接压缩整个开发文件夹。

## 首次上传

1. 在 GitHub 创建空仓库，建议名称 `cloudflare-saas-panel`。项目已有 LICENSE、README、忽略规则，不需要再由 GitHub 生成。
2. 解压源码包并在该干净目录初始化 Git；若使用现有 checkout，先核对 `git status` 和暂存文件。不要上传 work 中的数据库及配置备份。
3. 添加实际仓库地址并推送。命令中的 `YOUR_REPOSITORY_URL` 需替换为自己创建的仓库 URL：

```sh
git init
git branch -M main
git add .
git diff --cached --stat
git commit -m "Prepare 0.1.0-beta.17"
git remote add origin YOUR_REPOSITORY_URL
git push -u origin main
```

仓库简介建议：基于 PHP + MySQL 的 Cloudflare for SaaS 自动接入面板，支持自动 DNS / TXT 验证、优选入口、多回源及批量操作。

建议主题：`cloudflare`、`cloudflare-for-saas`、`php`、`mysql`、`dns`、`docker`。在仓库设置启用私密漏洞报告，安全反馈方式见 SECURITY.md。

## 发布检查

- 隔离 Linux Docker Engine 中完成镜像构建、169 项首次安装 HTTP / API、167 项重启 HTTP、167 项容器重建 HTTP 与 413 项 MySQL / 模拟 CF 集成检查，完整结果见[验收记录](DOCKER_VALIDATION.md)。
- 上传前使用实际待发布源码运行 `sh tests/docker-smoke.sh`。脚本同时检查映射端口、服务重启、全容器替换后的两个数据卷、私有配置及后台心跳。GitHub Actions 执行相同脚本，并支持手动触发；首次远程运行仍需上传后确认。
- beta.16 已用两个授权测试子域名完成真实 CF 新增、编辑、删除、三种模式、批量操作与 SaaS / TXT 验证，测试资源清理后全部原有记录未变。其他账号、优选 IP 组及源站 TLS / SNI 组合按实际部署再验证。
- 核对 README、VERSION、CHANGELOG、API、许可证及截图一致，ZIP 内容无私有数据。
- 首次发布维持 **Pre-release**，说明实际通过的环境及组合，避免将有限验收解释为全部环境均兼容。

准备完成不代表已发布。当前目录整理和源码打包均为本地操作；创建远程仓库、推送、打标签及创建 Release 是后续发布操作。
