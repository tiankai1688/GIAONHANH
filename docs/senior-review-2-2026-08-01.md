# 资深审查者挑刺报告（第 2 轮 · 全维度）

> 审查者立场：10 年经验、严苛、代码要上越南生产环境面对真实用户与黑客。
> 前提：软件**中国开发、越南上线、与国内无业务关系** → 管辖法为**越南 PDPD（13/2023/NĐ-CP）+ 消费者保护法**；PIPL 不适用；"中国开发 + 越南数据"自带跨境维度。
> 约束：沙箱**无 PHP**，全部代码从未被任何运行器跑过，以下为人工静态审查，结论以"能复现的利用路径"为准。
> 范围：backend/ + app/(前端原型) + mobile/。所有定位均带真实 `文件:行号`。

---

## 维度一：功能完整性 / 是否"做完了"

### 1.1 🔴 致命 — 合并订单（merged）永远无法履约：子单卡死 `pending_payment`
- **位置**：`PaymentController::momoIpn` L219、`zaloPayCallback` L279、`aggregatorCallback` L330（三处 IPN 处理）；`MerchantController::acceptOrder` L155。
- **为什么是问题**：用户为合并父单支付后，IPN 按 `order_no` 找到的是**父单**并置 `paid`，但**从没有任何代码把子单（type=sub）推进到 paid**。而 `acceptOrder` 强制要求 `status==='paid'`，子单永远是 `pending_payment` → 商家永远 422。**平台主推的"跨店合并、一次配送"功能完全失效**，且三处 IPN 都漏同样的逻辑，说明是设计遗漏而非手误。
- **修改建议**：在三个 IPN 的 `if ($payment->status !== 'success')` 块内、置父单 paid 之后，级联子单：
```php
// 在 $order->update(['status' => 'paid', 'paid_at' => now()]); 之后追加：
if ($order->type === 'merged') {
    $order->subOrders()->where('status', 'pending_payment')
        ->update(['status' => 'paid', 'paid_at' => now()]);
}
```

### 1.2 🔴 致命 — 商户结算漏掉最主要的订单类型 `type='single'`
- **位置**：`MerchantSettlementService::forMerchant` L33-34、`perMerchant` L64。
- **为什么是问题**：两处都 `->where('type', 'sub')`。但 `OrderController::store`（单店下单，L47-61）**从不设置 `type`**，迁移默认值 `2026_07_29_000000_support_merged_orders.php` 给的是 `'single'`。于是平台**占比最大的普通单店订单（type=single）永远不进入 T+1 结算**，商户对正常订单收不到钱，`adminPayout`/`total_payable` 全是错的。类注释"Only sub-orders are merchant-billable"是错误前提（single 单照样是某商户的订单、merchant_id 已填）。
- **修改建议**：
```php
// forMerchant:  L33 改为
$orders = Order::where('merchant_id', $merchant->id)
    ->whereIn('type', ['single', 'sub'])   // 含普通单店单
// perMerchant:  L64 改为
$rows = Order::whereIn('type', ['single', 'sub'])
```
  ⚠️ 同时必须核查 `SettlementService`（终身累计口径，agent 未覆盖）是否也有同样的 `type='sub'` 硬编码。

### 1.3 🔴 致命 — PSP 费根本没写进 `orders` 表（上一轮老板#1 的"修复"是死代码，本轮核对确认仍未修）
- **位置**：`PaymentController::pay` L63-65 写 `$locked->update(['psp_fee'=>..., 'psp_fee_bearer'=>...])`；`Order::$fillable` L15-22 **不含这两个字段**。
- **为什么是问题**：Eloquent `update()` 走 `fill()`，**静默丢弃非 fillable 字段** → `orders.psp_fee`/`psp_fee_bearer` 永远 `NULL`。上轮为"让单位经济可见"加的修复完全失效，每单 PSP 成本（1.5–3.5%/笔）依旧不可见。`payments.psp_fee` 能写（Payment 的 fillable 含它），但报表主口径在 `orders`，**口径还不统一**。
- **修改建议**：把这两列加入 `Order::$fillable`：
```php
protected $fillable = [
    'order_no', 'user_id', 'merchant_id', 'rider_id', 'type', 'parent_order_no', 'group_delivery_fee',
    'product_amount', 'delivery_fee', 'coupon_id', 'coupon_discount', 'platform_subsidy', 'commission',
    'amount', 'merchant_settlement', 'status', 'delivery_type', 'expect_time',
    'pay_method', 'address', 'lat', 'lng', 'contact_name', 'contact_phone', 'note',
    'paid_at', 'accepted_at', 'picked_at', 'delivering_at', 'delivered_at',
    'refunded_at', 'refund_error', 'psp_fee', 'psp_fee_bearer',   // ← 补这俩
];
```

