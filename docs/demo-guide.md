# GIAONHANH Docker 真机 Demo 录制指南

> 目标：用 **Docker 跑真实后端容器** + 前端演示页打真实 API，录一段 3–5 分钟投资人 demo。
> 前置：本机已装 Docker Desktop（Windows / macOS 均可），已克隆本仓库。
> 关键事实：演示页默认 `useApi:false`（离线模拟）；改 `GN_CONFIG` 即切真实后端。

---

## 0. 准备本地 `.env`（已完成）

`backend/.env` 已就位（被 `.gitignore` 排除，安全，不提交）。内含：
- `PLATFORM_COMMISSION_RATE=0`（0 佣金模型，与 demo 叙事一致）
- 演示种子账号（见文件末尾注释）

若需重建：`cp backend/.env.example backend/.env`。

## 1. 起栈（真实容器）

```bash
# 仓库根目录
docker compose -p giaonhanh up --build -d

# 验证后端活体（应返回 {"ok":true}）
curl http://127.0.0.1:8080/api/v1/health

# 迁移 + 种子（自带 3 商家 + 商品 + customer/merchant/rider 账号 + 订单生命周期）
docker compose -p giaonhanh exec app php artisan migrate --force
docker compose -p giaonhanh exec app php artisan db:seed
```

> 数据层（MySQL / Redis）在 `internal` 网络，不暴露主机；仅 `app` 暴露 `:8080`。

## 2. 让演示页走真实后端

演示页在 `app/`：`pay-demo.html`（下单→支付全链路）、`merged-demo.html`（跨店合并单）。

```bash
# 复制一份，避免改到仓库里的离线版
cp app/pay-demo.html app/pay-demo-live.html
cp app/merged-demo.html app/merged-demo-live.html
```

编辑这两份 `*--live.html`，把内联 `window.GN_CONFIG` 改为：

```js
window.GN_CONFIG = {
  apiBase: "http://127.0.0.1:8080",
  useApi: true,
};
```

然后在 `app/` 起一个静态服务（演示页需经 http 加载，不能直接 file://）：

```bash
cd app && python3 -m http.server 5173
# 浏览器打开 http://localhost:5173/pay-demo-live.html
```

## 3. 镜头前走「两步注册看 dev OTP」（可选，强推荐）

这是安全差异化卖点（未验证不发账号、不发车券）。需临时开 debug：

1. 编辑 `docker-compose.yml` 第 42 行 `APP_DEBUG: "false"` → `"true"`
2. `docker compose -p giaonhanh up -d` 重建 app 容器
3. 演示页走 `POST /auth/register` → 响应回显 `otp` → `POST /auth/register/verify` 拿 token
4. **录完务必改回 `false` 并重新 up**（生产永不回显 OTP，防脚本批量注册薅新人券）

> 若不想动 debug，直接用种子 customer 登录：`0900000002` / `demo1234`（来自 `DatabaseSeeder`）。

## 4. 录制脚本（建议 3–5 分钟，1080p，横屏）

| 时间 | 画面 | 旁白要点 |
|------|------|----------|
| 0:00 | 终端 `curl /health` 返回 ok，Docker Desktop 显示 app/mysql/redis 运行中 | "后端跑在真实 Docker 容器，CI 4 个 job 全绿" |
| 0:30 | 演示页 `pay-demo-live.html`，走两步注册（看 dev OTP 回显）→ verify 拿 token | "两步注册：未验证绝不建账号、绝不发券" |
| 1:15 | `GN.API.merchants()` → 列表；点商家 → `merchantProducts()` 商品 | "真实商家/商品来自容器数据库" |
| 1:45 | 加购 → `createOrder` → `pay`（pay-mock.html 沙箱，**MoMo/ZaloPay 验签为真**）→ 订单 paid | "下单到支付全链路真实，验签不降级" |
| 2:30 | 打开 `merged-demo-live.html`：多店加购 → `createMergedOrder` → 单次配送费 | "跨店合并单：一次配送、单次配送费" |
| 3:15 | 切商家/骑手/结算视角，展示平台 **0 抽成**、配送费平台补贴、商家拿满货款 | "0 佣金 + 平台补贴，毛利全留商家" |
| 4:00 | GitHub Actions：contract / laravel / pest / backend-smoke 全 success | "融资前工程就绪，DD 可直接审" |

## 5. 录制工具

- **OBS Studio**（Win/Mac 免费，1080p 60fps）或 **QuickTime**（Mac）
- 输出 `GIAONHANH-demo-YYYYMMDD.mp4`，横屏，3–5 分钟，旁白用 VI + ZH 双语字幕更佳

## 6. 收尾检查

- [ ] `docker-compose.yml` 第 42 行 `APP_DEBUG` 已改回 `"false"`
- [ ] `docker compose -p giaonhanh down` 关栈（或留作后续演示）
- [ ] `app/*-live.html` 为本地临时文件，勿提交（可加 `.gitignore` 或删除）
- [ ] `backend/.env` 不会进版本库（已被 gitignore）

---
*本指南仅描述本地演示流程；生产部署另需真实 PSP 密钥、TLS、持牌聚合接入。*

---

## 7. 镜头前逐句旁白（VI + ZH 双语，可直接照读）

