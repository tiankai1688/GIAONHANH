# GIAONHANH 营收模型（融资前定义）

> 文档状态：Phase 1 融资前技术就绪（DD-ready）配套材料
> 最后更新：2026-09-12
> 配置真相源：`backend/config/business.php` → `backend/app/Services/PaymentSplitService.php`
> 守护测试：`backend/tests/Feature/CommissionPolicyTest.php`

---

## 1. 当前模型（Phase 1 产品真实行为）

| 杠杆 | 值 | 含义 |
|------|----|------|
| `PLATFORM_COMMISSION_RATE` | **0** | 平台对商家 GMV **抽成恒为 0**（美团式 land-grab） |
| `DELIVERY_SUBSIDY_ENABLED` | `true` | 配送费**平台补贴**骑手，用户/商家 0 配送费感知 |
| `NEW_USER_COUPON_AMOUNT` | `20000` (₫) | **新人券平台出资**；商户券由商户出资（仅扣商户结算，不挪用平台预算） |

分账由 `PaymentSplitService` 单一真相源计算：
- 平台抽成 = `productAmount × commission_rate`（当前 0）
- 平台承担 = 补贴配送费 + 平台券；商家拿满货款（`productAmount` + 非补贴配送费 − 商户券）
- 商家级 `commission_rate` 覆盖全局值（种子商家均为 0，与全局一致）

> 改这一个环境变量，真实分账立即生效；`CommissionPolicyTest` 守护"改率必改钱"。

## 2. 为什么先 0 佣金

越南同城即时配送现有玩家（GrabMart / ShopeeFood / Baemin）对商家抽成约 **15%–30%**。
GIAONHANH 以 **0 佣金 + 0 配送费** 双零切入，换取：
- 商家侧：低门槛入驻、毛利不被抽走 → 快速起量
- 用户侧：到手价最低 → 高频复购、30–60 分钟心智占领
- 这是典型的**市场份额优先（land-grab）**策略，用平台补贴换规模。

## 3. 未来 ramp（融资叙事）

| 阶段 | 佣金率 | 说明 |
|------|--------|------|
| 启动 / land-grab 期（前 6–12 个月 / 按商家入驻队列） | **0%** | 当前产品默认；`backend/.env` 与 `.env.example` 均写 0 |
| 规模拐点后 | **ramp → 12%** | 仍显著低于竞品 15%–30% 区间，保留价格优势；按商家队列分批启用 |

> 实现约束：当前代码为**单一扁平 `PLATFORM_COMMISSION_RATE`**，无 promo 截止 / 按队列 ramp 逻辑。
> Roadmap：在 `config/business.php` 增加 `launch_promo_until`（或按 `merchants.onboarded_at` 队列）驱动未来 ramp；
> 届时不改分账核心，仅扩展配置来源。

## 4. 多元变现栈（佣金之外的收入）

0 佣金不等于 0 收入。规模起来后的变现层次：

1. **广告 / 优选位**：商家付费曝光、搜索加权、首页坑位
2. **金融服务**：基于真实交易流的商家周转贷 / 账期服务（平台不碰资金，合作持牌机构）
3. **连锁商家 B2B SaaS / 数据服务**：多店管理、经营看板、补货建议
4. **支付价差**：接入持牌聚合（`PAYMENT_AGGREGATOR`，规避二清）后的通道收益

## 5. 配置落点（给工程 / DD 的速查）

- 当前写入：`backend/.env` → `PLATFORM_COMMISSION_RATE=0`（demo 用真实产品行为）
- 默认值：`backend/.env.example` → `PLATFORM_COMMISSION_RATE=0`
- 运行时读取：`config('business.commission_rate')`（`env()` 仅在 `config/*.php` 内调用，已全量整改）
- 分账：`PaymentSplitService::compute()` —— 任何费率变更都经此路径，CI 测试覆盖

---
*本文件为商业策略说明，非代码；ramp 具体时间表以正式融资材料为准。*
