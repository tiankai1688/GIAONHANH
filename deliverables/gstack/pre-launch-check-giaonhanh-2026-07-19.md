# GIAONHANH（小时达 · 越南版美团）上线前就绪度评估

**日期**：2026-07-19
**场景**：上线前检查（Pre-launch Readiness / Go-No-Go）
**参与成员**：产品官（gstack-product-reviewer）＋ 安全卫士（gstack-security-officer）＋ 质量门神（gstack-qa-lead）＋ 设计师（gstack-designer）
**交付物口径（用户明确）**：前端 = 移动 App（安卓/iOS，经 Capacitor）；后端管理平台 = 网页版（浏览器）管理后台。

---

## 📌 TL;DR（执行摘要）

- **整体结论：🔴 No-Go（公开 GA 不可上线）；🟡 条件 Go（封闭单城试点 / Canary）——前提是先清零 6 个 P0 阻断项。**
- **重大修正**：上一轮审计（2026-07-15）称「代码逻辑层健康、可上线」，安全官实地核查后抛出 **4 项 P0 致命漏洞**，直接推翻该乐观结论——鉴权形同虚设、支付验签可被公开密钥绕过、且直连支付模式踩越南二清红线。
- **阻塞项数量：6（P0）×1 环境 + 1 后台 + 4 安全**。
- **距可信上线估算：单城试点 ≈ 4–6 周**（假设 1 全栈 + 1 移动 + 1 设计并行，且商务侧持牌支付并行推进）；公开全量上线在此基础上再加商店审核 + 牌照/合规周期。
- **下一步**：先清零 P0（修鉴权 2 项 / IPN 验签 fail-closed / 二清合规定调 / 后端真跑通 / 搭管理后台 MVP），再发封闭内测。

---

## 🎯 核心结论卡片

| 项目 | 内容 |
|------|------|
| Go / No-Go | 🔴 No-Go（公开 GA）；🟡 条件 Go（封闭单城试点 / Canary，P0 清零后） |
| 严重度分布 | 🔴 6（P0） · 🟠 13（P1） · 🟡 8（P2） · 🟢 4（P3） |
| 关键行动项 | 修鉴权(2) + IPN 验签 fail-closed + 二清合规定调 + 后端 runtime 冒烟 + 管理后台 MVP + 抢单加锁 |
| 建议负责人 | 后端 / DevOps（鉴权·支付·运行·CORS·TLS）；合规+主理人（二清）；前端+设计（管理后台·iOS·XSS）；商务（持牌支付）；QA（测试·压测） |

---

## 1. 各成员核心结论

### 🔍 产品官（产品评审）
- 核心判断：代码逻辑层（三端闭环、0 佣金分账、退款、IPN 验签）已齐备，但**网页版管理后台 100% 缺失**、后端从未运行、iOS 未构建、真实持牌支付未到位 → 整体 No-Go；单城试点约 4–6 周。
- 关键建议：火力压在「管理后台 UI + 后端真跑通 + 支付牌照」三件铁门槛，其余皆打磨；已拍板两处产品决策（退款留 admin + 强制审计日志；结算按周期、平台补贴分两份导出），由主理人采纳。

### 🛡️ 安全卫士（OWASP+STRIDE 审计）
- 核心判断：状态机/分账/退款逻辑自洽，但**鉴权与支付验签存在 4 项 P0 阻断**——公开注册可传 role 接管全平台、登录密码可空、IPN 验签密钥缺失时回退公开常量可伪造支付、直连支付模式踩二清红线。远未达金融级上线。
- 关键建议：先清零 P0-1~P0-4（关公开 role、强制密码/OTP、IPN fail-closed、仅持牌聚合或取牌照），再做 CORS/TLS/PII 加密/限流/XSS 转义。

