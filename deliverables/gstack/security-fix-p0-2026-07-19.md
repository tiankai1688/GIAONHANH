# P0 安全红线修复报告（V1 / V2 / V3 / P0-2）

**日期**：2026-07-19
**场景**：安全审计 + 修复（安全官出 spec → 调查员实现 → 质量门神验收）
**参与成员**：调查员（实现）· 安全官（修复 spec + 交叉确认）· 质量门神（验收标准）

---

## 📌 TL;DR（执行摘要）

- **整体结论**：🟡 有条件通过 —— 用户批准的 4 类目标漏洞（V1 越权 / V2 套补贴 / V3 IPN 伪造 / P0-2 空密码）代码**已落地**，主理人逐文件静态复核确认。
- **目标范围内阻塞项**：0。
- **遗留 follow-up（3 项，非本次 4 类范围但建议立即补）**：①退款并发无事务锁；②`users.password` 仍 nullable（缺 NOT NULL 迁移）；③Pest 回归套件未齐 + 缺 pest/phpunit.xml/Pest.php/TestCase 依赖 → **CI 无法门禁**。
- **下一步**：补全 Pest 套件与依赖（CI 由红转绿），并补退款事务锁与 password NOT NULL 迁移；部署前在 `.env` 配齐支付密钥（fail-closed 依赖密钥存在）。

---

## 🎯 核心结论卡片

| 项目 | 内容 |
|------|------|
| Go / No-Go | 🟡 条件 Go（4 类目标闭环；缺动态验证 + 2 项加固） |
| 严重度分布 | 🔴 0 / 🟠 3（遗留）/ 🟡 0 / 🟢 4（已修） |
| 关键行动项 | 6 条（P0×4 / P1×2） |
| 建议负责人 | 调查员（加固）+ 质量门神（Pest 套件）+ 主理人（二清/法务） |
| 验证方式 | 静态代码审查（沙箱无 PHP/Composer，未跑 pest/类型检查） |

---

## 1. 各成员核心结论

### 🔧 调查员（实现）
- **核心判断**：按 spec 完成 4 类漏洞的代码修复，逐文件自检通过。新增 `CouponRedemption` 模型与迁移（唯一约束兜底 V2），`PaymentGatewayService` 改为 fail-closed 验签，`AuthController` 移除客户端 `role` 并强制密码校验。
- **关键建议**：`users.password` 当前仍 nullable，已在代码注释标注需补 NOT NULL 迁移；`cancel()` 退款路径的并发锁未在本轮落地（spec 曾要求），列为遗留。

### 🛡️ 安全官（OWASP + STRIDE 审计 / 修复 spec）
- **核心判断**：4🔴 + P0-2 在代码层全部可复现，修复 spec 与调查员实现一致。特别确认 V3 已无硬编码默认密钥——这是"伪造已付回调"的根因，fail-closed 后缺失密钥即拒绝验签。
- **关键建议**：fail-closed 的副作用是**密钥必须存在**，否则所有真实回调也会被拒；上线前 `.env` 必须配 `PAYMENT_SANDBOX_SECRET` / `MOMO_SECRET_KEY` / `ZALOPAY_KEY*` / `AGGREGATOR_API_KEY`。

### ✅ 质量门神（QA 测试与发布）
- **核心判断**：现有 Pest 套件对本次修复**覆盖不足**——V1 注册/角色、V4 退款并发、P0-2 登录用例缺失；V2 现有反向用例（传 `_coupon_discount`）需改写以断言服务端算券；V3 仅覆盖 `verifyMoMoIpn` 单点，缺"密钥缺失/金额不符/重复 requestId/extraData 绑定"四层。且缺 `pestphp/pest` + `phpunit.xml` + `tests/Pest.php` + `TestCase` 四类依赖，**当前无法挂 CI 门禁**。
- **关键建议**：先补齐 pest 依赖与 4 类用例，使 CI 由红转绿；并新增 V4 退款并发测试（需配合事务锁落地）。

---

## 2. 综合审查发现（去重合并后按严重度排序）

| # | 状态 | 严重度 | 类别 | 位置 | 问题描述 | 建议 | 来源成员 |
|---|------|--------|------|------|---------|------|---------|
| 1 | ✅ 已修 | 🟢 | 安全 V1 | `AuthController.php:24-33`；`routes/api.php:84` | 注册不再接受客户端 `role`，强制 `customer`；`/admin/*` 复用 `ability:admin` 中间件 | 无需动作 | 调查员 |
| 2 | ✅ 已修 | 🟢 | 安全 V2 | `CreateOrderRequest.php:27`；`OrderController.php:50-53,160-184`；迁移 `001400` | 移除 `coupon_discount`；服务端 `resolveServerCoupon()` 算券 + 写 `coupon_redemptions`（唯一约束防复用） | 无需动作 | 调查员 |
| 3 | ✅ 已修 | 🟢 | 安全 V3 | `PaymentGatewayService.php:33,37,163,183,281,395`；`PaymentController.php` V3-④/②/③ | 删硬编码默认密钥；`resolveVerifyKey()` 缺失即返 null；三 verify 方法 fail-closed；回调加金额校验 + extraData/apptransid/order_id 绑定 + `isDuplicateIpn()` 去重 | 无需动作 | 调查员 |
| 4 | ✅ 已修 | 🟢 | 安全 P0-2 | `AuthController.php:54-65` | 登录强制 `password` 必填 + `Hash::check`；空密码账户拒登 | 补 `users.password` NOT NULL 迁移 | 调查员 |
| 5 | 🟠 遗留 | 🟠 | 完整性 | `OrderController.php:112-152` `cancel()` | 退款/取消多步状态变更（payment.status、order.status、rider 释放）无 `DB::transaction` / `lockForUpdate`，并发取消可能双退或状态竞态 | 包事务 + order 行锁 | 质量门神 |
| 6 | 🟠 遗留 | 🟠 | 安全加固 | `users.password` | DB 仍 nullable，应用层已挡空密码但 DB 层可写入空值 | 补 NOT NULL 迁移（含旧空值处理） | 调查员 |
| 7 | 🟠 遗留 | 🟠 | 质量/CI | `backend/tests/**` | Pest 套件 V1/V4/P0-2 缺失、V2 反向需改写、V3 仅部分覆盖；缺 pest/phpunit.xml/Pest.php/TestCase 依赖 | 补全 + 挂 CI | 质量门神 |

