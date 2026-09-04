# GIAONHANH 后端代码架构审查（系统架构师视角）

> 审查日期：2026-08-01 · 目的：融资技术尽调 + 越南规模化前的架构健康度体检
> 方法：基于仓库实代码逐文件核查（非凭记忆），引用具体文件:行号
> 范围：`backend/`（Laravel 11）— 12 Api 控制器 / 6 Service / 20 Model / 3 Request / 2 Middleware / 2 Event；**无 Repository、无 Action、无 Job（队列）、无 API 版本前缀**

---

## 1. 总体分层评分

| 维度 | 现状 | 评分 | 一句话 |
|------|------|------|--------|
| 分层方向 | Controller→Service→Model，单向，无循环依赖 | 🟢 良好 | 骨架对 |
| 模块耦合 | Controller 直接 `new`/`app()`/`静态` 依赖具体类 | 🟡 中等 | 能跑但难替换 |
| 单一职责(SRP) | `OrderController` 407 行上帝控制器；`PaymentGatewayService` 460 行单类 | 🔴 差 | 业务规则散落 Controller |
| 循环依赖 | 未见 Model→Service / Service→Controller 反向引用 | 🟢 无 | 这点要给肯定 |
| 技术债密度 | `env()` 滥用（生产炸弹）、store/storeMerged 重复、双结算服务歧义、无版本化、无队列 | 🔴 偏高 | 广度好深度欠 |
| 可测试性 | 仅 3 个 Feature 测试；核心下单/结算/优惠券无覆盖；静态调用+直接 new 难 mock | 🔴 差 | 改了不敢测 |

---

## 2. 分层是否合理

**基本合理但有"伪分层"**。Controller 确实把请求校验交给 `CreateOrderRequest`/`CreateMergedOrderRequest`（好），但**业务逻辑没有下沉到 Service/Action**，而是堆在 Controller 方法内部的事务块里：

- `OrderController::store()`（27–123 行）亲自做：遍历购物车、校验商品归属、算金额、构造 `PaymentSplitService`、写 `Order`/`OrderItem`、记优惠券核销、累加销量——这是一整个**下单用例**，本应是一个 `CreateOrderAction` / `OrderService`。
- `storeMerged()`（130–258 行）把上面约 70 行流程**几乎原样复制了一遍**（cart 遍历、product→merchant 校验、`effectivePrice`、`splitter` 构造、`items` 创建、redemption 记录、`sales` 累加）。

结果：下单/合并单两条路径各持一份副本，**改一处必须手动改另一处**——这是下文"牵一发动全身"的第一源头。

`PaymentGatewayService`（460 行）把 MoMo / ZaloPay / Aggregator 三套 create+sign+verify+refund 全塞进一个类。方向是"统一入口 `createPayment()`"，但**开闭原则违反**：加一个新网关（VietQR/Visa）必须改这个类。其 fail-closed 验签设计是亮点（安全），但类粒度过大。

---

## 3. 模块间耦合

- **依赖方向正确、无循环**：`Controller → Service → Model`，未发现 Model 反向依赖 Service、Service 反向依赖 Controller。
- **但 Controller 反向依赖过重**：`OrderController` 同时 `new PaymentSplitService(...)`、`app(PaymentGatewayService::class)`、`CouponService::resolve()`（静态）。好处是简单；坏处是**无法在测试中替换实现**（见可测试性）。
- **`CouponService::resolve()` 是静态方法**（CouponService.php:19）。静态调用 = 测试时无法用容器注入 mock，且 `OrderController` 的 `resolveServerCoupon`/`resolveNewUserCoupon` 又把**平台券 + NEW_USER 防套补贴逻辑留在 Controller 内**（OrderController.php:336–394），与 CouponService 职责割裂。

---

## 4. 是否违反单一职责

**多处违反，最严重在 `OrderController`：**

1. 一个类同时负责：下单、跨店合并单、订单查询、订单展示、**取消（含退款编排 + 释放骑手 + 级联子单）**、优惠券解析、防套补贴、授权——至少 4 个用例 + 2 个横切关注点。
2. `cancel()`（280–327 行）直接编排：`app(PaymentGatewayService)->refund()` + 改 `rider->status` + 级联 `subOrders` 状态。退款/调度/订单状态机三个域的逻辑耦合在一处。
3. `authorizeOrder()`（398–406 行）的授权逻辑应抽成 **`OrderPolicy`**（Laravel 内置授权机制），而非 Controller 私有方法。
4. `PaymentGatewayService` 单类承载 3 个网关 × 4 种操作。

