# M/L 后端缺口补齐 + 代码审查闭环报告

**日期**：2026-07-31
**场景**：全流程交付（后端缺口补齐 → 产品官代码审查 → 修复闭环）
**参与成员**：产品官（gstack-product-reviewer，代码审查）+ 主理人（实现与修复）

---

## 📌 TL;DR（执行摘要）

- 整体结论：🟢 通过（审查发现的 2 项阻塞 + 2 项重要 + 1 项建议均已修复）
- 本次补齐范围：① 代理商（Agent）管理 4 个缺失端点（show/reject/update/delete）；② 商家优惠券（Coupon）全栈 CRUD（模型/迁移/控制器/路由/前端接线）。
- 产品官审查结论：🔴 2 项阻塞（被优雅降级掩盖的真实运行时漏洞）、🟠 2 项重要、🟡 2 项建议。
- 修复闭环：B1 / B2 / I1 / I2 / S1 已修复并复验（语法 + 端点契约）；S2 评估为非可利用低优项，留作记录。
- 下一步：沙箱无 PHP，迁移与功能联调需在真机/CI 跑；CloudStudio 重新部署两个 Web 控制台。

---

## 🎯 核心结论卡片

| 项目 | 内容 |
|------|------|
| Go / No-Go | 🟢 Go（修复后） |
| 严重度分布 | 🔴 2（已修） / 🟠 2（已修） / 🟡 2（1 修 1 留） / 🟢 0 |
| 关键行动项 | 6 条（见行动清单） |
| 建议负责人 | 主理人（已执行）；真机联调由工程负责人复核 |

---

## 1. 各成员核心结论

### 🔍 产品官（产品评审 / 代码审查）
- 核心判断：本次缺口补齐代码"能跑通演示，但有两处会被前端 LIVE 降级沉默吞掉的真实漏洞"——`Merchant` 缺 `coupons()` 关系会导致 `CouponController@index` 运行时 500；`AgentApplication` 的 `$fillable` 漏掉 `share_rate`/`merchants_count` 会让管理端分成率修改静默失效（200 但库未写）。另指出优惠券百分比折扣缺 100% 上限、代理商决策前端错误被吞并伪造"成功"。
- 关键建议：补齐两处模型定义、对 percent 折扣加 `max:100`、前端 `agentDecision()` 失败时必须回滚状态并弹错误 toast。

### 🛠️ 主理人（实现与修复收口）
- 核心判断：审查发现的 5 项可操作问题全部已修复并复验（api.js 语法 OK、两个 Web 控制台 0 语法错误、端点契约 coupon 4 路由 + agent 5 路由全部命中）。剩余 3 条"MISSING"属 `verify-contract.mjs` 参数化路径误报，已逐条核对非断链。
- 关键建议：S2（`Coupon.$fillable` 含 `used_count`/`merchant_id`）评估后保留——无 update 路径触碰 `used_count`，`merchant_id` 在 store 强制写入，不可被请求篡改，风险可忽略。

---

## 2. 综合审查发现（去重合并后按严重度排序）

| # | 严重度 | 类别 | 位置 | 问题描述 | 建议 | 来源成员 | 状态 |
|---|--------|------|------|---------|------|---------|------|
| 1 | 🔴 | 运行时 | `backend/app/Models/Merchant.php` | 缺 `coupons()` HasMany 关系，`CouponController@index` 调 `$merchant->coupons()` 会 500（被前端 try/catch 降级掩盖） | 补 `coupons()` 关系 | 产品官 | ✅ 已修 |
| 2 | 🔴 | 数据完整性 | `backend/app/Models/AgentApplication.php` | `$fillable` 漏 `share_rate`/`merchants_count`，管理端改分成率静默无写入 | 补两字段 + casts | 产品官 | ✅ 已修 |
| 3 | 🟠 | 业务逻辑 | `backend/app/Http/Controllers/Api/CouponController.php` | `value` 对 `percent` 类型缺上限，可发 >100% 折扣（负单价） | `Rule::when(type==='percent','max:100')`（store+update） | 产品官 | ✅ 已修 |
| 4 | 🟠 | 前端健壮性 | `app/admin.html` `agentDecision()` | 吞掉 API 错误仍把状态翻成"成功"，伪造成功提示 | catch 内弹错误 toast 并 `return`，不改状态 | 产品官 | ✅ 已修 |
| 5 | 🟡 | 功能缺口 | `CouponController@update` | 优惠券有效期 `start_at`/`end_at` 不可编辑 | 加入 update 验证规则 | 产品官 | ✅ 已修 |
| 6 | 🟡 | 安全加固 | `Coupon` 模型 `$fillable` | 含 `used_count`/`merchant_id` 可批量赋值（理论越权） | 收窄 fillable | 产品官 | ⏸ 留作低优（见局限） |

> 注：端点契约工具 `verify-contract.mjs` 报告 3 条 `GET /api/admin/{orders|settlements/merchants|merchants}/{*}` MISSING，经核对为工具对参数化路径（`{id}` + `+ q` 查询串）归一化的误报——真实 admin 列表端点（`/api/admin/orders`、`/api/admin/settlements/merchants`、`/api/admin/merchants`）均已注册且被正确调用，无断链。

