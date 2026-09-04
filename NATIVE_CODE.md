# GIAONHANH 原生代码交付包（NATIVE CODE DELIVERY）

> 本目录为 GIAONHANH 越南小时达平台的**原生代码（前端 SPA + Laravel 11 后端 + 基础设施即代码）**交付包。
> 后续可直接交付客户。标注「原生代码」即代表该部分已开发完成、可独立运行 / 部署。

## 技术栈
- 前端：Capacitor 静态 SPA（`app/*.html` + `api.js`），VI+ZH 双语，货币 ₫
- 后端：Laravel 11 / PHP 8.2 / Sanctum 4 / SQLite（开发）；MySQL + Redis（生产）
- 基础设施：supervisor + nginx + docker-compose + GitHub Actions CI

## 目录结构
- `app/` 前端 SPA（C 消费者 + R 骑手）
- `admin.html` / `merchant-web.html` 商家 + 管理桌面 Web 控制台
- `index.html` 招商落地页
- `backend/` Laravel API（routes / app / config / tests）
- `tools/` 契约校验、压测、smoke
- `docs/` 方案、BP、就绪清单、runbook、三阶段路线图
- `docker-compose.yml` / `backend/infra/` 部署

## 原生代码交付状态（三色）
- 🟢 **已完成，可直接交付**：支付与合规（接口/验签/对账/PSP 费用入账）、订单/库存/结算（含跨店合并、0 佣金配置化）、安全基线（HttpOnly 刷新令牌/CSP 哈希/PII 加密/OTP 门控）、前端 SPA 全屏（46 顶层屏）、CI 配置 + 压测脚本 + 融资文档。
- 🟡 **待你（客户）个人提供**：PSP 生产密钥、短信网关签约、上架账号（Google Play / TestFlight）、出款 API 凭证、营收模型决策、push 触发 CI、录真机视频。
- 🔴 **工程待做（尚未构建）**：原生壳出包、半自动调度引擎、API 网关 + WAF + CDN、可观测性、回滚机制、数据分片/Redis 集群、ML 调度、数据本地化 + DR、热路径服务拆分。

## 运行 / 部署
- 本地：`docker compose up -d` → `API_BASE=... node tools/backend-smoke.mjs`
- CI：`git push` 触发 `ci.yml`（contract / laravel / pest / backend-smoke）
- 压测：`API_BASE=... DURATION=30 VUS=20 node tools/load-test.mjs`

## 版本 / 状态
- 当前：第一阶段工程完成（2026-08-02），仅余你侧 🟡 动作（push 触发 CI + 录真机视频 + 定营收模型）。
- 三阶段进度详见 `docs/三阶段交付路线图.md`（🟢 绿=已完成，🟡 黄=待你个人人为完成，🔴 红=工程待做）。
- 本交付包内 🟢 部分为可直接交付客户的原生代码。