### ✅ 质量门神（QA测试与发布）
- 核心判断：公开 GA = No-Go；封闭内测/Canary = Go（限量、可观测）。**P0 = 后端从未真实运行**（沙箱无 PHP/Composer），路由/模型作用域/分账/ability/IPN 全无运行时验证；抢单超卖竞态未修；自动化测试「写了却跑不起来」。
- 关键建议：先发封闭内测，按阻塞项闭环后再 GA；提供 PHP 环境冒烟、加抢单并发锁、补齐可执行 Pest 测试、配齐 iOS/支付/实时凭证；附完整回滚预案。

### 🎨 设计师（设计系统与视觉）
- 核心判断：消费者端 Conditional-Go（视觉/双语/品牌到位，缺订单历史与真实地址）；**网页版管理后台 No-Go**（零前端、后端 API 仅覆盖约 40% 运营面）。
- 关键建议：先把管理后台从 0 搭起来——它才是上线真正卡点；已定稿后台 IA（P0 = 商家/KYC 审核台 + 订单运营 + 结算对账，后端 API 已齐可直接联调）与设计系统（复用品牌双色、240px 侧栏 Shell、VI/ZH 双语）。

---

## 2. 综合审查发现（去重合并后按严重度排序）

| # | 严重度 | 类别 | 位置 | 问题描述 | 建议 | 来源 |
|---|--------|------|------|---------|------|---------|
| 1 | 🔴 P0 | 鉴权/越权 | `AuthController.php:22,31` + `routes/api.php:29` | 公开 `register` 接收 `role` → 任意人可注册 admin 令牌接管全平台（KYC/结算） | 移除公开 `role`，角色仅后台授予 | 安全 |
| 2 | 🔴 P0 | 鉴权/欺骗 | `AuthController.php:44-63` | 登录 `password` 可空、注册不写密码 → 仅凭手机号登任意账号（含种子 090000000x） | 强制密码/OTP，登录校验密码 | 安全 |
| 3 | 🔴 P0 | 支付/完整性 | `PaymentGatewayService.php:33,143,160,251,360` | IPN 验签密钥未配置时回退 `.env.example` 公开常量 `GIAONHANH_SANDBOX_SECRET`，可伪造 `resultCode=0` 白嫖 | 密钥缺失须 **fail-closed**，移入 `config/payment.php`，删公开默认密钥 | 安全 |
| 4 | 🔴 P0 | 合规红线 | `PAYMENT_COMPLIANCE.md §2` | 直连 MoMo/ZaloPay（平台主商户→代付）实为二清（SBV NĐ-101），与「平台不碰资金」冲突；默认 `PAYMENT_AGGREGATOR=none`=直连=非法资金归集 | 上线仅允许持牌聚合(Model B)或取牌照；更正文档 | 安全 |
| 5 | 🔴 P0 | 环境/验证 | `backend/`（全） | 无 PHP/Composer，后端从未 `migrate --seed`；order_no 绑定/作用域/effectivePrice/分账/ability/真·IPN 均未经运行时验证 | 提供 PHP8.2+/Composer 环境跑冒烟 | QA/产品 |
| 6 | 🔴 P0 | 产品/运营 | 全仓无 `admin.html` | 网页版管理后台 100% 缺失（零前端 + KYC 无证件采集），试点无法审核/对账 | 从零搭运营 UI（IA 已定稿：P0=商家/KYC+订单运营+结算对账） | 设计/产品 |
| 7 | 🟠 P1 | 并发 | `RiderController::accept` | 抢单 `update([rider_id])` 非原子，无锁 → 高并发双骑手同抢一单（超卖） | `whereNull('rider_id')->where('status','picked')->update()` 原子领取 + affectedRows 校验 | QA/安全/产品 |
| 8 | 🟠 P1 | 支付/竞态 | `OrderController::cancel:108-148` | 退款前未置 `refunding`/未加锁 → 并发双取消双退款 | DB 锁 / `FOR UPDATE` / 原子更新 | 安全 |
| 9 | 🟠 P1 | 配置/跨域 | `config/cors.php:19,29` | 默认 `allowed_origins='*'` + `supports_credentials=true` | 显式白名单（移动端 `capacitor://localhost` + 后台域名） | QA/安全 |
| 10 | 🟠 P1 | 传输 | `mobile/capacitor.config.json` | `cleartext:true` → MITM 截令牌 | `false` + 强制 TLS | 安全 |
| 11 | 🟠 P1 | 合规/PDPD | `users/orders/merchants` | phone/email/contact_*/bank_account/business_license 全明文，无 `encrypt()` | 加密存储 + 数据删除接口（PDPD NĐ-13） | 安全 |
| 12 | 🟠 P1 | DoS/滥用 | 注册/登录/IPN/下单 | 无限流 → 爆破/刷单 | `throttle` 中间件 | 安全 |
| 13 | 🟠 P1 | 构建/上架 | `mobile/ios`（缺失） | iOS 工程未生成，需 Mac+Xcode+签名 | 安排 Mac 构建机 + 证书 | QA/产品 |
| 14 | 🟠 P1 | 配置/外部 | `.env` / 支付 | 真实持牌支付商户号+密钥未到位；Pusher/BROADCAST_DRIVER 未配 | 生产 .env 固化（APP_URL/DB/Pusher/支付密钥/CORS）；关 DEBUG | QA/产品 |
| 15 | 🟠 P1 | 地图 | Leaflet / OSM | 仅 OSM 瓦片需联网，无越南本地源/离线缓存 | 换 VietMap + 离线缓存 | 产品 |
| 16 | 🟠 P1 | 实时 | `NotificationService` | Pusher 生产 key 未配，广播无触发方，降级轮询 | 配 Pusher key + 触发逻辑 | 产品/QA |
| 17 | 🟠 P1 | 数据 | 地址 | 地址写死河内假坐标，无地图选点/地理编码 | 地址选择器 + 地理编码 | 产品 |
| 18 | 🟠 P1 | 测试 | `composer.json` + `tests/` | 3 个 Pest 用例已写但无 PHP 不可跑；缺 `pestphp/pest` 依赖，即便 install 也跑不起 | 加 pest 依赖并 `composer install` 跑绿；或转 phpunit | QA |
| 19 | 🟠 P1 | 结算依赖 | `SettlementService::merchantPayouts()` | 缺日期区间参数，sum 全量 → 结算导出被部分阻塞 | 补 period 过滤（小改） | 产品/设计 |
| 20 | 🟡 P2 | XSS | `index.html`/`merchant.html` | 无 `esc()`，商家/商品名（API 可控）直灌 `innerHTML`；`window.GN.token` 全局 → 存储型 XSS 盗 Bearer | 全 UGC 转义 | 安全 |
| 21 | 🟡 P2 | 配置 | `PaymentGatewayService` | 内直读 `env()`，生产 `config:cache` 后失效 | 移入 config 经 `config()` 读 | 安全 |
| 22 | 🟡 P2 | 移动端 | Capacitor | 缺证书固定；deeplink 须 `server.allowNavigation` 限定自有源 | 加证书固定 + 限定导航源 | 安全 |
| 23 | 🟡 P2 | KYC/AML | `/merchant/onboard` + 审批 | 审批仅翻标志不核验；KYC 字段公开可提交自由串 | 真实 eKYC 核验 + 审批留痕 | 安全/产品 |
| 24 | 🟡 P2 | 数据篡改 | `Order.$fillable` | 含 `amount/status/rider_id/merchant_id` → 价格篡改风险 | 改 `$guarded` | 安全 |
| 25 | 🟡 P2 | 支付/重放 | IPN | 无金额一致性校验 & 无 requestId/时间戳重放防护 | 加金额校验 + 去重 requestId/时间戳 | 安全 |
| 26 | 🟡 P2 | 暴露 | `backend/public/pay-mock.html` | 公开可访问 | 移除 / 加守卫 | 安全/QA |
| 27 | 🟡 P2 | 依赖安全 | composer | 无 `composer audit` | CI 加 `composer audit` | 安全 |
| 28 | 🟡 P2 | 队列 | `config/queue.php` 缺失 | 队列走 sync；IPN/推送异步需 Redis+worker | 配 Redis 队列 + supervisor | QA |
| 29 | 🟡 P2 | 性能 | 全端 | 无压测：首屏<1.5s、60fps、并发订单+抢单竞态未验证 | 真机 + 区域压测 | QA |
| 30 | 🟡 P2 | 合规 | docs | KYC/AML/PDPD 仅文档未落地 | 接 VN eKYC + 隐私条款/数据删除 | QA |
| 31 | 🟡 P2 | 工具 | `tools/check_inline_js.js` | 运行必崩（缺 argv[2]），内联脚本校验门禁失效 | 加默认路径/usage，纳入 CI | QA |
| 32 | 🟢 P3 | 审计日志 | 审批/退款 | 仅翻标志，无统一审计落点 | ⑬审计日志页统一留痕（谁/何时/金额/单号） | 设计/产品 |
| 33 | 🟢 P3 | 聚合回调 | `verifyAggregatorIpn` | 忽略路由 `$name`，单密钥 | 按渠道路由独立密钥 | 安全 |
| 34 | 🟢 P3 | 运营报表 | `AdminController` 结算 | 0 佣金也需跟踪商户应收 + 平台补贴支出 | 财务口径复核报表 | QA/产品 |
| 35 | 🟢 P3 | 依赖 | Laravel11/Sanctum4 | 稳定无已知 CVE | 保持更新 + CI 审计 | 安全 |

