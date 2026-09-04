# 资深审查者挑刺报告（生产就绪性 / 安全 / 并发正确性）
> 审查对象：**2026-08-01「立即修复」轮新写/改的代码**（地理围栏、注册 OTP、PSP 费写入、reconcile 自愈、账户注销、跨境声明）。
> 立场：代码要上生产、面对真实用户和黑客。沙箱无 PHP，全部文件未经任何测试运行器验证。
> 基调：只挑刺，不捧场。

---

## 🔴 致命（修复本身失效 / 资损）

### F1. PSP 费根本没写进 `orders` 表 —— 红队老板#1 的修复是死代码
- **定位**：`app/Http/Controllers/Api/PaymentController.php::pay()` L63-64；`app/Models/Order.php` L15-22（`$fillable`）。
- **严重程度**：致命
- **为什么是问题**：
  `pay()` 里 `$locked->update(['psp_fee' => $pspFee, 'psp_fee_bearer' => $pspBearer])` 依赖 `Order` 的批量赋值。但 `Order::$fillable` **根本没有 `psp_fee` / `psp_fee_bearer`**。Eloquent 的 `update()` 走 `fill()`，会**静默丢弃**不在 fillable 里的字段——查询照常执行，只是这两列永远不写。
  结果：`orders.psp_fee` 永远是 `NULL`，单位经济**依然全盲**。上一轮号称"堵住老板#1"的改动，上线后毫无效果。`payments.psp_fee` 能写（Payment 的 fillable 有它），但财务报表主口径在 `orders`，所以口径也不一致。
- **修改建议**：把两列加进 `Order::$fillable`（并补 cast）：
  ```php
  // app/Models/Order.php
  protected $fillable = [
      'order_no', 'user_id', 'merchant_id', 'rider_id', 'type', 'parent_order_no', 'group_delivery_fee',
      'product_amount', 'delivery_fee', 'coupon_id', 'coupon_discount', 'platform_subsidy', 'commission',
      'amount', 'merchant_settlement', 'status', 'delivery_type', 'expect_time',
      'pay_method', 'address', 'lat', 'lng', 'contact_name', 'contact_phone', 'note',
      'paid_at', 'accepted_at', 'picked_at', 'delivering_at', 'delivered_at',
      'refunded_at', 'refund_error',
      'psp_fee', 'psp_fee_bearer',   // ← 补上，否则 update() 静默丢弃
  ];

  protected $casts = [
      // ...
      'psp_fee' => 'decimal:2',
      'psp_fee_bearer' => 'string',
  ];
  ```
  > 验证：加完后在 `pay()` 后断言 `Order::find(...)->psp_fee` 非 null。当前 `RedTeamFixesTest` 只断言了 `payments.psp_fee`，没断言 `orders.psp_fee`——所以连测试都没抓住这个失效。

---

## 🟠 严重（安全控制被绕过 / 数据损坏 / 运维缺口）

### S1. `nearby` 地理围栏用的是**客户端传的坐标** —— 围栏是假的，全国订单仍可枚举
- **定位**：`app/Http/Controllers/Api/RiderController.php::nearby()` L34-35、L46-55。
- **严重程度**：严重
- **为什么是问题**：
  ```php
  $lat = $request->query('lat', $rider->lat);
  $lng = $request->query('lng', $rider->lng);
  ```
  围栏（`whereBetween('lat'...)` / `whereBetween('lng'...)`）是围绕**攻击者自己传入的 lat/lng** 建的。恶意骑手只要把 lat/lng 扫遍越南（每 10km 一格），就能把全部 `picked` 未指派订单一格格拖出来。客户端坐标只能用于"显示距离"，**绝不能用于"谁能看到这张单"的围栏**。当前实现只是把枚举从"一次全返回"变成"走 10km 网格慢慢爬"，节流 `api=30/min` 只是拖慢，不阻断。
