# 安全渗透视角代码审计 · GIAONHANH 后端

> 审计时间：2026-08-01 ｜ 视角：安全工程师 / 渗透测试 ｜ 范围：`backend/`（Laravel 11 API）
> 方法：静态源码实查（沙箱无 PHP，未跑动态；CI 的 `pest`+`backend-smoke` 为动态验证权威）。
> 结论速览：**核心鉴权/防注入做得扎实，但"默认配置即不安全"——存在 1 个 CRITICAL（可伪造支付回调）、3 个 HIGH（调试模式默认开、admin 硬编码密码、CORS 通配）、4 处 MEDIUM/LOW。**

---

## 0. 总评分（按维度）

| 维度 | 评级 | 说明 |
|---|---|---|
| SQL 注入 | ✅ 优 | 全量 Eloquent 参数绑定，无 `whereRaw`/`DB::raw`/`DB::select`/`union`，`like` 走绑定（仅通配符滥用，非注入） |
| XSS | ⚠️ 中 | 纯 JSON API 不执行；风险在 **Web 控制台（admin.html / merchant-web.html）** 若用 `innerHTML` 渲染用户自由文本 |
| 鉴权绕过 | 🔴 严重 | 默认 `sandbox=true` + 硬编码沙箱验签密钥 → **任意人可伪造"已支付"回调** |
| 密钥/密码硬编码 | 🔴 高 | `.env.example` 调试默认 `true`；`DatabaseSeeder` 非生产 admin 密码 `GiaoNhanh#Admin#2026`；沙箱密钥硬编码 |
| 依赖已知漏洞 | ⚠️ 待 CI | 无 `composer.lock`，沙箱无 PHP，**无法跑 `composer audit`**；版本栈较新（Laravel11/Sanctum4/PHP8.2），需 CI 接 audit + Dependabot |
| 输入校验 | ✅ 良 | phone regex、qty max、items/groups 上限已加；但登录限流宽松、公开注册无 throttle |
| 敏感数据明文存储 | ⚠️ 中 | 密码 bcrypt✅、refresh_token sha256✅；但 `bank_account`/`business_license`/手机号/地址/经纬度 明文；admin 接口返回原始模型 |

---

## 1. CRITICAL — 默认配置下可伪造"已支付"回调（支付完整性绕过）

**位置**
- `config/payment.php:19` → `'sandbox' => env('PAYMENT_SANDBOX', true)`（**默认 true**）
- `config/payment.php:29` → `'sandbox_secret' => env('PAYMENT_SANDBOX_SECRET', 'GIAONHANH_SANDBOX_SECRET')`（**硬编码默认密钥**）
- `app/Services/PaymentGatewayService.php:165-175` `resolveVerifyKey()`：当 `sandbox=true` 且 `sandboxSecret!==''` 时，**返回该硬编码密钥用于验签**

**漏洞链条**
真实网关密钥路径是 fail-closed（缺失即拒），但 **sandbox 路径用硬编码常量做验签**。该常量同时出现在源码与 `.env.example:52`。IPN 路由 `/payments/momo/ipn`、`/payments/zalopay/callback`、`/payments/aggregator/{name}/callback` 均为**公开、无鉴权**（`routes/api.php:50-52`）。

**可利用场景**
1. 攻击者获取仓库/`.env.example` → 拿到 `GIAONHANH_SANDBOX_SECRET`。
2. 构造任意 `order_no` + `amount`，用该密钥算 HMAC（MoMo `signature` / ZaloPay `data+mac`）。
3. `POST /api/v1/payments/momo/ipn`（或 zalopay/callback，限流 120/min 仍充足）。
4. 验签通过 → 订单被标记为 `paid` → **未付款即发货 / 刷 GMV / 对账欺诈**。
5. 任何"忘记设置真实密钥且未关 sandbox"的部署（默认值即如此）在生产上**对知道该常量的人完全可伪造**。

**修复（必须）**
- `config/payment.php:19`：`'sandbox' => env('PAYMENT_SANDBOX', false)`（默认关）。
- `config/payment.php:29`：移除硬编码默认 → `'sandbox_secret' => env('PAYMENT_SANDBOX_SECRET', '')`；并让 `resolveVerifyKey` 在 sandbox 下**也** fail-closed（secret 为空即返回 null），即"沙箱验签也须显式配置密钥"。
- 文档明确：生产必须 `PAYMENT_SANDBOX=false` 并配置真实 `MOMO_*`/`ZALOPAY_*`。