---

## 🚧 阻塞项清单（P0，必须清零方可上线）

| 编号 | 阻塞项 | 为何阻塞上线 |
|------|--------|--------------|
| P0-1 | 公开注册可传 role → 任意人接管 admin | 全盘接管（KYC/结算/退款），任意篡改运营 |
| P0-2 | 登录密码可空 / 注册不写密码 | 凭手机号登任意账号（含种子号），用户资产失控 |
| P0-3 | IPN 验签密钥缺失回退公开常量 | 伪造 `resultCode=0` 白嫖商品，资金损失 |
| P0-4 | 直连支付踩二清红线（SBV NĐ-101） | 非法资金归集，法律/牌照风险，平台可被罚停 |
| P0-5 | 后端从未运行（无 PHP/Composer） | ability/分账/迁移/IPN 均无运行时验证，所有「已验证」结论不可信 |
| P0-6 | 网页版管理后台 100% 缺失 | 试点无法审核商家/KYC/对账，运营不可行 |

---

## 🔄 回滚预案（必须）

- **DB**：部署前全量快照；migrations 均含 `down()` 可 `migrate:rollback/reset`；但生产优先「前进迁移 + 特性开关」而非删表回滚；数据回滚用快照。
- **配置**：保留 known-good `.env` 于部署产物/密钥管理器，一键 redeploy 上一版 `.env`；配置走 env 不硬编码。
- **支付降级**：网关不可用 → `PaymentController` 降级 COD（`pay_method=cod` 已支持）；钱包失败转 `refund_error` 人工；新增 `ENABLE_WALLET` 开关一键关钱包支付。
- **特性开关**：`ENABLE_REALTIME` / `ENABLE_WALLET` / `ENABLE_GRAB`；实时异常 → 关实时回退轮询；抢单异常 → 暂停抢单池 / 切自动派单。
- **一键回退**：后端版本化部署（回滚上一 release tag）；移动端发上一已审核 build 到内测轨道。