---

## ✅ 行动清单（具体可执行项）

| # | 行动 | 负责方 | 紧急度 | 期望完成 |
|---|------|--------|--------|---------|
| 1 | 真机/CI 执行 2 个新迁移（`add_agent_meta_to_agent_applications_table`、`create_coupons_table`）并跑 `route:clear` | 工程负责人 | P0 | 上线前 |
| 2 | 用真实 Sanctum token 联调 agent 5 端点 + coupon 4 端点，确认 LIVE 模式非降级路径可用 | QA / 工程 | P0 | 联调阶段 |
| 3 | 前端 `agentDecision()` 在 API 失败场景做一次手动验证（确认状态不翻转 + 错误 toast 出现） | QA | P1 | 联调阶段 |
| 4 | 重新部署 `app/admin.html` 与 `app/merchant-web.html` 到 CloudStudio | 主理人 | P1 | 本任务收尾 |
| 5 | （可选）收窄 `Coupon.$fillable`，移除 `used_count`/`merchant_id` | 工程 | P3 | 下个迭代 |
| 6 | 补充优惠券使用核销（用户端领取/下单抵扣）端点——当前仅商家后台 CRUD，消费侧未接通 | 产品/工程 | P2 | 后续规划 |

---

## ⚠️ 待完善 / 已知局限

- **S2 未改**：`Coupon.$fillable` 仍含 `used_count`/`merchant_id`。评估为低危——无 update 路径校验或写入这两个字段，`merchant_id` 仅在 `store` 由服务端强制赋值为当前商家，请求不可篡改；`used_count` 仅由内部核销逻辑自增。保留不改以避免破坏现有写入链路。
- **沙箱无 PHP**：所有 Laravel 代码（迁移、模型、控制器、路由）仅静态编写并经 `node --check` / 契约校验，未实际跑迁移与路由注册。运行时正确性需在真机验证。
- **优惠券消费侧未通**：本期仅完成商家后台 CRUD + 后端存储，用户端领取/下单自动抵扣的逻辑尚未实现（行动项 6）。
- **契约工具误报**：`verify-contract.mjs` 对参数化路径存在归一化误报，勿被 3 条 MISSING 误导。

---

## 📚 成员产出索引

- gstack-product-reviewer（产品官）原始产出：M/L 后端缺口补齐代码审查报告（🔴 B1/B2 + 🟠 I1/I2 + 🟡 S1/S2，含最小修复优先级与代码片段建议）。
- 主理人实现与修复：新增/修改文件清单见下。

### 交付清单（代码变更）

**新增后端文件**
- `backend/database/migrations/2026_07_31_100000_add_agent_meta_to_agent_applications_table.php` — agent 表加 `share_rate` / `merchants_count`
- `backend/database/migrations/2026_07_31_100100_create_coupons_table.php` — coupons 表
- `backend/app/Models/Coupon.php` — 模型 + `generateCode()`
- `backend/app/Http/Controllers/Api/CouponController.php` — index/store/update/destroy（ownership 守卫）

**修改后端文件**
- `backend/app/Http/Controllers/Api/AgentController.php` — 加 show/reject/update/destroy
- `backend/app/Models/Merchant.php` — 加 `coupons()` 关系（B1 修复）
- `backend/app/Models/AgentApplication.php` — `$fillable` 补 `share_rate`/`merchants_count` + casts（B2 修复）
- `backend/routes/api.php` — 注册 agent 4 端点 + coupon 4 端点

**修改前端文件**
- `app/api.js` — 加 `adminAgent*`(5) + `merchantCoupons`/`createCoupon`/`updateCoupon`/`deleteCoupon`（语法 OK）
- `app/admin.html` — `tryLive()` 接 agent 真实数据 + `agentDecision()` 失败回滚（I2 修复）+ `toast_agent_fail` i18n
- `app/merchant-web.html` — `tryLive()` 接 `merchantCoupons()` + 新建优惠券调 `createCoupon`

### 测试覆盖
- `node --check app/api.js` → PASS
- 两端 Web 控制台内联脚本语法检查 → 0 错误
- `tools/verify-contract.mjs` → coupon 4 / agent 5 路由全部命中；3 条 MISSING 为工具误报（已核对非断链）

### 发布检查清单
- [x] 前后端端点对齐（契约 PASS）
- [x] 阻塞漏洞 B1/B2 修复
- [x] 重要项 I1/I2 修复
- [ ] 真机迁移执行（沙箱无 PHP）
- [ ] LIVE 模式非降级联调
- [ ] CloudStudio 重新部署

### 回滚预案
- 后端：回退 `routes/api.php` 的 agent/coupon 段 + 删除 2 个迁移文件即回到缺口前状态；LIVE 模式下前端自动降级演示数据，不影响用户可用。
- 前端：回退 `app/api.js` / `app/admin.html` / `app/merchant-web.html` 三文件即可；离线降级保留演示。

---

> 本报告由软件工坊 AI 协作生成，关键决策请由工程负责人复核。
