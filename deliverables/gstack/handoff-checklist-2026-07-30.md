# GIAONHANH 接手执行清单（需你本机 / CI 跑，沙箱无法执行）

**日期**：2026-07-30
**用途**：本清单列出的任务依赖 PHP/Composer、Mac 或真机环境，当前沙箱（无 PHP、无 Mac）只能写完源码，需你在本地或 CI 执行。

---

## 🔴 P0 — 上线前必须做

### 1. 执行密码约束迁移 + 验证登录
后端 `users.password` 已改为 NOT NULL（迁移文件 `backend/database/migrations/2026_07_30_000000_enforce_user_password_not_null.php`）。
```bash
cd backend
composer install            # 若未装依赖
cp .env.example .env && php artisan key:generate
php artisan migrate         # 落地 password NOT NULL；NULL 行会被置随机 bcrypt（无法登录，需重置）
# 验证：用 demo 账号登录（商家 0900000003 / 骑手 0900000004，密码 demo123）
php artisan tinker
>>> App\Models\User::whereNull('password')->count();   # 应为 0
```
> 回滚：`php artisan migrate:rollback`（迁移 down() 已改回 nullable）。

---

## 🟠 P1 — 联调期做

### 2. 骑手送达 GPS 邻近校验真机实测
`RiderController::deliver` 已加 0.3km 门禁（>0.3km 返回 422）。越南城区楼宇密集，GPS 漂移可能误伤。
- 真机跑一趟真实配送，观察 `distance_km` 返回值。
- 门禁阈值在 `deliver()` 内 `$dist > 0.3` 调整（建议城区 0.05–0.1km，郊区 0.3km）。
- 前端 `app/api.js` 的 `riderDeliver(orderNo, lat, lng)` 已支持携带坐标，确认骑手端 GPS 在送达时上报。

### 3. 逐屏核对设计稿与前端视觉一致性
- Ardot 设计稿（四端 31 屏 + 总览板） vs `app/merged-demo.html` 及 `index.html`。
- 重点：合并下单三屏 C9/C10/C11、商家 M9、骑手 R9/R10 的文案/状态机/CTA 与前端对应。

---

## 🟡 P2 — 下一迭代

### 4. Token refresh / rotation（#9）
当前 Sanctum 长期 token 无轮换。需：新建 `personal_access_token_rotations` 或 refresh-token 表 → 登录发 access+refresh → 中间件支持 refresh 端点 → 前端 `app/api.js` 接入。

### 5. 注册接口补验证码 / OAuth
`AuthController::register` 已强制 `password` 且拒绝 `role`，但无频控之外的防批量注册手段。建议加短信/邮箱验证码或社交登录。

### 6. Android assets 同步纳入 CI
`app/` 与 `mobile/android/app/src/main/assets/public/` 已手动 `cp` 对齐（9 文件）。建议加 CI 步骤自动同步，避免再漂移：
```bash
# 示例 CI 脚本
cp app/api.js mobile/android/app/src/main/assets/public/api.js
cp app/*.html mobile/android/app/src/main/assets/public/
```

### 7. 生成 iOS Capacitor 工程（需 Mac）
```bash
cd mobile
npm install
npx cap add ios          # 仅 Mac 可执行
npx cap sync ios
```
> 当前仅 android/ 已生成，ios/ 待 Mac 生成。

---

## ✅ 已交付（无需你做）
- 合并去重完成；9 项审核 6 修 2 证伪 1 后续；前后端契约 PASS。
- 完整交付报告：`deliverables/gstack/feature-dev-giaonhanh-2026-07-29.md`
- 可交互原型已部署（见 CloudStudio 链接）；源码在 `app/` `backend/` `mobile/`。

> 本清单由软件工坊 AI 生成，执行命令前请按你的实际环境（DB/支付密钥/.env）核对。