### 威胁建模（STRIDE）+ OWASP Top 10 检查表

| STRIDE | 威胁 | 本轮处置 | 状态 |
|--------|------|---------|------|
| **S** Spoofing（伪装） | V1 客户端自报 `role=admin` 伪造令牌 | 服务端强制 `customer` + `ability:admin` 网关 | ✅ 已修 |
| **T** Tampering（篡改） | V3 伪造已付 IPN 回调 | fail-closed HMAC 验签 + 金额校验 + 订单绑定 | ✅ 已修 |
| **R** Repudiation（抵赖） | V3 重复回调重复入账 | `isDuplicateIpn()` 24h 去重 | ✅ 已修 |
| **I** Info Disclosure（信息泄露） | PII 明文（User/Merchant） | 超出本轮 4 类范围，列为 P1 后续 | ⏳ 待办 |
| **D** DoS | — | 不在本轮范围 | — |
| **E** Elevation（提权） | V1 自造管理员令牌越权 | 角色不取请求体 + ability 中间件 | ✅ 已修 |

| OWASP Top 10 | 对应项 | 状态 |
|--------------|--------|------|
| A01 Broken Access Control | V1 越权 | ✅ 已修 |
| A04 Insecure Design | V2 套补贴（服务端算券） | ✅ 已修 |
| A07 Auth Failures | P0-2 空密码 | ✅ 已修（DB 层待加固） |
| A08 Software & Data Integrity Failures | V3 IPN 伪造 | ✅ 已修 |

---

## ✅ 行动清单（具体可执行项）

| # | 行动 | 负责方 | 紧急度 | 期望完成 |
|---|------|--------|--------|---------|
| 1 | 补 `pestphp/pest` + `phpunit.xml` + `tests/Pest.php` + `TestCase`，并补全 V1/V4/P0-2 用例、改写 V2 反向、补 V3 四层；挂 CI 门禁（红→绿） | 质量门神 | P0 | 本周 |
| 2 | 补 `users.password` NOT NULL 迁移（含历史空值处理） | 调查员 | P0 | 本周 |
| 3 | 将 `OrderController::cancel()` 退款路径包入 `DB::transaction` + `order` 行锁（`lockForUpdate`），防并发双退 | 调查员 | P0 | 本周 |
| 4 | 部署前 `.env` 配齐 `PAYMENT_SANDBOX_SECRET` / `MOMO_SECRET_KEY` / `ZALOPAY_KEY1/2` / `AGGREGATOR_API_KEY`（fail-closed 依赖密钥存在） | 运维/调查员 | P0 | 上线前 |
| 5 | 二清合规：`.env` 设 `PAYMENT_AGGREGATOR=sepay`（或 payoo）走持牌聚合源头 split + 法务确认资金流 | 主理人/法务 | P1 | 上线前 |
| 6 | PII 加密（Merchant/User 明文字段）、视觉 QA、设计补全（三端稿 + 登录页 + 连线） | 各成员 | P1 | 后续 |

---

## ⚠️ 待完善 / 已知局限

- **沙箱无 PHP/Composer**：本次为**静态代码审查**，未执行 `php artisan`、`pest`、静态类型检查。动态验证依赖用户侧 PHP 环境 + 行动项 #1 的 Pest 套件。
- **V4 退款并发锁为新增项**：原 V4 设计缺陷（结算导航框架化）已于前序会话修复；本报告 #5 的并发锁是安全 spec 新提出的加固，不在原 4 类范围内，但建议与 #2/#3 一并处理。
- **超出本轮范围的 P0（未处理）**：二清（#5）、PII 明文（#6）属于独立 P0，不在用户批准的 V1/V2/V3/P0-2 内，列为 P1 后续。
- **聚合模式默认关闭**：`PAYMENT_AGGREGATOR` 默认 `none`（直连 Model A），需显式切到持牌聚合（Model B）方规避二清。

---

## 📚 成员产出索引

- 调查员（实现）原始产出：4 类漏洞代码修复 —— 见 `AuthController.php` / `OrderController.php` / `PaymentGatewayService.php` / `PaymentController.php` / `CreateOrderRequest.php` / `routes/api.php` 内 `SECURITY (Vx)` / `V3-④/②/③` 注释，及迁移 `2026_07_15_001400_create_coupon_redemptions_table.php`。
- 安全官（修复 spec）原始产出：P0 修复 spec + QA 独立交叉确认 4🔴+P0-2 全部可复现。
- 质量门神（验收）原始产出：验收标准 + Pest 回归套件缺口清单（V1/V4/P0-2 缺失、V2 反向、V3 部分、依赖缺口）。

---

> 本报告由软件工坊 AI 协作生成，关键决策请由工程负责人复核。