### 1.4 🟠 严重 — 自愈命令是死代码：生产从不跑 `schedule:run`
- **位置**：`routes/console.php`（调度已注册）、`docker-compose.yml`（无 scheduler 进程）。
- **为什么是问题**：`ReconcileOrders` 调度每 5 分钟，但容器只起 `php artisan serve`/`php-fpm`，**没有进程执行 `schedule:run`**。卡死订单（IPN 丢失）照样永久卡死——上轮"自愈"修复在生产不生效。
- **修改建议**：`docker-compose.yml` 增加 `schedule` 服务（`php artisan schedule:run` 每分钟 via `cron` 或 `scheduler` 包），或显式起一个 `queued`/supervisor 进程；CI 增加"调度已注册且命令可执行"的冒烟检查。

### 1.5 🟠 严重 — 注册 OTP 未接真实短信网关 + 0 收入模型
- **位置**：`AuthController::register` L100-116（仅生成+缓存 OTP，dev 经 `app.debug` 回传）；商业模型（全仓无营收）。
- **为什么是问题**：生产无短信渠道，OTP 无法到达真实用户；且 0 佣金 + 平台补贴 + PSP 费 + 新人券，**规模越大亏越多，无任何收入线**。这是上线前的商业存亡问题，不是代码 bug 但比 bug 致命。
- **修改建议**：接入 MoMo/ZaloPay/第三方 SMS OTP 通道；商业上必须明确收入来源（广告、会员、商家增值、或 PSP 费转嫁商户），写入 `merchant-agreement.md` 并经财务确认。

### 1.6 🟡 一般 — 实时推送层在生产实际不可用 + FCM legacy 已停用
- **位置**：`.env.example:60` `BROADCAST_DRIVER=null`；`NotificationService` 用 FCM **legacy** API（Google 2024 已停）；全仓 grep 无 `NotificationService` 调用点。
- **为什么是问题**：商家/骑手实时通知实际只靠前端轮询（能工作，但违背"实时"设计）；即便配 `FCM_SERVER_KEY` 也会失败。
- **修改建议**：配置 Pusher/Ably 或文档化为"仅轮询"；迁移 `NotificationService` 到 FCM v1 并接到 `OrderPaid`/`OrderReadyForGrab` 事件。

### 1.7 🟡 一般 — 合并订单的 NEW_USER 券在事务外被消耗（下单失败也烧掉首单补贴）
- **位置**：`OrderController::storeMerged` L128 `resolveServerCoupon`（创建 `CouponRedemption`）在 `DB::transaction`（L137）**之外**；单店 `store` 在事务内（安全）。
- **为什么是问题**：合并下单若后续事务回滚（如某商品 `persistItems` 库存不足 abort 422），首单平台补贴已记为已用，用户再也拿不到补贴。单店路径无此问题。
- **修改建议**：把平台券 resolve 移入事务内（与单店一致），或在事务回滚时补偿删除该 `CouponRedemption`。

---

## 维度二：架构与可维护性

### 2.1 🟡 一般 — 死代码 `Controller::dispatchRider()`
- **位置**：`Controller.php:32-49`。全仓 grep 无任何调用点，注释暗示"自动派单"，与已采用的"抢单"模型自相矛盾，误导维护者。
- **修改建议**：直接删除该方法及未使用的 `use App\Models\Rider`。

### 2.2 🟡 一般 — 锁顺序不一致 → 死锁风险
- **位置**：`store()` 顺序 coupon→product（L81-82）；`storeMerged()` 顺序 product→coupon（L179 循环内锁 product、L183-185 循环后锁 coupon）。
- **为什么是问题**：单店与合并并发高负载下，两个事务互持一锁等另一锁 → MySQL 回滚其一，造成偶发 500。
- **修改建议**：统一"先锁 coupon 再锁 product"，或在单个事务内按固定顺序 `lockForUpdate` 所有相关行。

### 2.3 🟡 一般 — `OrderPaid` 合并父单广播频道非法
- **位置**：`Events/OrderPaid.php`（频道 `merchant.'.$order->merchant_id`），合并父单 `merchant_id=null` → 频道为 `merchant.`，无效。
- **修改建议**：修复 1.1 时，按各子单 merchant 分别广播，或对父单跳过广播。

### 2.4 🟢 建议 — `OrderResource` 向终端客户暴露内部计费口径
- **位置**：`OrderResource.php:33-36`（`platform_subsidy`/`commission`/`merchant_settlement`）。属内部计费，C 端无价值且泄露毛利。
- **修改建议**：按请求者角色隐藏；或仅在 `merchant`/`admin` 视角返回。

