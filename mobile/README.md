# GIAONHANH Mobile — Android & iOS (Capacitor)

把 `../app` 的高保真原型包成**安卓 + iOS 双端原生壳**。一次前端，双端安装包。

```
mobile/
├── capacitor.config.json   # appId / appName / webDir / 插件配置
├── copy-web.js             # 把 ../app 复制到 ./www（单一来源）
├── package.json            # Capacitor 6 + 原生插件
├── www/                    # 构建产物（自动生成，勿手改）
├── android/                # ✅ 已生成，Android Studio 打开即构建
└── ios/                    # ⚠️ 需在 macOS 上 `npx cap add ios` 生成
```

应用入口（同一套 web 资源，多套原生屏）：
| 文件 | 角色 |
|---|---|
| `../app/index.html` | **消费者端** APP（首页/分类/商家/购物车/订单/我的） |
| `../app/merchant.html` | **商家端** 原生屏（接单/商品/收益/店铺设置） |
| `../app/rider.html` | **骑手端** 原生屏（抢单/配送中地图/收益） |
| `../app/pay-demo.html` | **支付 IPN 测试台**（验证 MoMo/ZaloPay 真接回调） |
| `../app/native-bridge.js` · `payment.js` · `api.js` | 真机桥接 / 支付 / API 客户端 |

---

## 1. 已接入的真机能力（插件）

| 能力 | 插件 | 触发点 |
|---|---|---|
| 实时定位 | `@capacitor/geolocation` | 首页定位 chip（自动获取真实坐标） |
| 推送通知 | `@capacitor/push-notifications` | 订单状态变更（注册 + 监听） |
| 本地通知 | `@capacitor/local-notifications` | 骑手到达提醒 |
| 触感反馈 | `@capacitor/haptics` | 点击 Tab/按钮/加购（原生设备生效） |
| 启动屏 / 状态栏 | `@capacitor/splash-screen` / `@capacitor/status-bar` | 启动即隐藏 splash、深色状态栏 |
| 打开钱包 | `@capacitor/browser` | MoMo / ZaloPay 支付跳转 |

桥接代码在 `../app/native-bridge.js` + `payment.js` + `api.js`，**仅在 Capacitor 原生壳内激活**；
浏览器直接打开 `app/index.html` 时全部优雅降级，离线演示不受影响。

---

## 2. 构建安卓 APK / AAB

> 需要本机安装 **Android Studio + Android SDK**（首次构建会下载）。

```bash
cd mobile
node copy-web.js            # 或 npm run build
npx cap sync android        # 同步 web 资源 + 插件到 android/
npx cap open android        # 用 Android Studio 打开
```
在 Android Studio 中：`Build → Build Bundle(s) / APK → Build APK` 即可生成安装包。
（`android/` 目录已生成，无需 `cap add android`。）

## 3. 构建 iOS IPA

> **iOS 必须在 macOS + Xcode 环境**执行（Windows 无法生成）。

```bash
cd mobile
node copy-web.js
npx cap add ios             # 仅首次，需在 Mac 上运行
npx cap sync ios
npx cap open ios            # Xcode 打开 → 真机/Archive 出 IPA
```

---

## 4. 支付（MoMo / ZaloPay / COD）— 真接 IPN

`app/payment.js` 封装 `GN.pay({ method:'momo'|'zalopay'|'cod', amount, orderNo })`：
- **COD**：后端同步置 `paid`，并触发就近派单。
- **MoMo / ZaloPay**：前端调用 Laravel `POST /api/orders/{no}/pay` 拿到 `pay_url`，
  用 `@capacitor/browser` 打开钱包/沙箱收银台；之后轮询 `GET .../payment-status`
  直到网关 **IPN 回调**（`/api/payments/momo/ipn`、`/api/payments/zalopay/callback`）
  验签通过、订单转 `paid`。
- 服务端签名/验签全部按官方规范真实实现：见
  `backend/app/Services/PaymentGatewayService.php`（HMAC-SHA256）。
  未填网关密钥时走 `backend/public/pay-mock.html` 沙箱收银台，**验签链路端到端真跑**。
- 用 `app/pay-demo.html` 可一键验证整条 IPN：下单 → 选 MoMo/ZaloPay → 打开收银台 →
  点「我已支付」→ 后端验签 → 订单 paid。

配置网关密钥：在 `backend/.env` 填
`MOMO_PARTNER_CODE / MOMO_ACCESS_KEY / MOMO_SECRET_KEY` 与
`ZALOPAY_APP_ID / ZALOPAY_KEY1 / ZALOPAY_KEY2`；前端 `GN_CONFIG.apiBase` 指向后端即可。

---

## 5. 对接 Laravel 后端

`app/api.js` 暴露 `GN.API`（categories / merchants / createOrder / pay / order /
merchant / rider）。默认 `apiBase:""` → 走离线演示。要连真后端：
1. 部署 `backend/`（见 backend/README.md）。
2. 在 `app/index.html`、**`app/merchant.html`**、**`app/rider.html`**、
   **`app/pay-demo.html`** 各自的 `window.GN_CONFIG` 改
   `apiBase = "https://你的域名/api"`、`useApi: true`。
3. `node copy-web.js && npx cap sync android`。

> 三端（消费者/商家/骑手）+ 测试台共用同一 `GN.API` 客户端与 `native-bridge` 桥接，
> 仅在 `apiBase` 为空时降级为本地演示数据。

---

## 6. 上架清单

**Google Play**
- 准备 512×512 icon + 1024×500 feature graphic + 自适应图标
- 隐私政策链接（越南用户数据合规）
- 设置 `appId: vn.giaonhanh.app`（已在 config）

**App Store**
- Mac 上 `cap add ios`，Xcode 配 signing（Apple Developer 账号）
- 截图（6.5" / 5.5"）、隐私营养标签、越南语本地化描述

**合规提示**：越南市场需遵守《网络安全法》数据本地化与电子支付牌照要求；
支付上线前接入持牌聚合（MoMo / ZaloPay 均为持牌）。