---

## ✅ 行动清单（具体可执行项）

| # | 行动 | 负责方 | 紧急度 | 期望完成 |
|---|------|--------|--------|---------|
| 1 | 关闭公开 `role`；角色仅后台授予 | 后端 | P0 | 本周 |
| 2 | 登录强制密码/OTP；删除空密码路径 | 后端 | P0 | 本周 |
| 3 | IPN 验签 fail-closed + 密钥移入 `config/payment.php` + 删公开默认密钥 + 金额/去重校验 | 后端 | P0 | 本周 |
| 4 | 二清合规定调：上线仅持牌聚合(Model B)或取牌照；更正 `PAYMENT_COMPLIANCE.md` | 合规 + 主理人 | P0 | 本周 |
| 5 | 后端真跑通：PHP8.2+/Composer → `composer install` → `migrate --seed` → 三端冒烟 | 后端/DevOps | P0 | 1 周 |
| 6 | 搭网页版管理后台 MVP（商家审批+KYC+订单运营+结算对账，按既定 IA） | 前端 + 设计 | P0 | 2 周 |
| 7 | 抢单加原子锁防超卖（`whereNull('rider_id')->update()` + affectedRows） | 后端 | P1 | 1 周 |
| 8 | 真实持牌支付商户号+密钥（MoMo/ZaloPay/聚合） | 商务 | P1 | 2–4 周（外部依赖） |
| 9 | CORS 显式白名单 + `cleartext:false` + 证书固定 | DevOps/前端 | P1 | 3 天 |
| 10 | PII 加密存储 + 数据删除接口（PDPD NĐ-13） | 后端 | P1 | 1 周 |
| 11 | iOS 构建（Mac+Xcode+`cap add ios`+签名）+ 商店上架 | 移动 owner | P1 | 2 周 |
| 12 | 补齐可执行测试（pest 依赖 + 跑绿：IPN/分账/状态机/抢单并发） | QA | P1 | 1 周 |
| 13 | 全 UGC 转义（XSS）+ 取消/抢单加锁 + 全站限流 | 前后端 | P2 | 并行 |
| 14 | 真实地址地图选点 + 地理编码；VietMap 瓦片 + 离线缓存 | 前后端 | P1/P2 | 并行 |
| 15 | 真机首屏<1.5s / 60fps / 并发订单+抢单竞态压测 | QA | P2 | 上线前 |