### 2.5 🟢 建议 — 地理围栏依赖客户端坐标（架构缺陷，详见安全 3.x）
- **位置**：`RiderController::nearby` L34-60。围栏以**客户端传入的 lat/lng** 为基准，服务端无法信任。这是"假围栏"的根因（见 3.x）。

---

## 维度三：安全（⚠️ 单独跑一遍）

### 3.1 🔴 致命/严重 — 合并单履约失效 + 结算漏单 → 可直接被竞对/黑客用来
见 1.1 / 1.2。资金级漏洞，黑客可借此证明"平台付了钱却永不履约"并引发群诉。

### 3.2 🔴 致命 — `nearby` 围栏用客户端坐标 = 全国 PII 仍可枚举（上轮并指出，仍未修）
- **位置**：`RiderController::nearby` L34-60、`GrabOrderResource.php:32-34`。
- **为什么是问题**：围栏以**骑手自己报的 lat/lng** 为基准。恶意骑手沿越南网格（每 10km 一个采样点）依次发 `?lat=&lng=`，即可把全国所有 `picked 未指派` 订单的**收件地址 + 精确 GPS（address/lat/lng 在 GrabOrderResource 里原样返回）** 拖走。姓名/手机虽脱敏，但"谁住哪、精确坐标"已是可定位的 PII，违反 PDPD，且可被用于线下骚扰/抢劫（知道包裹时段+住址）。
- **修改建议**：围栏基准必须是**服务端可信的骑手实时位置**（来自 `updateLocation` 落库的 `riders.lat/lng`），严禁用 `request->query('lat/lng')` 作为围栏输入；客户端坐标仅用于显示距离。
```php
$lat = $rider->lat;   // 服务端可信，不用 $request->query('lat')
$lng = $rider->lng;
if (! $lat || ! $lng) {
    return GrabOrderResource::collection($query->where('rider_id', $rider->id)->paginate(...));
}
```

### 3.3 🔴 致命 — 前端 `innerHTML` 直接插值用户可控数据（存储型 XSS → 账户接管）
- **位置**：`app/index.html`、`app/merchant.html`、`app/rider.html`、`app/admin.html`、`app/merchant-web.html` 中商户名/商品名/客户联系/订单字段等经 API 返回后未经转义即 `innerHTML=`（`native-bridge.js:148-150` 的 toast 做了转义，但其余大量未做）。
- **为什么是问题**：商户把店铺名设为 `<img src=x onerror=...>` 即可在消费者/商家/骑手 WebView 与管理台执行脚本；结合 3.4（token 在 localStorage）即完整账户接管（改密码、转券、读订单）。
- **修改建议**：所有 API 来源字符串统一用 `textContent` 或 `DOMPurify` 转义；管理台与订单详情为最高危面，优先改造。

### 3.4 🔴 致命 — 长寿命 `refresh_token` 明文存 `localStorage`（XSS 即可持久劫持）
- **位置**：`app/api.js:14-17,25-27`（`GN.token`/`GN.refreshToken` 直接 `localStorage.setItem`）。
- **为什么是问题**：一旦 3.3 的 XSS 成立，脚本读 `refresh_token` 调 `/auth/refresh` 无限续期，**完全账户接管**，且 PDPD 下刷新令牌属敏感凭证明文存储。Capacitor 生产环境应使用 `Capacitor SecureStorage`/系统 Keychain。
- **修改建议**：
```js
import { Capacitor } from '@capacitor/core';
import { SecureStorage } from ...; // 或 @capacitor/secure-storage
await SecureStorage.set({ key: 'gn_refresh', value: token }); // 替代 localStorage
```

### 3.5 🟠 严重 — 注册 OTP 验证无每手机号尝试上限/锁（可被爆破）
- **位置**：`AuthController::verifyRegistration` L127-157 仅 `throttle:auth`（10 次/分/IP），无失败计数；`register` 发码有 60s 冷却但验证侧没有。
- **为什么是问题**：6 位 OTP（百万组合）在分布式 IP 下可爆破，绕过"防假账号 farm 新人券"的防护（红队老板#2 目标）。
- **修改建议**：对称 `login` 的 `registerFailedLogin`，加每手机号失败计数（5 次锁 15 分）：
```php
$key = 'otp_fail:' . $data['phone'];
if (Cache::get('otp_lock:' . $data['phone'])) return response()->json([...], 429);
if (! hash_equals(...)) {
    if (Cache::increment($key, 1, 1) >= 5) { Cache::put('otp_lock:'.$data['phone'], true, now()->addMinutes(15)); Cache::forget($key); }
    return response()->json(['message' => 'Mã không đúng.'], 422);
}
```

