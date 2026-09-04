# GIAONHANH Backend — Vietnam On-Demand Delivery API

越南全域小时达 · 同城购物配送平台后端（Laravel 11 API）。
实现：**0 佣金 + 配送费平台补贴** 的商家入驻、商品、订单、支付分账、骑手调度。

---

## 1. 安装运行

本目录是 Laravel 工程的 **应用层覆盖包**（不含框架自带 `config/` 与 `vendor/`）。
两种方式，任选其一：

### 方式 A（推荐）：叠加到全新 Laravel 工程
```bash
composer create-project laravel/laravel giaonhanh
cd giaonhanh
# 把本目录的以下内容覆盖进去：
#   app/  routes/  database/  composer.json  .env.example  artisan  bootstrap/
cp -r backend/app ./app
cp -r backend/routes ./routes
cp -r backend/database ./database
cp backend/composer.json ./composer.json
cp backend/.env.example ./.env.example
cp backend/artisan ./artisan
cp -r backend/bootstrap ./bootstrap

composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # 建表 + 11 大品类 + 样例商家/商品/骑手
php artisan serve               # http://localhost:8000
```

### 方式 B：直接在本目录
确保本机已装 PHP 8.2+ 与 Composer，然后：
```bash
composer install
cp .env.example .env && php artisan key:generate
# 手动补一份最小 config/（从 laravel/laravel 骨架复制 config/ 目录即可）
php artisan migrate --seed
php artisan serve
```

---

## 2. 业务规则（0 佣金 + 配送补贴）

核心逻辑在 `app/Services/PaymentSplitService.php`，下单时由 `OrderController` 调用：

| 字段 | 含义 | 取值 |
|---|---|---|
| `commission` | 平台抽成 | **恒为 0** |
| `delivery_fee` | 商家标准配送费 | 由商家设置 |
| `platform_subsidy` | 平台承担 = 补贴的配送费 + 平台出资优惠券 | 自动计算 |
| `coupon_discount` | 新人券（平台出资） | 默认 `NEW_USER_COUPON_AMOUNT=20000` ₫ |
| `merchant_settlement` | 商家实收 | = 商品金额（0 佣金）；未补贴时另加配送费 |
| `amount` | 用户应付 | = 商品金额 +（未补贴时配送费）− 优惠券 |

> 平台通过 `delivery_subsidy=true` 把配送费补贴给骑手，商家与用户两端均 0 配送费感知。

---

## 3. API 端点

基础路径：`/api`，返回 JSON。鉴权：`Authorization: Bearer <sanctum_token>`，
token 按角色签发能力（ability）：`customer` | `merchant` | `rider` | `admin`。

### 公开
| Method | Path | 说明 |
|---|---|---|
| GET | `/api/categories` | 11 大类 + 子类目树 |
| GET | `/api/merchants?category_id=&q=&lat=&lng=` | 已开业商家（支持距离排序） |
| GET | `/api/merchants/{id}` | 商家详情 |
| GET | `/api/merchants/{id}/products?subcategory_id=` | 商家商品 |
| GET | `/api/flash-sales?lat=&lng=` | 限时秒杀 |
| POST | `/api/agents` | 区域代理申请 |
| POST | `/api/auth/register` | 注册（手机，模拟 OTP） |
| POST | `/api/auth/login` | 登录（手机） |

### 用户 customer
| Method | Path | 说明 |
|---|---|---|
| GET | `/api/me` | 我的资料 |
| POST | `/api/orders` | **下单**（自动分账） |
| GET | `/api/orders` | 我的订单 |
| GET | `/api/orders/{no}` | 订单详情 + 配送轨迹 |
| POST | `/api/orders/{no}/pay` | **发起支付**：COD 同步入账；MoMo/ZaloPay 返回 `pay_url` 并创建 pending 支付 |
| GET | `/api/orders/{no}/payment-status` | 轮询支付结果（钱包支付后前端据此刷新） |
| POST | `/api/orders/{no}/cancel` | 取消订单 |

### 商家 merchant
| Method | Path | 说明 |
|---|---|---|
| POST | `/api/merchant/onboard` | **入驻申请**（→ pending） |
| GET | `/api/merchant/me` | 商家资料 |
| GET | `/api/merchant/orders` | 商家订单 |
| POST | `/api/merchant/orders/{no}/accept` | 接单 |
| POST | `/api/merchant/orders/{no}/ready` | 已打包（待骑手取货） |

### 骑手 rider
| Method | Path | 说明 |
|---|---|---|
| GET | `/api/rider/orders?lat=&lng=` | 附近待取订单 |
| POST | `/api/rider/location` | 上报实时位置 |
| POST | `/api/rider/orders/{no}/accept` | 取货出发（→ 配送中） |
| POST | `/api/rider/orders/{no}/deliver` | 送达（→ 已送达） |