---

## ⚠️ 待完善 / 已知局限

- 本次评估基于**静态代码审阅 + 上一轮审计（2026-07-15）结论**；后端从未真跑，故「已验证」项（IPN 验签、分账、状态机）仅在沙箱桩环境下成立，需 runtime 冒烟复核——安全 P0-3 正是在「未配置」态暴露，说明乐观结论需打折。
- 安全官的 4 项 P0 与此前 `AUDIT_FINAL`「代码逻辑层健康」判断**直接冲突**，说明前审在「鉴权与支付验签的 fail-closed 维度」有盲点，建议将安全审计纳入每次上线前强制关卡。
- 时间线为**假设估算**（1 全栈 + 1 移动 + 1 设计 + 商务牌照并行），不含商店审核与牌照申请的不确定性；持牌支付（P0-4/P1-8）为外部强依赖，可能成为最长路径。
- 设计师已产出后台 IA 与设计系统草案，但**未落地原型代码**——用户原始请求为评估，非实现；是否启动管理后台实现将单独征求用户意见。
- 早期审计 P0-1（ability 中间件）、P0-2（消费者端接后端）据 FINAL 已在代码中确认修复；但 P0-5（后端从未运行）使这些修复仍缺运行时证明。

---

## 📚 成员产出索引

- gstack-product-reviewer（产品官）原始产出：产品就绪度最终评估（消费者/商家/骑手端 Conditional-Go、支付分账 🟢、网页后台 🔴；B1–B8 阻塞；4–6 周试点估算；两处产品决策）。
- gstack-security-officer（安全卫士）原始产出：安全与合规最终审计（OWASP Top10 + STRIDE；4 P0 + 5 P1 + 5 P2 + 5 P3；二清红线冲突；安全维度 No-Go）。
- gstack-qa-lead（质量门神）原始产出：QA/发布就绪评估（公开 GA No-Go、Canary Go；F1–F12；测试计划 + 发布检查清单 + 回滚预案）。
- gstack-designer（设计师）原始产出：设计就绪度评审（消费者端 Conditional-Go、后台 No-Go；后台 IA + 设计系统 + P0 三页交互规格；B1–B4 阻塞）。

---

> 本报告由软件工坊 AI 协作生成，关键决策请由工程负责人复核。
> 注：四大维度均判 🔴/🟡，公开上线不成立；建议以「封闭单城试点」为首个发布里程碑，P0 清零后进入 Canary，再视商店审核与牌照进度放量。