### 3.6 🟠 严重 — 支付状态竞态：控制器覆盖 IPN 的 success
- **位置**：`PaymentController::pay` L121-127（钱包路径 `createPayment` 返回后**无条件** `$payment->update(['status'=>$result['status']...])`，`$result['status']` 恒为 `'pending'`，无 `if status!=='success'` 守卫）。
- **为什么是问题**：若 IPN 在 `createPayment` 返回与本次 `update` 之间到达（重放/晚到场景），IPN 已置 success+order paid+触发 `OrderPaid`，随后该 `update` 又把 payment 改回 `pending` → 订单显示已付但支付行 pending，且 `isDuplicateIpn` 拦截后续真实重试 → 订单卡死、退款逻辑 `cancel()` 因 `$payment->status!=='success'` 不退款、双扣风险。
- **修改建议**：
```php
if ($payment->status !== 'success') {   // 守卫
    $payment->update([...]);
}
```

### 3.7 🟠 严重 — 注销未清除订单中的客户 PII（违反 PDPD 被遗忘权）
- **位置**：`AuthController::destroyAccount` L266-311 仅匿名化 User/Merchant/Rider，未处理 `orders.contact_name/contact_phone/address/lat/lng`。
- **为什么是问题**：用户注销后，其历史订单仍明文保留住址/电话/GPS，且通过 `user_id` 关联到已软删账户，违反 PDPD 数据最小化与被遗忘权。
- **修改建议**：在 `destroyAccount` 内对该用户所有 `orders` 的识别性 PII 置空（保留订单财务记录以满足税务/AML 留存例外）：
```php
Order::where('user_id', $user->id)->update([
    'contact_name' => null, 'contact_phone' => null,
    'address' => null, 'lat' => null, 'lng' => null,
]);
```
  （并据此更新隐私政策"留存例外"条款。）

### 3.8 🟡 一般 — 同一骑手并发双接单（busy 检查基于未加锁快照）
- **位置**：`RiderController::accept` L113（`$rider->status==='busy'` 在事务外读取）、L120-137 事务只锁 order 不锁 rider。
- **为什么是问题**：同一骑手对两个不同订单并发 `accept`，都过 busy 检查 → 同时持两单、`current_order_id` 被覆盖。
- **修改建议**：事务内对 rider 行 `lockForUpdate` 后再判 busy：
```php
return DB::transaction(function () use ($order, $rider) {
    $r = Rider::where('id', $rider->id)->lockForUpdate()->first();
    if ($r->status === 'busy') return response()->json([...], 409);
    $locked = Order::where('id', $order->id)->lockForUpdate()->first();
    ...
    $r->update(['status' => 'busy', 'current_order_id' => $locked->id]);
});
```

### 3.9 🟢 建议 — CORS 默认 `*`
- **位置**：`config/cors.php:19` `explode(',', env('CORS_ALLOWED_ORIGINS', '*'))`。当前 `supports_credentials=false`+Bearer 下风险可控，但生产应改显式白名单。

---

## 维度四：测试是否"真"覆盖

**结论：本套测试质量高于常见项目，未发现"只断言自己 set 的值 / 复制实现而非调用实现"的假测试。** `PaymentSplitServiceTest` 测金额数学、`OrderControllerTest`/`FailurePathCoverageTest`/`BlindSpotCoverageTest`/`RedTeamFixesTest` 均经 HTTP+RefreshDatabase+Mockery 验真实行为、`PaymentGatewaySignatureTest` 真验篡改即拒。但存在**致命盲区——正是 1.1/1.2/1.3 能溜进 CI 的原因**：

| 盲区 | 后果 | 应补测试 |
|---|---|---|
| 合并单"支付后生命周期"零覆盖 | 1.1 漏网 | 父单支付后断言全部 sub 变 `paid`、商家可 `acceptOrder` |
| 结算 `type` 过滤器零覆盖 | 1.2 漏网 | 断言 `forMerchant` 含 `type='single'`、`perMerchant` 同理 |
| `Order.$fillable` 未断言含 psp_fee | 1.3 漏网 | 断言 `pay()` 后 `orders.psp_fee` 非 null |
| 支付状态竞态零覆盖 | 3.6 漏网 | 注入"IPN 在 createPayment 之后、控制器 update 之前到达"的时序 |
| OTP 爆破/锁、注销 PII 清除、迟到 IPN 对账、骑手双接单 | 3.5/3.7/3.8 漏网 | 分别补单测 |

**关键制度缺陷**：`verify-contract.mjs` 只验路由存在性，不验业务正确性；CI 的 `pest` job 是唯一真相，但**沙箱无 PHP，本仓库从未真正跑过一次 pest**。建议 push 触发 CI 拿到绿证前，任何"已修复"声明都只是静态推断。

---

## 维度五：市场化 / 合规 / 成本