---

## 2. HIGH — `.env.example` 默认 `APP_DEBUG=true`

**位置**：`backend/.env.example:4` → `APP_DEBUG=true`；`composer.json:41` 安装脚本 `copy('.env.example', '.env')`。

**可利用场景**
任何按标准流程安装（或 CI 误复制）的环境，`.env` 直接继承 `APP_DEBUG=true`。生产开启 debug → 异常页/JSON 暴露 **ENV 密钥、DB 结构、SQL、堆栈、内部路径**，为后续漏洞利用提供地图。Laravel 官方模板默认 `false`。

**修复**：`.env.example` 改为 `APP_DEBUG=false`；安装脚本不要无脑复制，或复制后强制覆盖 debug=false。

---

## 3. HIGH — Admin 种子账号硬编码密码

**位置**：`database/seeders/DatabaseSeeder.php:23`
```php
$adminPassword = env('ADMIN_SEED_PASSWORD', app()->isProduction()
    ? Str::random(24) : 'GiaoNhanh#Admin#2026');
```
**可利用场景**
- 当 `APP_ENV` 为 `local/staging/dev`（非精确 `production`，而这是非生产环境最常见值）时，`isProduction()` 返回 false → admin 密码被设为**源码里写死的 `GiaoNhanh#Admin#2026`**。
- 任何暴露的 staging/preview 环境若运行 `db:seed` → 攻击者用该已知密码 + 公开手机 `0900000001` 直接登 admin，接管全平台（审批商家、KYC、结算打款接口）。
- 密码本身已提交仓库，属"已知凭证"。

**修复**：彻底移除 dev 兜底，始终 `env('ADMIN_SEED_PASSWORD')` 缺失则 `abort`/随机并**打印到日志而不落库明文**；staging 也必须随机；上线前轮换并删除该字符串。

---

## 4. HIGH — CORS 通配 + 凭据

**位置**：`config/cors.php:19` `allowed_origins => explode(',', env('CORS_ALLOWED_ORIGINS', '*'))`；`:29` `supports_credentials => true`；`bootstrap/app.php:22` 全局挂 `HandleCors`。

**可利用场景**
`*` + `supports_credentials:true` 时，Laravel CORS 中间件会**反射请求 Origin** 到 `Access-Control-Allow-Origin` 并带 `Allow-Credentials:true`。结果：任意网站可发起**带凭据的跨域请求**到本 API。虽当前鉴权用 Bearer Token（非 Cookie，直接窃 token 受限），但仍允许任意前端站调用 API（数据抓取、CSRF 式状态变更若 token 经某处落入请求）。属典型错误配置。

**修复**：`CORS_ALLOWED_ORIGINS` 显式白名单（Capacitor `capacitor://localhost` + 自有域名）；非必要关闭 `supports_credentials`（纯 Bearer 无需凭据）。

---

## 5. MEDIUM — 登录暴力破解防护弱

**位置**：`bootstrap/app.php:31` `'auth' => ... ':60,1'`（每 IP 60 次/分钟）；`routes/api.php:37-41` login/register/refresh 仅挂 `throttle:auth`。

**可利用场景**
手机号+密码登录：单 IP 每分钟 60 次尝试，无按手机号计数、无锁定、无验证码。弱密码用户可被在线爆破；`register` 可被批量刷号。

**修复**：限流改为 `phone + IP` 复合键、降至 5–10/min；连续失败 N 次临时锁定；生产启用真实短信 OTP（代码注释已承诺但未实现）。

---

## 6. MEDIUM — 公开 `/agents` 注册无 throttle

**位置**：`routes/api.php:35` `Route::post('/agents', ...)` 在 Public 段，无 `throttle`；`AgentController::store` 仅做字段校验。

**可利用场景**
匿名可无限提交代理申请 → 垃圾数据/存储滥用/手机号枚举；无验证码。

**修复**：加 `throttle` + 简单人机校验；或移至需登录。

---