- **修改建议**：围栏**只信服务端存的坐标**（`riders.lat/lng`，来自 `updateLocation`）；客户端 lat/lng 仅用于距离展示。
  ```php
  public function nearby(Request $request)
  {
      $rider = $request->user()->rider;
      if (! $rider || ! $rider->lat || ! $rider->lng) {
          // 无服务端可信坐标 → 只返自己的单，绝不返未指派单
          return GrabOrderResource::collection(
              $rider ? Order::with('items','merchant')->where('rider_id',$rider->id)
                      ->whereIn('status',['paid','accepted','picked'])->paginate(30)
                    : collect()
          );
      }
      $lat = (float) $rider->lat;   // 围栏只用服务端坐标
      $lng = (float) $rider->lng;
      $clientLat = $request->query('lat');  // 仅展示用
      $clientLng = $request->query('lng');
      // ... 围栏 WHERE 用 $lat/$lng，distance 显示用 $clientLat/$clientLng（回退到 $lat/$lng）
  }
  ```
  > 真正的兜底还要加：对 `nearby` 做账号级频控 + 异常地理跳跃检测（同一骑手 1 分钟内出现在河内和胡志明 = 爬虫）。

### S2. `verifyRegistration` 只有 IP 级 10/min 节流，**没有单次 OTP 尝试上限** —— 6 位 OTP 可被分布式爆破
- **定位**：`app/Http/Controllers/Api/AuthController.php::verifyRegistration()` L127-157；路由 L39（仅 `throttle:auth`）。
- **严重程度**：严重
- **为什么是问题**：
  `register` 端点节流 10/min/IP，但那是"申请 OTP"的节流；`verifyRegistration` 同样 10/min/IP。10/min/IP 对 6 位 OTP（100 万组合）意味着单 IP 约 16 小时可爆破完；**分布式多 IP 可并行压**。更关键：没有任何"同一 OTP 错 5 次即作废"的逻辑，攻击者可以拿着同一个 pending OTP 无限试错（60s 冷却只限制重新申请，不影响拿着旧 OTP 猛试）。这正是红队#2"假账号 farm"的对岸——OTP 机制形同虚设。
- **修改建议**：加 per-phone OTP 尝试计数，满 5 次直接作废 pending，必须重新注册：
  ```php
  // verifyRegistration() 内，取 pending 之前：
  $attemptKey = 'otp_attempts:' . $data['phone'];
  if ((int) Cache::get($attemptKey) >= 5) {
      Cache::forget('reg:pending:' . $data['phone']);
      Cache::forget($attemptKey);
      return response()->json(['message' => 'Quá nhiều lần thử sai. Vui lòng đăng ký lại.'], 429);
  }
  // 校验失败分支：
  if (! hash_equals(...)) {
      Cache::add($attemptKey, 0, now()->addMinutes(10)); // 首次建立计数（带 TTL）
      Cache::increment($attemptKey);
      return response()->json(['message' => 'Mã xác thực không đúng.'], 422);
  }
  Cache::forget($attemptKey); // 成功后清空
  ```

### S3. `ReconcileOrders` 库存回滚**不在事务内** —— 进程崩溃会双倍回滚、库存膨胀
- **定位**：`app/Console/Commands/ReconcileOrders.php::handle()` L42-50。
- **严重程度**：严重
- **为什么是问题**：
  ```php
  foreach ($stale as $order) {
      foreach ($order->items as $item) {
          Product::where('id', $item->product_id)->where('stock','>=',0)->increment('stock', $item->qty);
      }
      $order->update(['status' => 'cancelled']);   // 与上面的 increment 不在同一事务
  }
  ```
  若进程在 `increment` 之后、`update('cancelled')` 之前崩溃（或被 kill），下一次调度该订单仍是 `pending_payment` → **再回滚一次** → 库存凭空 +qty。此外 `where('stock','>=',0)` 是永远成立的废条件（stock 不可能为负），起不到任何保护作用；`$order->items` 还是懒加载（N+1）。