### 管理员 admin
| Method | Path | 说明 |
|---|---|---|
| POST | `/api/admin/merchants/{id}/approve` | 审核通过（锁定 0 佣金+补贴） |
| POST | `/api/admin/merchants/{id}/reject` | 驳回 |
| GET | `/api/admin/agents` | 代理申请列表 |
| POST | `/api/admin/agents/{id}/approve` | 通过代理 |

### 支付网关回调（公开，签名校验）
| Method | Path | 说明 |
|---|---|---|
| POST | `/api/payments/momo/ipn` | **MoMo IPN**：HMAC-SHA256 验签，成功置 Payment=success、订单=paid、就近派单 |
| POST | `/api/payments/zalopay/callback` | **ZaloPay 回调**：data(base64)+mac(key2) 验签，同上 |

---

## 4. 支付与 IPN（MoMo / ZaloPay）

核心在 `app/Services/PaymentGatewayService.php`，所有**签名与验签均按官方规范真实实现**（HMAC-SHA256）。

- **MoMo**：下单签名串 `accessKey=…&amount=…&…&requestType=payWithMethod`；IPN 验签串 `accessKey=…&amount=…&…&transId=…`。`resultCode=0` 即支付成功。
- **ZaloPay**：下单用 `key1` 对 `appid|apptransid|appuser|amount|apptime|embeddata|item` 签名；回调 `data`(base64)+`mac` 用 `key2` 验签。
- **沙箱模式**（未填 `MOMO_*`/`ZALOPAY_*` 或 `PAYMENT_SANDBOX=true`）：不连真实 PSP，而是把**服务端生成的真实签名**嵌入 `public/pay-mock.html` 收银台链接；用户在沙箱页点「已支付」→ 浏览器 POST 到 IPN → 后端**真实验签**通过后置订单为 paid。**只有外部 PSP 被桩替，验签链路是端到端真跑的。**

### 支付时序
```
客户 POST /orders                 -> 创建订单(pending_payment)，分账计算完成
客户 POST /orders/{no}/pay{method}
      cod                        -> Payment(success) + 订单 paid + 就近派单（同步）
      momo / zalopay            -> Payment(pending) + 返回 pay_url（钱包/沙箱收银台）
用户完成支付
      -> MoMo/ZaloPay 回调 /api/payments/{momo/ipn | zalopay/callback}
         -> 验签通过 -> Payment(success) + 订单 paid + 就近派单
前端轮询 GET /orders/{no}/payment-status 直到 paid
```

### 本地验证 IPN（沙箱）
```bash
php artisan serve --port=8000
# 用 app/pay-demo.html 下单并选 MoMo/ZaloPay，会打开 http://localhost:8000/pay-mock.html?... 收银台
# 点「我已支付」-> 页面 POST 到 IPN -> resultCode=0 / return_code=1
# 轮询 payment-status 返回 paid=true，订单进入派单
```

---

## 5. 下单 → 支付 → 分账 时序

```
客户 POST /orders            -> 创建订单(pending_payment)，写入 order_items，分账计算完成
客户 POST /orders/{no}/pay   -> COD: 同步 paid + 就近派单；钱包: 返回 pay_url，等 IPN
网关 IPN 回调                -> 验签 -> Payment=success, 订单=paid, 按 haversine 就近派单
商家 POST .../accept         -> accepted
商家 POST .../ready          -> picked（待骑手取货）
骑手 POST .../accept         -> delivering（骑手实时位置上报）
骑手 POST .../deliver        -> delivered
```

---

## 6. 环境变量（.env）

见 `.env.example`。关键项：
- `DB_*`：MySQL 连接
- `NEW_USER_COUPON_AMOUNT`：新人券金额（₫）
- `DELIVERY_SUBSIDY_ENABLED`：是否开启配送补贴
- `MOMO_PARTNER_CODE` / `MOMO_ACCESS_KEY` / `MOMO_SECRET_KEY` / `MOMO_ENDPOINT`：MoMo 凭证
- `ZALOPAY_APP_ID` / `ZALOPAY_KEY1` / `ZALOPAY_KEY2` / `ZALOPAY_ENDPOINT`：ZaloPay 凭证
- `PAYMENT_SANDBOX`：空凭证时默认 true，走 `pay-mock.html` 沙箱收银台（验签真实）
- `PAYMENT_SANDBOX_SECRET`：沙箱签名密钥（生产请填真实网关密钥）

---

## 7. 目录速览

```
app/Models/        9 个 Eloquent 模型（含关系与 cast）
app/Services/      PaymentSplitService（分账 + haversine 派单）
                    PaymentGatewayService（MoMo/ZaloPay 真实签名+验签 + 沙箱收银台）
app/Http/Controllers/Api/   Auth/Merchant/Order/Rider/Agent/Admin/Category/Payment
app/Http/Resources/         JSON 资源（含配送轨迹 steps）
app/Http/Requests/          StoreMerchantRequest / CreateOrderRequest
public/pay-mock.html        沙箱收银台（触发真实 IPN 验签）
database/migrations/        10 张表
database/seeders/           CategorySeeder(11 品类) + DatabaseSeeder(样例)
```
