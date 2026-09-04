# GIAONHANH 越南小时达 · 合并去重 + 全链路审核修复交付报告

**日期**：2026-07-29
**场景**：全流程交付（合并去重 → 安全/质量审核 → 多角色测试 → 修复 → 源码交付）
**参与成员**：主理人直落（ardot-design-core HARD RULE 优先：本任务由主 agent 直接执行审核与修复，未启用 5 人子代理团队；设计稿与前端原型经 Ardot 工具闭环）

---

## 📌 TL;DR（执行摘要）

- 整体结论：🟢 通过（合并去重完成 + 9 项审核发现已修复/证伪 7 项 + 契约 PASS）
- 阻塞项数量：0（#9 token 轮换为可选的后续加固项，非阻塞）
- 合并去重：把"当前 Ardot 设计轮次 + 13 天前决策节点"两套成果合并，消除 android assets 与 `app/` 的版本差（旧 assets 缺 P0 合并下单/结算/推送/媒体查询）
- 审核修复：登录缺密码(🔴)、骑手 busy 可重复接单(🔴)、无限流(🟠)、register 未拒 role(🟠)、password 可为 NULL(🟡)、骑手送达无位置校验(🟡) 全部修复；商品归属(#3)与取消退款(#4)经核实代码已实现，为误报
- 契约校验：`node tools/verify-contract.mjs` → **PASS**（前端 30 / 后端 47 端点，含 merged + settlement；无重复键）
- 源码：完整可交付源码已在工作区树内（`app/` 前端原型 + `backend/` Laravel 11 后端 + `mobile/` Capacitor 工程 + 设计稿），无需额外打包

---

## 🎯 核心结论卡片

| 项目 | 内容 |
|------|------|
| Go / No-Go | 🟢 Go（可直接进入人工接手编写 / 部署联调） |
| 严重度分布 | 🔴 2 已修 / 🟠 3 已修 / 🟡 2 已修 / 🟢 2 误报证伪 |
| 关键行动项 | 6 条（见行动清单） |
| 建议负责人 | 后端（Laravel）/ 移动端（Capacitor）/ 人工接手工程师 |

---

## 1. 合并去重说明（两套成果合一）

用户红框内两个 Ardot 节点 = **当前设计轮次** + **13 天前决策节点**。合并策略：

1. **设计稿（Ardot）**：以当前轮次为基准，沿用 13 天前已定稿的「四端 31 屏 + 1 总览板」结构（C8 消费者 + M9 商家 + R9/R10 骑手 + L4 管理后台 + 4:827 总览板），剔除重复屏，保留杀手锏链路 C9/C10/C11（跨店合并购物车/支付/订单）。
2. **前端原型（`app/`）**：以 `app/` 为唯一真源，将 android assets（`mobile/android/app/src/main/assets/public/`）与之对齐——旧 assets 落后 14 天，缺 `createMergedOrder`/`settlement`/`deviceToken`/媒体查询。已用 `cp` 同步 9 个文件（api.js / index.html / merchant.html / rider.html / pay-demo.html / payment.js / native-bridge.js / settings-overlay.js / merged-demo.html）。
3. **后端源码（`backend/`）**：沿用已完成的 P0 跨店合并下单全链路（迁移/模型/分账/控制器/路由/资源）+ T+1 子单分账结算，作为合并后的后端真源。

> 去重结果：无重复代码文件；`app/` 与 android assets 内容一致；后端单一真源在 `backend/`。

---

## 2. 多角色测试矩阵（按角色走查 + 契约校验）

> 沙箱无 PHP/Composer，无法起 Laravel 实跑；采用「逐角色控制器静态走查 + 前端契约校验 + 边界用例推演」。

| 角色 | 走查入口 | 测试结论 | 发现 |
|------|---------|---------|------|
| 🛒 消费者 | AuthController(login/register) · OrderController(store/storeMerged/pay/cancel) · PaymentController | 登录/注册/下单/合并下单/支付/取消退款链路完整；合并下单商品归属已校验 | #1 登录缺 password（🔴，已修） |
| 🏪 商家 | MerchantController · OrderController(merchantReady) · SettlementController | 接单/备货/子单分账结算链路完整 | 无新增阻塞 |
| 🛵 骑手 | RiderController(nearby/accept/current/deliver/profile) | busy 守卫、送达邻近校验已加；进行中单 `current` 正常 | #2 busy 重复接单（🔴，已修）· #8 送达无位置校验（🟡，已修） |
| 🛡 管理员 | AdminController · SettlementController(admin) | 商家审核/代理审核/结算/对账接口完备 | #5 无限流（🟠，已修） |

### 逻辑性验证（关键不变量）
- 合并下单：`storeMerged` 已校验子单 item 仅属该店（L116-118）→ #3 商品归属误报，证伪。
- 取消退款：`cancel` 已对 paid 订单触发退款 + 释放库存（L236-257）→ #4 取消退款误报，证伪。
- 支付安全：MoMo/ZaloPay/聚合器 HMAC-SHA256 验签、fail-closed（无密钥不验证）、金额绑定、`extraData.order_no` 防订单替换、`Cache::add` 防重放、幂等均已实现。

---

## 3. 审核发现与修复（9 项，去重合并）

| # | 严重度 | 类别 | 位置 | 问题描述 | 修复 | 状态 |
|---|--------|------|------|---------|------|------|
| 1 | 🔴 | 功能/安全 | `app/api.js` · `AuthController` | 前端 `login` 未传 `password` → 后端 422 无法登录 | `login(phone,password)` 补齐；`demoLoginAs` 加默认 `password='demo123'` | ✅ 已修 |
| 2 | 🔴 | 并发/业务 | `RiderController::accept` | 骑手 `busy` 状态无守卫，可重复接单导致订单被多骑手抢占 | 加 `! $rider` 404 + `status==='busy'` 返回 409 | ✅ 已修 |
| 3 | 🔴→🟢 | 安全 | `OrderController::storeMerged` | 疑：合并下单商品可跨店归属 | 核实 L116-118 已实现 `item.merchant_id === $m->id` 校验 | ✅ 误报证伪 |
| 4 | 🟠→🟢 | 资金 | `OrderController::cancel` | 疑：取消不退款/不释放库存 | 核实 L236-257 已退款 + 库存回滚 | ✅ 误报证伪 |
| 5 | 🟠 | 安全/抗压 | `bootstrap/app.php` · `routes/api.php` | auth 登录/注册、IPN webhook 无限流 | 加 `auth:60,1` / `ipn:120,1` limiter 并应用到路由 | ✅ 已修 |
| 6 | 🟠 | 安全 | `AuthController::register` | 未强制 `password` 必填，且未拒绝客户端传入 `role` | validate 加 `password min:6`；`unset($data['role'])` 防御性拒绝 | ✅ 已修 |
| 7 | 🟡 | 数据完整 | `users` 表 · 迁移 | `password` 可 NULL，存在空密码登录风险 | 新建迁移：先把 NULL 置随机 bcrypt，再 `nullable(false)` | ✅ 已修 |
| 8 | 🟡 | 反欺诈 | `RiderController::deliver` | 送达无位置校验，可虚假完成 | 接收可选 `lat/lng`，与订单目的地距离 >0.3km 拒绝(422)；前端 `riderDeliver` 同步携带坐标 | ✅ 已修 |
| 9 | 🟡 | 安全(可选) | `Sanctum` token | 无 refresh/rotation 机制 | 标记为后续加固项（需 refresh-token 表 + 轮换逻辑），非阻塞 | ⏳ 建议 |

---

## 4. 交付清单（代码变更 + 测试覆盖 + 发布检查 + 回滚）

### 代码变更（本次）
- `app/api.js`：`login`/`demoLoginAs` 补密码；`riderDeliver` 支持携带 GPS 坐标（向后兼容）
- `mobile/android/app/src/main/assets/public/api.js`：同步上述前端改动
- `backend/app/Http/Controllers/Api/RiderController.php`：`accept` 加 busy 守卫；`deliver` 加邻近校验
- `backend/app/Http/Controllers/Api/AuthController.php`：`register` 强制密码 + 拒绝 role
- `backend/bootstrap/app.php`：注册 `auth`/`ipn` 限流别名
- `backend/routes/api.php`：登录/注册/`*ipn` 应用限流中间件
- `backend/database/migrations/2026_07_30_000000_enforce_user_password_not_null.php`：新建（password NOT NULL 加固）

### 测试覆盖
- 契约：`node tools/verify-contract.mjs` → PASS（前端 30 / 后端 47）
- 支付验签：`tools/verify-ipn.mjs`（Node 端到端）+ `backend/scripts/verify-ipn.php`（生产冒烟）
- 角色走查：见第 2 节矩阵

### 发布检查清单
- [x] 前端与 android assets 一致（已 cp 同步）
- [x] 前后端契约 PASS
- [x] 登录/注册/接单/送达 关键路径修复落地
- [ ] 人工接手后：`php artisan migrate` 执行密码约束迁移（沙箱无 PHP，需在真机/CI 跑）
- [ ] 真机联调：骑手送达 GPS 邻近校验实测

### 回滚预案
- 全部为增量修改，回滚即 `git revert` 对应提交；密码迁移 `down()` 已提供 `nullable()` 回退。
- 前端 `riderDeliver` 坐标携带为可选，旧客户端不传坐标亦不破坏。

---

## 5. 源码获取与目录结构

完整可交付源码即本工作区树（无需额外打包）：

```
小时达软件开发/
├── index.html              # 招商落地页
├── app/                    # 消费者端 APP 原型（高保真、自包含、可离线）
│   ├── index.html  merchant.html  rider.html  pay-demo.html  merged-demo.html
│   └── api.js  payment.js  native-bridge.js  settings-overlay.js
├── backend/                # Laravel 11 API（drop-in 覆盖到 fresh Laravel）
│   ├── app/Http/Controllers/Api/   # Auth/Merchant/Order/Rider/Payment/Admin/Settlement
│   ├── app/Services/              # PaymentGatewayService / PaymentSplitService / MerchantSettlementService
│   ├── database/migrations/       # 含本次新增 password NOT NULL 迁移
│   └── routes/api.php
├── mobile/                 # Capacitor 6 工程（android/ 已生成；ios/ 需 Mac）
│   └── android/app/src/main/assets/public/   # 已与 app/ 同步的 9 个文件
├── docs/  tools/  deliverables/
```

> 后续人工编写时，直接在此树上改；`backend/` 为 Laravel drop-in，`app/` 为前端真源，`mobile/` 用 `node copy-web.js` 同步 web 资源（本环境 `npm run build` 不复制）。

---

## 6. 部署指南（要点）

1. 后端：`composer install` → `.env` 配置 DB / Sanctum / 支付密钥（MoMo/ZaloPay/聚合器）→ `php artisan migrate`
2. 前端：`app/` 直接用 `Live` 集成模式；`GN_CONFIG.apiBase` 为空则离线降级保留演示数据
3. 移动端：`mobile/` 下 `npx cap sync android`（iOS 需 Mac 生成 `ios/`）
4. 支付合规：平台不碰资金，经持牌聚合（Sepay/Payoo）规避二清；详见 `backend/docs/PAYMENT_COMPLIANCE.md`

---

## ✅ 行动清单（后续接手必做）

| # | 行动 | 负责方 | 紧急度 | 期望完成 |
|---|------|--------|--------|---------|
| 1 | 真机执行 `php artisan migrate`，落地密码约束迁移并验证现有账号可登录 | 后端 | P0 | 部署前 |
| 2 | 骑手送达 GPS 邻近校验真机实测（0.3km 门禁是否过严/过松，按越南城区调整） | 移动端 | P1 | 联调期 |
| 3 | 引入 token refresh/rotation 机制（#9），降低长期 token 泄露风险 | 后端 | P2 | 下一迭代 |
| 4 | 把 android assets 同步纳入 CI（避免再出现 14 天版本漂移） | 工程 | P2 | 持续集成 |
| 5 | 注册接口补充邮箱/手机验证码或 OAuth，降低批量注册风险 | 后端 | P2 | 下一迭代 |
| 6 | 人工接手后逐屏核对 Ardot 设计稿与 `merged-demo.html` 视觉一致性 | 设计/前端 | P1 | 接手首周 |

---

## ⚠️ 待完善 / 已知局限

- 沙箱无 PHP/Composer，Laravel 仅能写源码未实跑；所有后端修复基于静态走查 + 契约校验，需在真机/CI 跑 migrate 与功能测试。
- #9 token 轮换未实现，列为后续加固。
- iOS 工程未在本次生成（需 Mac）；当前仅 android 资源已同步。
- 合并去重基于两份 Ardot 节点的可见内容推断，若用户原始红框含未读图层需复核。

---

## 📚 成员产出索引

- 主理人直落（ardot-design-core HARD RULE 优先，未启 5 人子代理）：合并去重决策 + 9 项审核 + 多角色走查 + 修复 + 契约校验 + 本报告
- 参考原始产出：Ardot 设计稿节点 4:827 总览板、C9/C10/C11 合并下单三屏、M9/R9/R10 商家与骑手合并单屏；`tools/verify-contract.mjs` PASS 输出；`backend/docs/PAYMENT_COMPLIANCE.md`

---

> 本报告由软件工坊 AI 协作生成，关键决策请由工程负责人复核。源码已在 `app/` `backend/` `mobile/` 三棵树内，可直接交人工接手编写。
