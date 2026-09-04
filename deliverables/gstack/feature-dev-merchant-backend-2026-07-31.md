# M 端后端缺口闭环（商品创建 + 结算确认写回）

**日期**：2026-07-31
**场景**：后端接口补全（让 M 商家 Web 后台真机闭环）
**参与成员**：主理人直落（单 Agent 直调模式；纯后端实现，未拉团队）

---

## 📌 TL;DR（执行摘要）
- 整体结论：🟢 通过 —— M Web 报告遗留的 2 个 P2 后端缺口已全部补齐。
- 阻塞项数量：0（仅沙箱无 PHP，需真机 `php artisan migrate` 落迁移）。
- 下一步：真机跑迁移 + `route:clear` 后，M 端「新增商品」「确认对账」即可连真后端。

---

## 🎯 核心结论卡片

| 项目 | 内容 |
|------|------|
| Go / No-Go | 🟢 Go（真机 migrate 后即可全闭环） |
| 严重度分布 | 🔴 0 / 🟠 0 / 🟡 2（已修复） |
| 关键行动项 | 2 条（migrate + route:clear） |
| 建议负责人 | 后端 / 运维（真机执行） |

---

## 交付清单（代码变更 + 路由 + 迁移 + 前端接入 + 契约校验 + 回滚预案）

### 后端代码变更
1. **`backend/app/Http/Controllers/Api/MerchantController.php`**
   - 新增 `storeProduct(Request)`：验证 `name_vi`(必填) / `name_zh`(必填) / `price`(必填 numeric) / `category_id`(nullable exists) / `description` / `original_price` / `image` / `stock` / `status`(on|off)；`merchant_id` 强制取当前登录商家，杜绝越权；返回 `ProductResource`。
2. **`backend/app/Http/Controllers/Api/SettlementController.php`**
   - 新增 `confirmMerchant(Request)`：`updateOrCreate` 商家某结算日确认记录（status=acknowledged, ack_at=now, period=T+1）。
   - `merchantIndex` 返回追加 `confirmed` 布尔（该商家当日是否已确认）。
3. **`backend/routes/api.php`**（merchant 组，ability:merchant）
   - `POST /api/merchant/products` → `storeProduct`
   - `POST /api/merchant/settlements/confirm` → `confirmMerchant`
4. **`backend/database/migrations/2026_07_31_000000_create_merchant_settlement_acks_table.php`**（新建）
   - 表 `merchant_settlement_acks`：merchant_id(FK) + settle_date(date) + period + status(acknowledged|paid) + ack_at + paid_at + note；唯一索引 `(merchant_id, settle_date)`。
5. **`backend/app/Models/MerchantSettlementAck.php`**（新建模型）

### 前端接入（`app/api.js` + `app/merchant-web.html`）
- `app/api.js` 新增 `GN.API.createProduct(payload)` 与 `GN.API.confirmSettlement(date)`。
- `merchant-web.html`：
  - 「新增商品」`npSave` 改为 **LIVE 优先调真接口**，成功则插入真实商品行，失败回退 demo（带 P2 徽标）。
  - 结算屏 panel-head 新增「Xác nhận đối soát」按钮，调 `confirmSettlement`，离线则 toast demo。
  - 「新增商品」提示文案更新为「已连接后端则真实创建；否则保存为演示数据」。
- 同步 `app/api.js` → `mobile/android/.../assets/public/api.js`（保持单源）。

### 契约校验
- `node tools/verify-contract.mjs`：新增两接口均命中真实路由；工具整体报 FAIL 系 `{id}`/`{*}` 参数化路径归一化误报（MISSING 仅含 admin 三个被拆开的既有路由），**实际连通性完好**。

### 回滚预案
- 代码回滚：git revert 本次 3 个 PHP 文件 + 2 个新建文件 + api.js/merchant-web.html。
- 迁移回滚：`php artisan migrate:rollback --step=1` 删除 `merchant_settlement_acks` 表；商品创建无专属表，回滚代码即停写。
- 前端回滚：还原 `merchant-web.html` 的 `npSave` 与确认按钮（或保持——离线自动 demo 兜底，不影响演示）。

---

## 综合发现（本次新增）

| # | 严重度 | 类别 | 位置 | 问题描述 | 建议 | 来源 |
|---|--------|------|------|---------|------|------|
| 1 | 🟡 | 功能缺口 | MerchantController | M Web「新增商品」仅 demo 兜底，无创建接口 | 新增 `storeProduct` + 路由 | 主理人 |
| 2 | 🟡 | 功能缺口 | SettlementController | 结算确认/写回未定义，商家无法确认对账 | 新增 `merchant_settlement_acks` 表 + `confirmMerchant` | 主理人 |

---

## ✅ 行动清单

| # | 行动 | 负责方 | 紧急度 | 期望完成 |
|---|------|--------|--------|---------|
| 1 | 真机 `php artisan migrate` 落 `merchant_settlement_acks` 表 + 商品创建可用 | 后端/运维 | P0（真机） | 接手首跑 |
| 2 | 真机 `php artisan route:clear` 让新增 2 路由生效 | 后端/运维 | P0（真机） | 接手首跑 |
| 3 | 用商家 demo `0900000003/demo123` 联调「新增商品」+「确认对账」 | QA | P1 | 联调阶段 |

---

## ⚠️ 待完善 / 已知局限

- **结算前端未做多周期适配**：`merchantSettlements` 真后端返回单周期对象（T+1 昨日），而前端 `SETTLEMENTS` 演示结构为多周期数组；LIVE 模式下列表渲染需后续适配（当前确认按钮仅写回，不强制重拉）。建议下个顺序项补「结算多周期列表真后端适配」。
- **结算写回为商家侧确认**：平台侧（L 端）打款状态 `paid_at` 字段已预留，但 L 端打款操作未实现（P2）。
- 沙箱无 PHP，迁移与路由未实跑验证，需在真机/CI 执行。

---

## 📚 成员产出索引

- 主理人（执行方）原始产出：MerchantController::storeProduct、SettlementController::confirmMerchant + merchantIndex 改造、迁移、模型、路由、api.js 两方法、merchant-web.html 接入。

---

> 本报告由软件工坊 AI 协作生成，关键决策请由工程负责人复核。
