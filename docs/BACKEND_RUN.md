# 让后端真正跑起来（GIAONHANH Backend Runtime）

本仓库此前所有后端代码（鉴权 / #9 令牌轮换 / 优惠券抵扣 / T+1 结算真打款 / 跨店合并下单）都**只写了没运行过**——本沙箱无 PHP/MySQL，无法本地执行。本文档给出两条让"后端真正跑起来并可被证明"的路径。

---

## 路径 A：本地 Docker（最快，含 PHP + MySQL）

前置：本机已装 Docker。

```bash
# 1) 起 PHP + MySQL + (可选) Redis
docker compose up -d

# 2) 进容器初始化
docker compose exec app bash
  cp .env.example .env
  php artisan key:generate
  php artisan migrate --force
  php artisan db:seed --force
  php artisan serve --host=0.0.0.0 --port=8080

# 3) 另开终端跑端到端冒烟（需要 Node 18+）
API_BASE=http://localhost:8080 \
ADMIN_PHONE=0900000001 ADMIN_PASSWORD=GiaoNhanh#Admin#2026 \
node tools/backend-smoke.mjs
```

冒烟覆盖：health → 注册(拿 token+refresh) → 列商家/商品 → 下单(服务端算券) → 支付(COD) → 查单 → refresh 换发 → 管理端登录 → 结算列表。任一项失败退出码非 0。

---

## 路径 B：CI 自动验证（推送即证明）

`.github/workflows/ci.yml` 包含三个作业：

- `contract` — 前端/后端端点契约 + `app/api.js` 语法（Node，沙箱可跑）。
- `laravel` — `composer install` + `php artisan route:list`（证明路由/代码可加载）。
- `backend-smoke` — **真正启动 Laravel + MySQL**，migrate + seed + 起服务 + 跑 `tools/backend-smoke.mjs`。

`backend-smoke` 在 `push`/`PR` 到 `main`/`master` 时自动运行。要自定义管理员口令（冒烟用它登录），在 CI 的 `env` 里改 `ADMIN_PASSWORD`，并同步把 `ADMIN_SEED_PASSWORD` 写进 `.env`（seeder 读取该值；未设则回退 `GiaoNhanh#Admin#2026`）。

> 想本地复刻 CI：在新装 Ubuntu 上跑同样的 `services: mysql` + `setup-php@8.2` + 上述命令即可。

---

## 本次为"能跑"修掉的静态审查问题

沙箱无 PHP，无法 `php -l`，但精读 + grep 校验发现并修复了 2 个会导致运行期数据丢失的 mass-assignment 坑：

1. `MerchantPayout` `$fillable` 缺 `paid_at` → `adminPayout()` 里 `paid_at => now()` 会被 Laravel 静默丢弃（打款记录无时间）。已补。
2. `CouponRedemption` `$fillable` 缺 `coupon_id` → 商户券核销记录写不进 `coupon_id`（FK 丢，仅 `(user_id, coupon_code)` 唯一约束仍在，防复用不受影响）。已补。

另已核对：令牌轮换链路（公开 `POST /api/auth/refresh` + 4 个 ability 组挂 `token.expiry` 中间件 + `bootstrap/app.php` alias + `User::refreshTokens()` 关系 + `refresh_tokens` 迁移列）完整；`PaymentGatewayService` / `Product::effectivePrice()` / `Merchant::approved()` 等被引用的符号均存在；新增迁移列（`funded_by` / `coupon_id` ×2 / `refresh_tokens.*`）齐备。

---

## 仍未覆盖（需真机/CI 才暴露）

- PHP 运行期错误（如某个关系名拼错、auth guard 配置）——由 `backend-smoke` 作业在 CI 捕获。
- 真实支付 PSP 联调（MoMo/ZaloPay 沙箱凭证）——见 `docs/PAYMENT_COMPLIANCE.md`，属路线图阶段 B。
- 移动端上架、合规、压测——路线图阶段 C/D/E。