### 5.1 🔴 致命 — 0 佣金无收入线（商业模型不可持续）
全仓无任何营收来源。0 佣金 + 平台补贴配送费 + 新人券 + PSP 费，规模越大亏越多。必须上线前定下收入模型并经财务签字。

### 5.2 🔴 致命 — PSP 费成本不可见（见 1.3）
单位经济全盲，连"每单亏多少"都看不到，无法做定价/补贴决策。

### 5.3 🟠 严重 — PDPD 跨境维度：中国开发 + 越南数据
- "中国开发团队访问越南生产库/日志" = 未申报的跨境处理；任何崩溃上报/遥测回连中国 = 非法跨境传输。
- **修改建议**：生产日志/监控落地越南或合规第三国；中国开发禁直连生产库（脱敏镜像 + 跳板审批）；隐私政策越南语明示存储地/跨境（已在 `privacy-policy.md §5` 增补，但需 App 内挂链接并落实隔离）。

### 5.4 🟠 严重 — 结算/看板时区未显式锁定
- **位置**：`AdminController::today()/yesterday()` 与 `MerchantSettlementService` 依赖 `app.timezone`。若未设 `Asia/Ho_Chi_Minh`，T+1 结算日界错位 → 漏算/重算。
- **修改建议**：`.env` 显式 `APP_TIMEZONE=Asia/Ho_Chi_Minh`；结算逻辑用显式时区。

### 5.5 🟠 严重 — 单容器、队列与 web 同体、无扩缩容
单容器单主机，骑手位置写主表有写放大，同步调 PSP。规模化后瓶颈明显，且 `schedule:run` 无进程（见 1.4）。

### 5.6 🟡 一般 — 迟到 IPN 造成资损/客诉
`ReconcileOrders` 把 `pending_payment` 超 TTL 直接取消并恢复库存（L37-55），未先查 PSP 真实状态。若 MoMo/ZaloPay IPN 30 分钟后才到，客户已被扣款但订单已取消、库存被别人买走 → 资损+群诉。
- **修改建议**：过期前先调网关查询接口对账，确未支付才取消；或把 TTL 设为远大于通道最大重试窗口。

### 5.7 🟢 建议 — `capacitor.config.json:6` `cleartext:true`
生产允许明文 HTTP，易被中间人拦截 token/支付回调。生产置 `false` 强制 HTTPS。

---

## 进阶：让代码"红队自己"

把上面最致命的几条串成**一条可在上线当天打崩平台的攻击链**（无需 0day，全是功能/逻辑缺陷）：

1. **财务沉默出血（老板视角自动化）**：脚本批量注册（当前 OTP 未接短信，dev 回传即自动）→ farm 新人券 → 下单→取消（3.7 不清 PII 但券已消耗）→ 平台纯烧钱。即便修 OTP，财务 1.1/1.2/1.3 仍让"已付合并单永不履约""商户收不到钱""每单亏多少不可见"。
2. **竞对可以利用 1.1 做舆情核弹**：在媒体/群里发"我在 GIAONHANH 合并下单付款后，永远显示待接单、商家说看不到我的单"——这是**真实会发生**的，不是 hypothetical；修复前必爆。
3. **黑客拖 PII（3.2）**：写 20 行脚本，沿越南经纬度网格轮询 `GET /rider/orders?lat=&lng=`，把全国待配送订单的**住址+GPS** 入库。结合 3.3/3.4 的 XSS→接管 admin 后台，可再导出全量用户电话。
4. **账户接管链**：商家商品名注入 XSS（3.3）→ 管理台/骑手端执行 → 读 `localStorage` 的 `refresh_token`（3.4）→ `/auth/refresh` 续期 → 改任意订单/券/商户银行账号。
5. **拒绝服务（1.4）**：因为 `schedule:run` 没进程，任何卡死订单（如 3.6 竞态或 IPN 丢失）永久卡死，运营只能手工改库，规模化后工单淹没。

**"红队自己"的结论**：这套代码在"happy path 演示"层面做完了，但在"生产正确性 + 资金闭环 + 安全闭环"三个维度都有未闭合的致命缺口。**最该先修的 5 件（按上线阻断力排序）**：
- A. 1.1 合并子单级联 paid（否则主功能失效）
- B. 1.2 结算含 `type='single'`（否则商户收不到钱）
- C. 1.3 `Order.$fillable` 补 `psp_fee/psp_fee_bearer`（否则单位经济全盲）
- D. 3.2 围栏改用服务端可信坐标（否则全国 PII 拖库）
- E. 3.3+3.4 前端 XSS 转义 + token 移出 localStorage（否则账户接管）
- 次优先：3.5 OTP 锁、3.6 支付竞态守卫、3.7 注销清订单 PII、1.4 调度进程、2.2 锁顺序。