> 用法：按时间轴口播，语速从容，总长约 3–5 分钟。两套语言择一即可，投资人在场建议 **VI 主声 + ZH 字幕**（或反过来）。加粗句为「必须说到位」的核心卖点。

### 0:00 — 容器活体 & 后端健康

**画面**：终端 `curl http://127.0.0.1:8080/api/v1/health` 返回 `{"ok":true}`；Docker Desktop 显示 `app / mysql / redis` 三个容器 `Running`。

- 🎙 **VI**："Xin chào, đây là **GIAONHANH** — nền tảng giao hàng nhanh 30–60 phút tại Việt Nam. Backend đang chạy thực tế trong Docker container: app, MySQL và Redis đều đang hoạt động. Lệnh curl vừa trả về `ok`, chứng tỏ API đang sống."
- 🎙 **ZH**："大家好，这是 **GIAONHANH**——越南 30 到 60 分钟极速达平台。后端正真实跑在 Docker 容器里：app、MySQL、Redis 全部运行。刚才 curl 返回 ok，证明 API 已活。"

### 0:30 — 两步注册看 dev OTP（安全差异化）

**画面**：演示页走 `POST /auth/register` → 响应回显 `otp` → `POST /auth/register/verify` 拿 token（需临时 `APP_DEBUG=true`）。

- 🎙 **VI**："Điểm khác biệt về bảo mật: **đăng ký hai bước**. Chúng tôi không bao giờ tạo tài khoản nếu chưa xác minh, và không phát mã giảm giá cho tài khoản chưa xác minh. Ở đây bạn thấy OTP hiện ra trên môi trường dev, sau đó verify để lấy token."
- 🎙 **ZH**："安全上的差异化卖点：**两步注册**。未验证我们绝不建账号，也绝不向未验证账号发券。这里你能看到 dev 环境回显 OTP，然后 verify 拿 token。"

### 1:15 — 商家 / 商品真实数据

**画面**：`GN.API.merchants()` 返回列表；点商家 → `merchantProducts()` 拉出商品（来自容器 DB，非假数据）。

- 🎙 **VI**："Danh sách merchant và sản phẩm được lấy trực tiếp từ cơ sở dữ liệu trong container — **không phải dữ liệu giả**. Mỗi merchant đều có sản phẩm thật."
- 🎙 **ZH**："商家和商品列表直接从容器数据库拉取，**不是假数据**。每个商家都有真实商品。"

### 1:45 — 下单 → 支付全链路（验签为真）

**画面**：加购 → `createOrder` → `pay` → 打开 pay-mock 收银台 → IPN 验签通过 → 订单 `paid`。

- 🎙 **VI**："Thêm vào giỏ, tạo đơn, rồi thanh toán. Ở chế độ sandbox, cổng MoMo/ZaloPay được thay bằng pay-mock, nhưng **chữ ký xác thực IPN là thật**. Đơn chuyển sang `paid` ngay sau khi xác thực."
- 🎙 **ZH**："加购、建单、支付。沙箱模式下 MoMo/ZaloPay 网关由 pay-mock 替代，但 **IPN 验签是真实的**。验签通过后订单立即转 paid。"

### 2:30 — 跨店合并单（单次配送费）

**画面**：打开 `merged-demo-live.html`，多店加购 → `createMergedOrder` → 单次配送费。

- 🎙 **VI**："Tính năng gộp đơn nhiều cửa hàng: khách chọn hàng từ nhiều merchant, hệ thống tạo một đơn cha và **gộp thành một lần giao hàng, chỉ thu một khoản phí giao hàng duy nhất**."
- 🎙 **ZH**："跨店合并单功能：顾客从多家店选货，系统生成一个父单并**合并成一次配送，只收一次配送费**。"

### 3:15 — 0 佣金 + 平台补贴

**画面**：切商家 / 骑手 / 结算视角，展示 `PLATFORM_COMMISSION_RATE=0`、配送费平台补贴、商家拿满货款。

- 🎙 **VI**："Mô hình doanh thu: nền tảng thu **0% hoa hồng**. Phí giao hàng được nền tảng trợ cấp, nên thương gia nhận toàn bộ tiền hàng. **Biên lợi nhuận hoàn toàn thuộc về thương gia**."
- 🎙 **ZH**："营收模型：平台抽成 **0%**。配送费由平台补贴，所以商家拿到全部货款，**毛利完全留在商家**。"

### 4:00 — CI 4 job 全绿（融资前工程就绪）

**画面**：GitHub Actions：contract / laravel / pest / backend-smoke 全部 `success`。

- 🎙 **VI**："Về kỹ thuật, trước vòng gọi vốn: **4 job CI đều xanh** — contract, Laravel, Pest và backend-smoke. Sẵn sàng cho due diligence."
- 🎙 **ZH**："工程上，融资前技术就绪：**CI 4 个 job 全绿**——contract、Laravel、Pest、backend-smoke。可直接进入尽调。"

---

### 收尾口播（建议，15 秒）

- 🎙 **VI**："Đó là GIAONHANH — backend thực trong Docker, thanh toán có xác thực thật, và mô hình 0% hoa hồng. Cảm ơn các bạn đã theo dõi."
- 🎙 **ZH**："这就是 GIAONHANH——Docker 真容器后端、真实验签支付、0 佣金模型。感谢观看。"