- **修改建议**：整单回滚包进一个事务，并对商品加行锁，保证"回滚库存"与"置 cancelled"原子：
  ```php
  DB::transaction(function () use ($order) {
      foreach ($order->items as $item) {
          Product::where('id', $item->product_id)->lockForUpdate()
                 ->increment('stock', $item->qty);
      }
      $order->update(['status' => 'cancelled']);
  });
  ```
  > 顺带：`reserveStock`（下单时扣库存）扣的是 `stock`+`flash_stock`？若 reconcile 只回 `stock` 不回 `flash_stock`，两侧不一致，需对齐。

### S4. 自愈命令已注册，但**部署没有 scheduler 进程** —— `orders:reconcile` 在生产是死代码
- **定位**：`routes/console.php` L13（已注册）；`bootstrap/app.php` L16（commands 已加载）；`docker-compose.yml`（无 scheduler 服务）。
- **严重程度**：严重
- **为什么是问题**：
  代码层面调度确实注册了（`Schedule::command('orders:reconcile')->everyFiveMinutes()`）。但 Laravel 调度**必须由 OS 周期性调用 `php artisan schedule:run` 才会执行**。当前 `docker-compose.yml` 只有 web 容器，没有 cron / supervisor / 独立 scheduler 服务。结果：这个自愈命令**永远不会自己跑**，上一轮"卡死订单自愈"的承诺在生产环境落空——IPN 一丢，订单照样永久卡死。
- **修改建议**：加一个 scheduler 服务（或进 supervisord）：
  ```yaml
  # docker-compose.yml（节选）
  scheduler:
    build: .
    command: sh -c "while true; do php artisan schedule:run --no-interaction; sleep 60; done"
    depends_on:
      - app
      - db
    env_file: .env
  ```
  > 同时确认 `php artisan schedule:run` 在容器里能拿到正确的 `.env`/`config:cache`，否则命令跑起来读不到配置。

### S5. 账户注销**未脱敏历史订单里的客户 PII** —— 注销即"擦除"是假的
- **定位**：`app/Http/Controllers/Api/AuthController.php::destroyAccount()` L266-311；`orders` 表 `contact_name`/`contact_phone`/`address`/`lat`/`lng`（明文，无加密、无脱敏）。
- **严重程度**：严重
- **为什么是问题**：
  `destroyAccount` 把 `users`/`merchant`/`rider` 的 PII 置空并软删，但**完全没动 `orders` 表**。客户的历史订单里 `contact_name`、`contact_phone`、`address`、`lat`、`lng` 仍是明文。用户以为"注销=删号"，实际他的姓名+电话+精确住址永久留在 `orders` 里。这直接违反 PDPD 的"被遗忘权"精神（你说保留是为了税务/AML，但保留的应是**交易记录**，不是可识别的自然人 PII；两者应分离）。
- **修改建议**：注销时一并匿名化该用户订单的可识别字段（保留订单/金额/商户/商品用于审计）：
  ```php
  // destroyAccount() 内，软删 user 之前：
  Order::where('user_id', $user->id)->update([
      'contact_name'  => null,
      'contact_phone' => null,
      'address'       => null,
      'lat'           => null,
      'lng'           => null,
      'note'          => null,
  ]);
  ```
  > 若合规上必须保留配送信息，则至少对 `contact_phone`/`address` 做加密存储，且查询需授权。

---

## 🟡 一般（逻辑/一致性瑕疵）

### N1. `verifyRegistration` 并发竞态未捕获唯一约束冲突 → 第二并发请求 500
- **定位**：`AuthController::verifyRegistration()` L135（exists 检查）→ L147（`User::create`）。
- **严重程度**：一般
- **为什么是问题**：`exists()` 与 `create()` 之间有两个并发请求同时通过校验，第二个 `create` 撞 `users.phone` 唯一索引 → 未捕获的 `SQLSTATE[23000]` → 500。应在 `create` 外套 `try/catch` 或包进事务 + 捕获 `UniqueConstraintViolation`。
- **修改建议**：
  ```php
  try {
      $user = User::create([...]);
  } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
      return response()->json(['message' => 'Số điện thoại đã được đăng ký.'], 409);
  }
  ```