> 声明：本报告为静态代码审查，非法律意见；PDPD/SBV/消费者保护合规建议上线前聘越南当地律师 + DPO 复核。所有"已修复"声明需以 `php artisan test` + 真实部署冒烟为证（沙箱无 PHP，本轮未运行）。

---

## ✅ 已修复状态（2026-08-01「立即修复」）

上一轮审查确认的 3 个致命 + 安全/数据严重项，本轮已落地（沙箱无 PHP，全部为静态对齐源码，权威验证待 CI `pest` 绿证）：

| 审查定位 | 修复 | 落地物 |
|---|---|---|
| **致命 A** 合并单 IPN 不级联子单 | 新增 `PaymentController::markOrderPaid()`：父单置 `paid` 时级联把所有 `pending_payment` 子单也置 `paid`；三处 IPN（momo/zalo/aggregator）成功分支改为调用它 | `PaymentController.php` + 私有助手 |
| **致命 B** 结算漏掉 single 单 | `MerchantSettlementService` 两处 `where('type','sub')` → `whereIn('type',['single','sub'])` | `MerchantSettlementService.php` |
| **致命 C** PSP 费死代码 | `Order::$fillable` 补 `psp_fee`/`psp_fee_bearer`，`pay()` 的 `update()` 从此真正持久化 | `Order.php` |
| **严重 S1** 围栏信任客户端坐标 | `nearby` 地理围栏中心改用骑手**存储坐标**（`riders` 表，由 `updateLocation` 写入）；客户端 lat/lng 仅用于距离展示，无法再网格扫描枚举全国订单 | `RiderController.php` |
| **严重 S3** 库存回滚非原子 | `ReconcileOrders` 库存恢复+置 cancelled 包进同一 `DB::transaction`；去掉废条件 `where('stock','>=',0)` | `ReconcileOrders.php` |
| **严重 N3** 误标已支付单 | 步骤 2 加 `whereHas('order', status='pending_payment')` 守卫，不再把已支付订单的 pending 支付错标 failed | `ReconcileOrders.php` |
| **严重 S5** 注销不脱敏订单 PII | `destroyAccount` 软删前匿名化该用户 `orders` 的 contact_name/phone/address/lat/lng/note（商业金额保留供审计） | `AuthController.php` |
| **安全 XSS** innerHTML 直接插值 | 新增全局 `GN.esc()`（HTML 转义）；对跨角色高危注入点加转义：商家视图(客户姓名/电话/地址/商品名)、骑手视图(客户姓名/取送地址/商家名址)、客户视图(店名/商品名/分类名) | `app/api.js` + `index.html`/`merchant.html`/`rider.html` |
| **安全 令牌存储** refresh_token 明文 localStorage | `api.js` 令牌存储 `localStorage` → `sessionStorage`（tab 关闭即清，缩小 XSS 读取窗口）；注释说明真解为 httpOnly 同域代理 | `app/api.js` |
| **严重 S4** 调度进程缺失（reconcile 死代码） | 在 `infra/supervisor.conf` 增 `gn-scheduler` 程序，每 60s 跑 `php artisan schedule:run`，使 `orders:reconcile`（每 5 分钟）在生产真正执行；`docker-compose.yml` 注释说明调度由同容器 supervisor 负责，无需独立容器 | `infra/supervisor.conf` + `docker-compose.yml` |
| **安全 XSS** 无全域 CSP | API 源 nginx（`infra/nginx/default.conf`）加全局 `Content-Security-Policy`（`connect-src 'self'` / `frame-ancestors 'none'` / `object-src 'none'` 等）；SPA 源部署指南见 `docs/csp.md` | `infra/nginx/default.conf` + `docs/csp.md` |
| **安全 令牌 3.4** refresh_token HttpOnly 真解 | 后端 `respondWithTokens()` 把 refresh_token **仅**以 HttpOnly Cookie 下发（绝不进 JSON body）；`refresh` 读 Cookie（body 仅作 native 兜底）；新增 `logout` 端点 + `destroyAccount` 注销时过期 Cookie；前端 `api.js` 不再持有/存储 refresh_token，刷新靠 Cookie 自动携带 | `AuthController.php` + `routes/api.php` + `app/api.js` |

**新增回归测试**：`backend/tests/Feature/SeniorReviewFixesTest.php`（5 例）锁定 A 级联 / B 结算含 single / C fillable 持久化 / S5 注销脱敏 / S3+N3 reconcile；`backend/tests/Feature/HttpOnlyRefreshTest.php`（4 例）锁定 refresh_token 不进 body / HttpOnly / Cookie 轮换 / 无 Cookie 401 / logout 过期 Cookie。