## 7. MEDIUM/LOW — 存储型 XSS 取决于前端渲染

**位置（后端返回的自由文本，未转义）**：
- `OrderResource`：`note`/`contact_name`/`address`（`app/Http/Resources/OrderResource.php:41-46`）
- `Merchant`：`business_hours`（经 MerchantResource）
- `AgentApplication`：`channel_desc`/`reject_reason`/`note`（`AgentController` 直接 `response()->json($agent)`）
- `Merchant.reject_reason`（`AdminController::rejectMerchant`）

**可利用场景**
后端是 JSON API，本身不执行脚本。但若 **admin.html / merchant-web.html（Web 控制台）** 用 `innerHTML`/`v-html` 直接插入上述字段 → 存储型 XSS：攻击者在下单备注/商家简介注入 `<img onerror>` 或 `<script>`，管理员/商家打开即被执行（窃取 admin token、篡改页面）。

**修复**：前端一律用 `textContent`/框架自动转义；加 CSP（`default-src 'self'`）；后端可对自由文本做基础净化（非必须，前端转义为主）。

---

## 8. LOW — `User.$fillable` 含 `role`（潜在越权升级）

**位置**：`app/Models/User.php:15` `fillable` 含 `'role'`。

**现状**：当前所有建号路径均**显式**写 `role`（register 强制 `customer`、seeder 显式），无误用 mass-assignment 取请求 `role` 之处 → 目前安全。
**风险**：未来若有人写 `User::create($request->validated())` 或 `User::update($request->all())` 且请求带 `role` → 直接提权为 admin。属"守门员依赖人"的隐患。

**修复**：从 `fillable` 移除 `role`，改为显式赋值（与现有做法一致，纵深防御）。

---

## 9. LOW — 敏感 PII 明文存储

- 已做对的部分：`password` bcrypt（`Hash::make`）、`refresh_tokens.token_hash` sha256（明文不落库）、`User.$hidden` 隐藏 password/remember_token。
- 明文项：`Merchant.bank_account` / `business_license`、`User.phone`、`Order.contact_phone`/`address`/`lat`/`lng`、FCM `device_token`、`Payment.raw`（存完整网关回调，可能含 PII/签名）。
- 合规提示：越南《个人数据保护法》(PDPD 2023) 下，银行账号/手机号/地址属敏感个人数据，建议加密存储 + 最小可用返回；admin 接口应走 Resource 而非原始模型（`AdminController::merchants` 直接 `paginate()` 暴露 `bank_account` 等）。

---

## 10. 依赖漏洞（待 CI）

- 沙箱无 PHP/Composer，**无法跑 `composer audit`**；仓库也**无 `composer.lock`**。
- 当前版本栈现代（Laravel `^11`、Sanctum `^4`、PHP `^8.2`、`doctrine/dbal ^3.8`、Pest `^3`），无已知的 EOL 依赖。
- **必须在 CI 加 `composer audit`（需先 `composer update` 生成 lock）** + 启用 Dependabot/Renovate，否则无法证明无 CVE。这是融资 DD 的常见扣分项。

---

## 11. 已做扎实的防护（正面清单，勿回退）

- ✅ **零 SQL 注入面**：全 Eloquent 参数绑定；`like "%{$q}%"` 虽拼接但值走绑定，非注入（仅通配符滥用）。
- ✅ **支付验签 timing-safe**：全程 `hash_equals`；真实网关密钥 fail-closed（缺失即拒伪造回调）。
- ✅ **鉴权分层严密**：`auth:sanctum` + `ability:customer|merchant|rider|admin`；admin 全路由 `ability:admin`；`role` 永不取客户端；register 强制 customer。
- ✅ **防 IDOR**：订单路由按 `order_no` 绑定（`Order::getRouteKeyName`），非自增 id；`OrderPolicy` 归属校验；商家/骑手操作均校验 `merchant_id`/`rider_id` 归属（403）。
- ✅ **令牌安全**：access 2h 过期（Sanctum 原生拒绝 + `token.expiry` 中间件双保险）；refresh 轮换 + 单活跃会话 + sha256 存储。
- ✅ **无危险函数**：无 `eval`/`unserialize`/`exec`/`shell_exec`/`proc_open`；无文件上传（image 为字符串 URL，规避上传 RCE/SSRF）。
- ✅ **输入校验**：phone 正则、qty max:9999、items max:50、groups max:20（前次 QA 修复已加）。

