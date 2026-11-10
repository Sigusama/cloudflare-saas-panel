# 安全问题反馈

当前维护版本为 `0.1.0-beta.17`。已完成指定测试域名的真实 Cloudflare 联调及隔离 Linux Docker 安装检查，具体环境与范围见 [开发进度](docs/IMPLEMENTATION_STATUS.md)。

发现凭据泄露、越权、任意文件访问或其他安全问题时，请优先使用项目 GitHub 仓库的 **Security → Report a vulnerability** 私密报告入口；该入口由仓库维护者在发布时启用。若入口不可用，请联系 [维护者](https://github.com/Sigusama) 获取私密反馈渠道，公开 Issue 只说明需要私密联系，不公布利用细节。

报告可包含版本、部署方式、影响范围、最小复现步骤和脱敏日志。不要附带真实 Cloudflare Token、API Key、密码、Cookie、storage/config.php 或数据库备份。泄露的凭据应在对应服务撤销并重新生成。

部署时将 Web 根目录限制为 public，单独保管数据库与 storage 备份，并定期升级 PHP、MySQL 及容器基础镜像。