### N2. `register` 用 `Rule::unique('users','phone')` → 注册接口泄露"谁已注册"
- **定位**：`AuthController::register()` L87；路由 L38。
- **严重程度**：一般
- **为什么是问题**：已注册手机号在 step1 即返回 422（验证失败），未注册返回 200 → 攻击者可用注册接口做手机号枚举（与 `login` 的 404/401 差异同理）。影响有限，但属于红队#3 同类缺陷。
- **修改建议**：step1 不要把 `unique` 放进验证规则；改成总是接受并写入 pending，step2 的 exists 检查返回统一文案（"该号码已注册，请直接登录"）——即便如此仍透露存在性，但至少不在验证层做时序差异。或干脆接受这是注册场景的正常 UX，在威胁模型里标注"低风险"。

### N3. `ReconcileOrders` 步骤 2 会把"已支付订单的 pending 支付"标 `failed` → 财务状态错配
- **定位**：`ReconcileOrders.php` L59-66。
- **严重程度**：一般
- **为什么是问题**：步骤 2 选所有 `status=pending` 且超时的 Payment，无视其订单当前状态。极端竞态下（订单已被 IPN 翻 `paid` 但 Payment 行还未来得及翻 `success`），会被错标 `failed`，造成"订单已付、支付却失败"的对账缺口。概率低，但财务对账会报警。
- **修改建议**：步骤 2 只处理其订单仍为 `pending_payment` 的支付：
  ```php
  $stalePay = Payment::where('status','pending')
      ->where('created_at','<=',$cutoff)
      ->whereHas('order', fn ($q) => $q->where('status','pending_payment'))
      ->get();
  ```

---

## 🟢 建议（设计/口径）

- **G1 — PSP 费率硬编码假设**：`config/payment.php` 用固定 `psp_fee_rate=0.025`。真实 MoMo/ZaloPay 按通道/商户等级浮动（1.5%–3.5%）。当前只在下单时按假设值记账，`payments.psp_fee` 可能与实际扣费不符。建议：钱包创建/对账时以**网关返回的真实手续费**回填，或由财务定期校准。
- **G2 — `GrabOrderResource` 仍向半径内所有骑手暴露客户精确 GPS**（`address`/`lat`/`lng`）。配送必需，但违反 PDPD"精确位置最小化"。建议：抢单列表只给"商户→大致片区"，精确落点仅在 `accept` 后通过 `OrderResource` 揭示；或对该坐标做 coarse-graining。
- **G3 — `nearby` 分页无总页数/是否末页信号**：大市场下客户端无法判断是否拉完。建议透传 `last_page`/`total`，或加游标。
- **G4 — 这批修复**全部未经任何运行器验证（沙箱无 PHP）。`RedTeamFixesTest` 的 PSP 断言只验了 `payments.psp_fee`，漏验 `orders.psp_fee`（所以 F1 没被测试抓住）。**强烈建议 push 一次触发 CI 的 `pest` + `backend-smoke`，用绿证代替"我认为对了"**。

---

## 综合结论（不捧场版）
上一轮"立即修复"的初衷是对的，但**落地质量不达标**：
- **1 个致命**：PSP 费写入因 `Order` 缺 fillable 字段而完全失效，红队老板#1 没真正堵住。
- **5 个严重**：围栏用客户端坐标（枚举照旧）、OTP 无尝试上限（可被爆破）、reconcile 库存回滚非原子（库存可膨胀）、调度无进程（自愈是死代码）、注销不脱敏订单 PII（被遗忘权落空）。
- 测试也未覆盖这些失效点。

**上线前必须修 F1 + S1~S5**，否则这批"修复"只是看起来修好了。