---

## 12. 修复优先级

| 优先级 | 项 | 工作量 |
|---|---|---|
| P0 | #1 沙箱默认配置可伪造支付 → 改 `sandbox` 默认 false + 移除硬编码 secret 默认 | 极小 |
| P0 | #2 `.env.example` `APP_DEBUG` 改 false | 极小 |
| P0 | #3 admin 种子密码去硬编码 + 随机化 | 极小 |
| P1 | #4 CORS 白名单 + 关凭据 | 小 |
| P1 | #5 登录限流降速 + 按手机号锁定 | 小 |
| P1 | #6 `/agents` 加 throttle | 极小 |
| P2 | #7 前端转义 + CSP（需前端审计配合） | 中 |
| P2 | #8 `User` 移除 `role` from fillable | 极小 |
| P2 | #9 PII 加密 + admin 走 Resource | 中 |
| P2 | #10 CI 加 `composer audit` + Dependabot | 小 |

> 注：P0 三项均为"配置/常量"级，不影响业务逻辑，却直接关系资金安全与密钥泄露，**建议立即改**，且改完需推一次 CI 拿 `pest`+`backend-smoke` 双绿证。

---

## 13. 修复状态（2026-08-01 续，已落地）

用户指令"立即修"，本会话已闭环 P0 全部 + P1 #4/#6 + P2 #9(部分)。改动文件：

| 审计项 | 修复 | 文件 |
|---|---|---|
| #1 CRITICAL 沙箱伪造 | `sandbox` 默认 `true→false`（安全默认）；`.env.example` `PAYMENT_SANDBOX=true→false`。dev 密钥常量保留但**仅**在显式 `sandbox=true` 时生效（CI/自测仍可跑，签名测试 `putenv('PAYMENT_SANDBOX=true')` 不受影响）。默认部署走真实密钥 fail-closed，公开 IPN 不再可被伪造 | `config/payment.php`、`backend/.env.example` |
| #2 HIGH 调试默认开 | `.env.example` `APP_DEBUG=true→false`、`LOG_LEVEL=debug→info`（composer create-project 复制为 `.env` 即安全） | `backend/.env.example` |
| #3 HIGH admin 硬编码密码 | 移除非生产 fallback `GiaoNhanh#Admin#2026`；改为 `env('ADMIN_SEED_PASSWORD', Str::random(24))` 并 `info()` 记录随机密码。CI 已显式设 `ADMIN_SEED_PASSWORD=SmokeAdmin#2026` 不受影响 | `database/seeders/DatabaseSeeder.php` |
| #4 HIGH CORS 凭据 | `supports_credentials=true→false`（API 用 Sanctum Bearer，不需 cookie）；`allowed_origins` 维持 env 驱动 | `config/cors.php` |
| #6 `/agents` 无 throttle | 注册 `api` 限流器(30/min) + 公开 `POST /agents` 加 `throttle:api` | `bootstrap/app.php`、`routes/api.php` |
| #9(部分) PII 明文 | `Merchant` 模型 `$hidden` 加入 `bank_account` → admin 列表裸 `paginate` 不再泄露银行卡号（商家 profile 走 `MerchantResource` 本就不含该字段，无副作用） | `app/Models/Merchant.php` |

**未处理（仍存，已记入路线图）**：#5 登录降速/锁定、#7 前端转义+CSP（需前端审计配合）、#8 `User.role` 移除 fillable（当前无误用，风险低）、#9 PII 字段加密存储、`#10` CI `composer audit`+Dependabot。

**验证**：`grep env( backend/app` 零残留；`verify-contract.mjs` 56/56 PASS（退出 0）。沙箱无 PHP，CI 双绿证为权威（需推一次）。

**本地开发备忘**：此前本地预览默认走沙箱收银台（pay-mock.html）；现在安全默认关闭沙箱，本地开发需在 `.env` 显式设 `PAYMENT_SANDBOX=true` + `PAYMENT_SANDBOX_SECRET=GIAONHANH_SANDBOX_SECRET` 才能跑通 MoMo/ZaloPay mock 全流程。