**仍待处理（非本轮代码层 / 需法务·运维·商务）**：
- 前端 XSS 全域 CSP 的**严格化**（nonce 化 / 去内联 `onclick`）属重构项；当前以 `GN.esc()` + 全局 `connect-src 'self'` CSP 已达纵深防御，SPA 源需按 `docs/csp.md` 在其托管层补同一份头。
- 0 佣金无收入线、单容器无队列无扩缩容、reconcile 接真实 PSP 查询对账、生产-开发网络隔离、PDPD 跨境影响评估+A05 报备——均为架构/流程项，未在本轮改动。
- 仍建议 push 一次触发 CI（`pest` + `backend-smoke`）拿权威绿证。

---

## ✅ 第 3 轮「立即修复」闭环（2026-08-01 续）

上一轮（资深审查第 2 轮）标红的 3 个代码层待修项（S4 调度 / XSS 全域 CSP / refresh_token httpOnly 真解）本轮已落地（沙箱无 PHP，全部静态对齐源码，权威验证待 CI `pest` 绿证）：

| 审查定位 | 修复 | 落地物 |
|---|---|---|
| **严重 S4** 调度进程缺失（reconcile 死代码） | supervisor `gn-scheduler` 每 60s 跑 `schedule:run` | `infra/supervisor.conf` + `docker-compose.yml` |
| **安全 XSS** 无全域 CSP | API 源 nginx 全局 CSP；SPA 源指南 `docs/csp.md` | `infra/nginx/default.conf` + `docs/csp.md` |
| **安全 令牌 3.4** refresh_token HttpOnly 真解 | refresh_token 仅 HttpOnly Cookie（不进 body）；`refresh` 读 Cookie；新增 `logout`；前端不持有 refresh_token | `AuthController.php` + `routes/api.php` + `app/api.js` |

**新增回归测试**：`backend/tests/Feature/HttpOnlyRefreshTest.php`（4 例）锁定 refresh_token 不进 body / HttpOnly / Cookie 轮换 / 无 Cookie 401 / logout 过期 Cookie。

**仍待处理（非代码 / 需法务·运维·商务）**：0 佣金营收模型、单容器扩缩容、reconcile 接真实 PSP 对账、生产-开发网络隔离、PDPD 跨境 A05 报备、XSS CSP 严格化（nonce）。仍建议 push 触发 CI 拿绿证。

---

## ✅ 第 4 轮「立即修复」闭环（2026-08-01 · 待处理清单）

将上一轮"仍待处理"中可在沙箱内真实落地的项全部闭环（沙箱无 PHP，PHP 改动静态对齐源码，权威验证待 CI `pest` 绿证）：

| 待处理项 | 性质 | 本轮落地 | 落地物 |
|---|---|---|---|
| 0 佣金营收模型 | 业务+代码 | 把"抽成恒 0"从硬编码字面改为**显式可配置**的全局策略：新增 `PaymentSplitService` 构造函数回退到 `config('business.commission_rate')`（env `PLATFORM_COMMISSION_RATE`）；合并订单不再硬编码 `0.0`，改用全局策略；单店仍保留按商户覆盖。以后调营收只需翻 env，无需动代码。 | `PaymentSplitService.php` + `OrderController.php` + `config/business.php`（已存在） |
| 单容器扩缩容 | 运维 | `docker-compose.yml` 数据层（MySQL/Redis）移入 **internal 网络**（不可被外部扫描、无出网）；`schedule:run` 加 `withoutOverlapping()`（Redis 锁），多副本/独立调度器下不会重复对账；附多副本 `deploy.replicas` 与独立调度服务注释示例 | `docker-compose.yml` + `routes/console.php` |
| reconcile 接真实 PSP 对账 | 代码 | `PaymentGatewayInterface` 增 `queryStatus()`；`PaymentGatewayService` 实现（MoMo/ZaloPay 真实查询端点，失败/未配置/sandbox 一律返回 `null`）；`ReconcileOrders` 先查真实 PSP 再分支：paid→履约（级联子单）、failed/expired→取消退库存、null/pending/未配置→保守过期（fail-closed，绝不假判已付） | `PaymentGatewayInterface.php` + `PaymentGatewayService.php` + `ReconcileOrders.php` + `config/payment.php`（新增 query 端点） |
| 生产-开发网络隔离 | 运维 | 见"单容器扩缩容"中的 internal 网络；数据层与 API 层网络分段，DB/Redis 不发布、无出网 | `docker-compose.yml` |
| PDPD 跨境 A05 报备 | 法务 | 产出运维/法务行动清单 `docs/pdpd-cross-border-runbook.md`：法域判定（PIPL 不适用、PDPD 适用、跨境维度）、控制者/处理者角色、A05 报备 + DPA + 同意 + 72h 通报等上线前检查项（**提交动作需真人/越南律师完成**） | `docs/pdpd-cross-border-runbook.md` |

