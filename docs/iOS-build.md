# GIAONHANH · iOS 构建与真机说明（Capacitor 6）

> 适用：用同一套 `app/` Web 原型 + Capacitor 壳，在 macOS 上打包出 iOS App。
> 安卓端已在 Windows 上生成（`mobile/android/`），本文件只讲 iOS 这一半。

---

## 1. 前置条件（必须在 Mac 上）

| 工具 | 版本/说明 |
|---|---|
| macOS | 12+（Ventura 及以上更稳） |
| Xcode | App Store 安装，**必须打开一次**以同意许可并安装 Command Line Tools |
| Xcode Command Line Tools | `xcode-select --install` |
| Node + npm | 与安卓侧一致（≥ 18 LTS） |
| CocoaPods | `sudo gem install cocoapods`（Capacitor iOS 6 已改用原生 `.xcodeproj`，但部分插件仍依赖 Pods） |
| Apple 开发者账号 | 免费账号可真机调试；**上架 App Store 需付费 $99/年** |

验证：
```bash
xcodebuild -version
pod --version
npm -v
```

---

## 2. 生成 iOS 工程

在 **项目根目录**（与 `mobile/` 同级）执行，或在 `mobile/` 内：

```bash
cd mobile
# 先确保 Web 资源是最新的（与安卓侧同一个脚本）
node copy-web.js

# 添加 iOS 平台（只需一次，之后用 sync）
npx cap add ios
```

完成后目录结构：
```
mobile/
├─ android/        # 已存在（Windows 生成）
├─ ios/            # 本次新增
│  └─ App/App.xcworkspace
├─ www/            # copy-web.js 同步进来的 Web 资源
└─ capacitor.config.json
```

> 之后每次改了 `app/` 下的代码，都只需：
> ```bash
> node copy-web.js && npx cap sync ios
> ```
> 这会把最新 Web 资源拷贝进 `ios/App/Public/` 并刷新原生工程。

---

## 3. Capacitor 配置要点（`capacitor.config.json`）

iOS 与安卓共用同一份配置，重点确认：

```jsonc
{
  "appId": "com.giaonhanh.app",   // Bundle ID，苹果侧必须唯一且你拥有
  "appName": "GIAONHANH",
  "webDir": "www",
  "server": { "androidScheme": "https" },
  "plugins": {
    "PushNotifications": { "presentationOptions": ["badge", "sound", "alert"] }
  }
}
```

- `appId` 要与你在 **Apple Developer** 里注册的 Bundle ID 完全一致。
- 不要为 iOS 单独改 `webDir`，保持 `www`，与安卓同步逻辑一致。

---

## 4. 打开工程并配置签名

```bash
npx cap open ios
```
Xcode 打开后：

1. 选中 **App** 工程 → **Signing & Capabilities**。
2. 勾选 **Automatically manage signing**。
3. **Team** 选你的开发者账号（个人免费账号亦可真机跑）。
4. Bundle Identifier 与 `appId` 一致（`com.giaonhanh.app`）。
5. 如需推送（FCM/APNs），开启 **Push Notifications** 能力，并上传 **APNs Auth Key (.p8)** 或 APNs 证书。

> 免费账号的“真机运行”证书每 7 天过期，需重新插线 run；上架不受影响。

---

## 5. 真机运行 / 打包

### 真机调试
- USB 连 iPhone → Xcode 顶部设备选该手机 → ▶ Run。
- 首次需在手机 **设置 → 通用 → VPN与设备管理** 里信任你的开发者证书。

### 上架 App Store
```
Xcode → Product → Archive → 验证 → Distribute App
```
走 **App Store Connect** 通道，填元数据、截图、隐私清单（参见第 7 节）。

---

## 6. 支付与深链（iOS 专属）

MoMo / ZaloPay 在 Safari/应用内打开收银台后**通过 URL Scheme 回跳 App**，必须在 `ios/App/App/Info.plist` 注册：

```xml
<key>CFBundleURLTypes</key>
<array>
  <dict>
    <key>CFBundleURLName</key>
    <string>com.giaonhanh.app</string>
    <key>CFBundleURLSchemes</key>
    <array>
      <string>giaonhanh</string>   <!-- 与前端 payReturnUrl: "giaonhanh://pay/return" 对应 -->
    </array>
  </dict>
</array>
```

- 前端 `app/index.html` 里 `GN_CONFIG.payReturnUrl` 已设为 `giaonhanh://pay/return`，与上面 Scheme 一致。
- 钱包回跳后会触发 `payment.js` 的轮询 `GN.waitForPayment(orderNo)`，等后端 IPN 确认支付成功。
- iOS 上 `WKWebView` 默认禁止非 HTTPS 跳转，生产环境请确保后端 `APP_URL` 为 HTTPS，且支付网关回调域名已加入白名单（见 `backend/docs/PAYMENT_COMPLIANCE.md`）。

---

## 7. 隐私与合规（越南 + 苹果双重要求）

1. **越南 SBV 支付合规**：平台抽成恒为 0，配送费由平台补贴，资金通过持牌聚合商（Sepay/Payoo）分账，**平台不碰用户资金（无二清）**。详见 `backend/docs/PAYMENT_COMPLIANCE.md`。
2. **Apple 隐私清单**：若集成了 FCM/Analytics 等，需要在 `Info.plist` 的 `NSPrivacyAccessedAPITypes` / `NSPrivacyCollectedDataTypes` 声明，并在 App Store Connect 填隐私问答。
3. **位置权限文案**：`native-bridge.js` 用 Capacitor Geolocation，iOS 会在首次调用时弹“使用位置”授权；如需自定义文案，在 `Info.plist` 加 `NSLocationWhenInUseUsageDescription`。

---

## 8. 与安卓侧的对齐清单

| 项 | 安卓 | iOS |
|---|---|---|
| 工程生成 | `npx cap add android`（已在 Win 完成） | `npx cap add ios`（本文件） |
| 资源同步 | `node copy-web.js && npx cap sync android` | `node copy-web.js && npx cap sync ios` |
| 推送 | FCM（需 `FCM_SERVER_KEY`，见后端 `.env.example`） | APNs（需 .p8 Key） |
| 支付回跳 | `giaonhanh://` scheme | 同左，Info.plist 注册 |
| 实时 | Laravel Echo + Pusher/Ably（双端共用后端频道） | 同左 |

---

## 9. 常见问题

- **`npx cap sync ios` 报 “Xcode not found”** → 必须在 Mac 上且 Xcode 已安装并打开过一次。
- **真机运行报签名错误** → Team 未选 / Bundle ID 不匹配 / 免费证书过期（重插线 run）。
- **支付打开后无法回跳 App** → 检查 `Info.plist` 的 URL Scheme 与前端 `payReturnUrl` 是否一致。
- **地图在 iOS 不显示** → `rider.html` 用 Leaflet + OSM 瓦片（无需 Key），确认 `www/` 已通过 `copy-web.js` 同步且设备能访问外网瓦片服务。
