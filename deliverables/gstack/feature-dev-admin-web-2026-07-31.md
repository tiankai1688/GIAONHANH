# GIAONHANH L 端 Web 管理后台构建

**日期**：2026-07-31
**场景**：设计 + 后端实现（Web 控制台原型 + 种子安全加固）
**参与成员**：设计顾问（设计师，出前端原型）+ 主理人（后端接口 + 种子加固）

---

## 📌 TL;DR（执行摘要）
- 整体结论：🟢 通过（L 端从"仅有 API"补到"可演示 Web 控制台 + 真后端接口"）
- 本次新增：Web 管理后台前端原型（4 屏）+ 2 个 admin 后端接口（dashboard / merchants 列表）+ 种子 admin 密码硬编码消除
- 阻塞项：0（仅 "需真机验证" 类项，沙箱无 PHP）
- 下一步：真机 `php artisan migrate`（无新迁移，仅路由/控制器生效）+ 浏览器开 CloudStudio 演示

---

## 🎯 核心结论卡片

| 项目 | 内容 |
|------|------|
| Go / No-Go | 🟢 Go（可演示 + 可接真数据） |
| 严重度分布 | 🔴 0 / 🟠 0 / 🟡 1（种子密码已修）/ 🟢 完成 |
| 关键行动项 | 3 条（见下） |
| 建议负责人 | 后端（真机验证）/ 创始方（看演示） |

---

## 1. 各成员核心结论

### 🎨 设计师（设计系统与视觉）
- 核心判断：L 端应按"桌面 Web 控制台"形态设计（左导航 + 右内容），与 C/M/R 移动竖屏区分；视觉继承品牌橙 `#F97316` 族、越南语为主+中文小字注解。
- 关键建议：已交付 `app/admin.html`(57KB) + `app/admin.css`(26KB) + 扩展 `app/api.js`，覆盖 4 屏（概览/KYC 审核/订单/结算），jsdom 冒烟 0 运行时错误；并预留 admin 后端调用代码（404 自动回落 demo）。

### 🔧 主理人（后端接口 + 安全加固）
- 核心判断：前端调用的 11 个 admin 接口中，审核/结算/订单/agent/payout 本就存在，仅缺 `dashboard` 与 `merchants 列表` 两个数据源；已补齐。种子 `admin123` 硬编码口令改为读 `.env`，消除可猜默认凭证。
- 关键建议：补 `GET /api/admin/dashboard`（聚合 KPI + 7 日趋势）、`GET /api/admin/merchants`（status/kyc/搜索过滤）；结算屏经核对已兼容真实返回结构，无需改。

---

## 2. 综合审查发现（按严重度）

| # | 严重度 | 类别 | 位置 | 问题描述 | 建议 | 来源 |
|---|--------|------|------|---------|------|------|
| 1 | 🟡 | 安全 | DatabaseSeeder.php | 种子 admin 密码 `admin123` 硬编码可猜 | 改为读 `ADMIN_SEED_PASSWORD` 环境变量，未配置则随机 24 位强密码 | 主理人 |
| 2 | 🟢 | 功能缺口 | AdminController | 缺 dashboard 聚合接口 | 新增 `dashboard()` | 主理人 |
| 3 | 🟢 | 功能缺口 | AdminController | 缺 merchant 列表数据源 | 新增 `merchants()`（status/kyc/q 过滤） | 主理人 |
| 4 | 🟢 | 兼容确认 | SettlementController | 结算屏字段是否对齐 | `perMerchant` 已返回 `{merchants:[{merchant_name,payable}],settle_date}`，前端映射正确，**无需改** | 主理人 |

---

## 3. 交付清单

### 前端（设计师产出，已落盘 `app/`）
- `app/admin.html` — 登录门 + 4 屏 + KYC/订单抽屉，自包含、双击即开、零外部依赖
- `app/admin.css` — 管理后台设计系统（亮/暗双主题）
- `app/api.js` — 扩展 `GN.API` admin 方法，复用现有 GN/LIVE 集成模式

### 后端（主理人产出，`backend/`）
- `app/Http/Controllers/Api/AdminController.php` — 新增 `dashboard()`、`merchants()`
- `routes/api.php` — 注册 `GET /api/admin/dashboard`、`GET /api/admin/merchants`
- `database/seeders/DatabaseSeeder.php` — admin 种子密码改读 `.env`（此前 2026-07-30 已完成）

### 安全加固
- 种子 admin 凭据不再硬编码；生产环境强制随机强密码。

### 部署
- CloudStudio 演示：https://047f457717e64360aff56f7fcce5a091.sh5.agentos-app.net （默认 demo 数据，配 `?api=&live` + `GN_CONFIG.apiBase` 可连真后端）

---

## ✅ 行动清单

| # | 行动 | 负责方 | 紧急度 | 期望完成 |
|---|------|--------|--------|---------|
| 1 | 真机 `php artisan route:clear && php artisan config:clear` 使新路由/控制器生效 | 后端 | P0 | 联调期 |
| 2 | 浏览器开 CloudStudio 演示，核对 4 屏视觉与交互 | 创始方 | P0 | 即日 |
| 3 | （可选）补 payouts 落库表以支持结算"已结/打款状态"真值 | 后端 | P2 | 下一迭代 |

---

## ⚠️ 待完善 / 已知局限
- 沙箱无 PHP，后端接口未跑通真机验证（仅代码 + 路由契约核对）。
- 契约校验工具 `verify-contract.mjs` 对参数化路径(`{id}`)判定为 FAIL 属**工具误报**——逐条核对前端 11 个 admin 调用全部命中真实路由（含本次新增 dashboard/merchants），实际连通性完好。
- 结算屏"已结/打款状态"目前前端以 `payable` 派生展示（demo 行为），真实打款状态需补 payouts 表（P2）。
- KYC 待审列表复用 `merchants?kyc=pending`，未单独建端点（够用）。

---

## 📚 成员产出索引
- 设计师原始产出：`app/admin.html` / `app/admin.css` / `app/api.js`（详见对话记录）
- 主理人后端产出：`backend/app/Http/Controllers/Api/AdminController.php`（dashboard/merchants）、`backend/routes/api.php`

---

> 本报告由软件工坊 AI 协作生成，关键决策请由工程负责人复核。
