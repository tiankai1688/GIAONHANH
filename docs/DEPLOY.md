# GIAONHANH — 生产部署指南（Phase A）

本指南把"后端从未运行"这一最大阻塞拆成可执行的步骤。沙箱无 PHP，以下产物只写不跑；在真实服务器（推荐越南节点：VNG Cloud / AWS ap-southeast-1）执行。

## 1. 产物清单（本仓库已包含）
- `docker-compose.yml` — app(Laravel) + mysql + redis 三件套
- `backend/Dockerfile` — PHP 8.2-fpm + nginx + supervisor（supervisor 管 php-fpm / nginx / queue worker）
- `backend/infra/nginx/default.conf` — 站点配置（含支付 IPN 路由、SSL 注释模板）
- `backend/infra/supervisor.conf` — 进程管理
- `backend/.dockerignore`
- `.github/workflows/ci.yml` — CI（契约校验 + Laravel 可构建性）
- `scripts/deploy.sh` / `scripts/settlement-cron.sh` — 一键部署 + 每日结算
- `backend/routes/api.php` 新增公开 `GET /api/health`（监控/健康检查）

## 2. 前置条件
- 一台 Linux 主机（2 vCPU / 4 GB 起），Docker + docker compose v2
- 一个域名 + SSL 证书（Let's Encrypt 免费）
- 越南支付对接（见第 6 节）所需的商户号 / 聚合方资质

## 3. 环境变量
```bash
cp backend/.env.example backend/.env
```
必须填写且影响上线安全：
- `APP_KEY`：部署脚本会自动 `php artisan key:generate`
- `APP_DEBUG=false`、`APP_ENV=production`
- `DB_*`：连 docker-compose 内 mysql（host 用 `mysql`）
- `ADMIN_SEED_PASSWORD`：**务必设强口令**，否则 admin 种子密码随机不可恢复
- `CORS_ALLOWED_ORIGINS`：填真实前端/App origins（Capacitor 用 `capacitor://localhost`）
- `SANCTUM_STATEFUL_DOMAINS`：填前端域名
- 支付：`MOMO_*` / `ZALOPAY_*` 或 `PAYMENT_AGGREGATOR=sepay|payoo`（聚合模式规避二清）

## 4. 部署
```bash
chmod +x scripts/deploy.sh scripts/settlement-cron.sh
./scripts/deploy.sh
```
脚本会：构建镜像 → 起容器 → 生成 key → `migrate --force` → `db:seed --force` → 清缓存 → 健康检查。

## 5. TLS / 反向代理（生产必做）
- 方案 A：在 `infra/nginx/default.conf` 启 `listen 443 ssl` 并挂载证书。
- 方案 B（推荐）：前置 Caddy / Traefik / 云 LB 做 TLS 终止，把 8080 暴露在内网。
- 配置 HTTP→HTTPS 重定向与 HSTS。

## 6. 每日结算（T+1）
部署脚本会打印 crontab 行；加入主机 cron：
```
0 2 * * * /path/to/scripts/settlement-cron.sh >> /var/log/giaonhanh-settlement.log 2>&1
```
`settlement:daily` 由 queue worker 异步处理打款任务（需后续补"真打款"接口，见路线图阶段 E）。

## 7. 监控
- 健康检查：`GET /api/health` → `{"ok":true,...}`（接 UptimeRobot / Grafana）
- 日志：容器 stdout 已被 supervisor 导向 stdout，用 `docker compose logs -f app` 或接 Loki/Papertrail
- 告警：支付 IPN 失败、队列积压、DB 连接异常

## 8. 回滚
- 代码回滚：`git checkout <prev>` → `./scripts/deploy.sh` 重建。
- 数据库：迁移不可逆项极少；重大结构变更前先 `mysqldump` 快照。
- 镜像：保留上一个 `giaonhanh-app:latest` 之前的 tag 以便回切。

## 9. 已知限制（与路线图对齐）
- 本沙箱未运行过 `migrate`，迁移正确性需在真机验证。
- 支付仍为桩 → 阶段 B 接真实 PSP。
- 结算"真打款（paid_at / 商户银行代付）"接口骨架待补（阶段 E）。
- CI 的契约校验对参数化路径有已知误报，目前仅作信息性输出。