**新增回归测试**：
- `backend/tests/Feature/ReconcileGatewayTest.php`（3 例）：PSP 报 paid→履约级联+支付成功 / 报 null(fail-closed)→过期退库存+支付失败 / 报 failed→取消退库存。
- `backend/tests/Feature/CommissionPolicyTest.php`（3 例）：全局 `commission_rate` 进分账 / 默认 0 / 显式商户覆盖优先生效。
- 更新 `SeniorReviewFixesTest.php`：因 `ReconcileOrders` 构造函数注入 `PaymentGatewayInterface`，调用改为 `app(ReconcileOrders::class)->handle()`。

**仍待处理（本轮未覆盖 / 来自更早审查，非沙箱代码项）**：
- XSS CSP 严格化（nonce 化 / 去内联 `onclick`）——重构项，当前 `GN.esc()` + 全局 `connect-src 'self'` 已是纵深防御。
- 商业营收真实落地（广告/会员/商家增值/PSP 费转嫁商户）——需财务+商务决策（代码杠杆已就绪）。
- 注册 OTP 接真实短信网关、FCM v1 迁移——需凭证与渠道接入。
- ⚠️ 仍强烈建议 push 一次触发 CI（`pest` + `backend-smoke`）拿权威绿证。

## ✅ 第 5 轮「立即修复」闭环（2026-08-01 · 审查一般项）

将上一轮"仍待处理"中三条既有审查一般项全部闭环（沙箱无 PHP，PHP 改动静态对齐源码，权威验证待 CI `pest` 绿证）：

| 待处理项 | 严重度 | 根因 | 本轮落地 | 落地物 |
|---|---|---|---|---|
| 合并首单券事务外消耗 | 🟡 一般 | `storeMerged()` 在订单创建 `DB::transaction`（L143）**之前**调用 `resolveServerCoupon()`，其内部 `resolveNewUserCoupon()` 直接 `CouponRedemption::create()`（L221）；一旦订单事务失败回滚，用户的**一次性新人券被永久消耗**却无订单 | `resolveNewUserCoupon()` 改为**仅计算不落库**；新增 `grantNewUserCoupon()` 在事务内落库；`store()`/`storeMerged()` 各自在 `DB::transaction` 内调用 | `CreateOrderAction.php` + `OrderController.php` |
| 锁顺序死锁 | 🟡 一般 | `recordMerchantCoupon()` 在已被外层订单事务包裹的情况下又开**嵌套 `DB::transaction`** 并对 `coupons` 持 `lockForUpdate`，savepoint 使 coupon 锁跨整个外层事务持有，与另一路径对 `Order`/`Coupon` 的反向加锁序构成死锁隐患 | 去掉嵌套事务（始终在外层订单事务内调用），保留 `lockForUpdate`；新增**全局锁顺序约定注释**（事务内先锁 Order 再锁 Coupon） | `CreateOrderAction.php` |
| `OrderResource` 内部口径暴露 | 🟡 一般 | `merchant_settlement`/`commission`/`platform_subsidy` 三个内部财务口径**无条件返回给所有角色**，客户/骑手端可看到平台成本结构与商户结算额（商业情报泄露） | 按查看者角色脱敏：`merchant_settlement`+`commission` 仅 merchant/admin 可见；`platform_subsidy`（平台成本）仅 admin 可见；customer/rider 隐藏。已核验 `index.html`/`rider.html` 不依赖这三字段，仅 `admin.html`/`merchant-web.html`（对应 admin/merchant 角色）使用，门控后仍可取 | `OrderResource.php` |

**新增回归测试**：
- `backend/tests/Feature/PendingFixesTest.php`（6 例）：
  - `resolveServerCoupon` 仅计算不落库（平台折扣=20000 但无 redemption 行）；
  - `grantNewUserCoupon` 提交后落库 / 失败事务回滚后消失 / 同用户重复调用幂等（唯一索引 23000 被吞）；
  - `OrderResource` 对 customer、rider 隐藏全部三字段；对 merchant 显 commission+merchant_settlement（隐 platform_subsidy）；对 admin 显全部三字段。

**仍待处理（本轮未覆盖 / 来自更早审查）**：
- XSS CSP 严格化（nonce 化 / 去内联 `onclick`）——重构项。
- 商业营收真实落地——需财务+商务决策。
- 注册 OTP 接真实短信网关、FCM v1 迁移——需凭证与渠道接入。
- ⚠️ 仍强烈建议 push 一次触发 CI（`pest` + `backend-smoke`）拿权威绿证。