---

## 5. 有无循环依赖

**未发现循环依赖**。这是本代码的正面项：Model 不依赖 Service/Controller，Service 不依赖 Controller，Service 之间也无互相 `new`。耦合高但**无环**，意味着重构可以局部进行，不必整体重写。

---

## 6. 技术债密度（高危项）

### 🔴 6.1 `env()` 在生产环境是隐藏炸弹（最高优先）
`OrderController` 用 `env('DELIVERY_SUBSIDY_ENABLED')`、`env('NEW_USER_COUPON_AMOUNT')`；`PaymentGatewayService` 构造函数与每个方法大量 `env('MOMO_SECRET_KEY')`、`env('ZALOPAY_KEY1')` 等（PaymentGatewayService.php:30–37, 50–53, 199–202…）。

**Laravel 铁律：`env()` 只能在 `config/*.php` 里调用**。一旦生产执行 `php artisan config:cache`（标准部署做法），运行时 `env()` 全部返回 `null`：
- `MOMO_SECRET_KEY` → `null` → `resolveVerifyKey()` 返回 `null` → **所有支付回调验签 fail-closed 直接拒绝** → 付了钱订单不翻转；
- `sandboxSecret` 默认空串 → sandbox 误判 → 更乱。
- **CI 的 smoke 用 `php artisan serve`（不 cache config）所以测不出来，真到生产才爆**。这是最阴险的一类债：本地/测试全绿，上线即崩。

### 🔴 6.2 `store` / `storeMerged` 重复下单流程
见 §2，约 70 行逻辑双份维护，且都靠 `forceFill($split)` 把数组键直接灌进 Order 列——**改 `PaymentSplitService` 返回的 key 名，两个 Controller 会静默错填而不报错**。

### 🟠 6.3 双结算服务命名/职责歧义
- `SettlementService::merchantPayouts()`（SettlementService.php:41）：**全局累计**每个商户应付 + 银行账号 + KYC。
- `MerchantSettlementService::perMerchant()`（MerchantSettlementService.php:54）：**按日期 T+1** 每个商户应付。
两者都算"每商户应付"，仅差 "Merchant" 前缀，且**分别被 `AdminController` 和 `SettlementController` 调用**（grep 确认都在用，非死代码）。口径不一致 + 命名易混 = 数据对账风险。

### 🟠 6.4 无 API 版本前缀
`routes/api.php` 全部裸挂在 `/api` 下（无 `/api/v1`）。未来改合并单结构/字段即 breaking change，老客户端直接崩——规模化运营无法灰度。

### 🟠 6.5 无队列（Job）
退款（`cancel` 内同步调）、IPN 回调、结算日报表（`SettlementDaily` command）均为同步。`docker-compose.yml` 也无 worker 服务。峰值/IPN 并发会阻塞 PHP 进程。

---

## 7. 可测试性

- **测试广度极窄**：`backend/tests/Feature/` 仅 3 个（PaymentSplitService / PaymentGateway 签名 / Order 状态机）。**最核心的下单 `store`/`storeMerged`/`cancel` 零覆盖**，CouponService、两个 SettlementService、Auth 令牌轮换、Rider 调度均无测试。
- **难 mock**：`CouponService::resolve()` 静态、`new PaymentSplitService`、`app(PaymentGatewayService)` 直接取——没有 `GatewayInterface`/可注入抽象，单元测试无法隔离外部 PSP。
- **env 测试脆弱**：测试须设 `env()` 而非 `config()`，与本应 cache 的生产行为不一致。
- 注：测试在沙箱（无 PHP）跑不了，CI 才跑——但覆盖率本身太低，CI 绿不代表行为被验证。

---

## 8. 「哪个改动会牵一发动全身」

