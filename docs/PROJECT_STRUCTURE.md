# 项目目录规划

```text
cloudflare-saas-panel/
├── README.md
├── VERSION                       应用版本唯一来源
├── CHANGELOG.md                  版本记录
├── LICENSE                       GNU AGPLv3 许可全文
├── NOTICE                        项目出处与署名
├── SECURITY.md                   安全反馈
├── .env.example                 Docker 参数示例，不保存真实凭据
├── compose.yaml                 Web / MySQL / Worker
├── Dockerfile
├── app/
│   ├── bootstrap.php             自动加载与启动
│   ├── Core/                     配置、数据库、加密、校验及错误处理
│   ├── Auth/                     会话、API Key、权限、限流和幂等
│   ├── Cloudflare/               Cloudflare SaaS API 客户端
│   ├── Dns/                      DNS 适配接口与 Cloudflare 实现
│   ├── Services/                 安装、配置、接入、诊断和审计服务
│   │   ├── DomainConfig.php      生效快照与检查选项
│   │   ├── DomainChange.php      切换、失败恢复与按次回滚
│   │   ├── Configuration.php     配置生命周期与引用保护
│   │   ├── SaasInventory.php     分页读取、恢复游标与扫描结果
│   │   ├── InventoryLinks.php    跨账号 DNS / SaaS 关联及判断
│   │   ├── SaasAdoption.php      纳管预览、云端复核与外部归属
│   │   ├── AdoptedDomain.php     已有域名的只读检查
│   │   ├── ObservedDns.php       已观察 DNS 基线与规范化比较
│   │   ├── SaasDiscovery.php     从 CF 只读获取默认回源与标准 CNAME 候选
│   │   ├── ZoneRecords.php       按主域名查询与记录编辑入口
│   │   ├── BulkRecords.php       批量预览、逐项校验与任务提交
│   │   ├── ManagedOrigin.php     每条记录的 IP / CNAME 源站与代理回源
│   │   ├── EntryAlias.php        自定义优选入口、共享 DNS 计划与冲突保护
│   │   ├── DnsMode.php           关云、代理及优选的 DNS 目标与核对
│   │   ├── SaasProvision.php     普通 DNS 首次切入优选的 SaaS 准备
│   │   ├── Transfer.php          可移植配置及记录导入导出
│   │   └── Settings.php          新任务默认配置
│   ├── Jobs/                     后台执行、状态推进及维护任务
│   └── Http/                     路由与请求处理
├── bin/
│   └── console.php               安装口令、迁移、Worker 与环境检查
├── database/
│   └── migrations/               编号 SQL 迁移
├── public/                       唯一 Web 根目录
│   ├── index.php                 应用入口
│   ├── router.php                PHP 内置服务器开发路由
│   ├── .htaccess                 Apache 重写规则
│   └── assets/                   CSS / JavaScript / 本地静态资源
├── resources/
│   └── views/                    安装、登录与管理界面模板
├── docker/                       Apache 配置与容器入口
├── storage/                      私有持久目录，内容不提交
│   ├── config.php                安装生成的数据库参数与应用密钥
│   ├── installed.lock            安装完成标识
│   └── logs/                     脱敏运行日志
├── tests/                        PHP 集成、HTTP 与云端替身
│   ├── docker-smoke.sh           隔离 Docker 安装与持久化验收
│   └── docker-state.php          重建前后配置、安装锁及数据摘要
├── scripts/package.ps1           白名单源码打包与摘要
├── .github/workflows/ci.yml      Docker 验收工作流
├── docs/
│   ├── REQUIREMENTS.md
│   ├── TECHNICAL_DESIGN.md
│   ├── PROJECT_STRUCTURE.md
│   ├── DEPLOYMENT.md
│   ├── API.md
│   ├── IMPLEMENTATION_STATUS.md
│   ├── DOCKER_VALIDATION.md
│   └── RELEASING.md
├── release/                      本地源码包与摘要，不进入仓库或镜像
└── work/                         本地工具与临时文件，不进入公开仓库
```

这是目标结构；开发时按实际功能创建文件，不用空文件假装模块已实现。没有 public 之外目录的静态访问路由。测试替身不能通过生产环境变量任意切换 API 到不受信任地址。

业务服务不读取具体 Web 表单，API 和页面通过同一服务调用。Cloudflare for SaaS 管理与客户 DNS 操作分离，未来 DNSPod 只新增 DNS 适配，不复制整套接入状态机。