**A. 订单金额 / 分账 / 优惠券计算（最高危）**
`PaymentSplitService::compute/computeMerged` 的返回结构 + `OrderController::store/storeMerged` 的 `forceFill($split)`。因两条下单路径各持副本，任何分账字段增减、任何优惠券规则变更都要**同时改两处**；且 `forceFill` 直接按数组 key 填列，改 Service 返回的 key 会让两个 Controller **静默错填**（不抛异常）。这是教科书级"牵一发动全身"。

**B. `Order.type` 多态（merged / sub / standalone）**
`type` 字段贯穿 `OrderController::mine/show/cancel`、`OrderResource`、`MerchantSettlementService::forMerchant`（`where type='sub'`）、`SettlementService`、`RiderController`。合并单是核心差异点，改其父子关联或加新订单类型，全局受影响。

**C. `env()` → 部署动作触发全局失效（隐性）**
不是"改代码"牵动全身，而是**"启用 config:cache 部署"**会让所有 `env()` 失效，支付/补贴同时崩。比 A/B 更致命，因为发生前毫无征兆、测试也发现不了。

---

## 9. 重构优先级

### 🔴 P0（先解炸弹，成本最低、杠杆最高）
1. **`env()` → `config/` 化**：新建 `config/payment.php`、`config/subsidy.php`、`config/coupon.php`，所有 `env()` 移入并改为 `config('payment.momo_secret_key')`。这是比任何功能都紧急的修复，否则生产支付回调全拒。
2. **抽取下单逻辑**：新建 `App\Actions\CreateOrderAction`（或 `OrderService`），统一 cart→split→items→redemption 流程；`store`/`storeMerged` 只负责"组装 groups / 父子结构"后委托。消除重复、集中分账/优惠券变更点。

### 🟠 P1
3. **优惠券规则移出 Controller**：`resolveServerCoupon` / `resolveNewUserCoupon`（含 NEW_USER 防套补贴）迁入 `CouponService`（改为**非静态、可注入**），Controller 仅调用。
4. **PaymentGateway 拆策略**：`GatewayInterface` + `MoMoGateway` / `ZaloPayGateway` / `AggregatorGateway` + `GatewayFactory`。提升可测试性（mock 接口）与扩展性（加网关不改核心类）。
5. **厘清双结算服务**：明确 `SettlementService`（全局汇总）与 `MerchantSettlementService`（T+1 按日）边界，合并 `merchantPayouts`/`perMerchant` 歧义，统一"应付"口径。
6. **API 版本化**：`/api/v1` 前缀，为未来契约演进留灰度空间。

### 🟡 P2
7. **引入队列**：退款、IPN 处理、结算日报表异步化（`php artisan queue:work` + docker-compose 加 worker）。
8. **Repository / Query 对象**：收口散落 Controller 的查询（`Merchant::approved()->findOrFail` 等）。
9. **Policy**：`authorizeOrder` → `OrderPolicy`。
10. **补测试**：`OrderController` store/storeMerged/cancel、CouponService、两个 SettlementService、PaymentGateway 真实路径；把静态/直接 new 改为可注入以便 mock。

---

## 10. 给融资 DD 的提示

- **架构不崩，但"深度"是硬伤**：分层方向对、无循环依赖，可以被资深 CTO 认可骨架；但 `env()` 炸弹 + 上帝控制器 + 零核心测试，是 DD 技术问询中会被一眼看穿的三处。
- **叙事建议**：把"标准 Laravel 分层、无循环依赖、支付 fail-closed 合规"作为架构正面项；主动披露"正在做 P0 重构（env→config、下单逻辑收敛）"，比被问到再解释更主动。
- **不要说"已微服务化/已容器编排"**：当前是单 Laravel 单体 + 单实例，无 worker/LB——如实说"单体优先，规模化时按 P2 引入队列与水平扩展"。

---

## 附：关键文件索引
- 上帝控制器：`backend/app/Http/Controllers/Api/OrderController.php`（407 行）
- 支付大类：`backend/app/Services/PaymentGatewayService.php`（460 行，env 炸弹源）
- 重复下单：`OrderController.php::store`(27) vs `::storeMerged`(130)
- 双结算：`Services/SettlementService.php:41` vs `Services/MerchantSettlementService.php:54`
- 静态耦合：`Services/CouponService.php:19`
- 路由无版本：`routes/api.php`
- 测试不足：`backend/tests/Feature/`（仅 3 个）
