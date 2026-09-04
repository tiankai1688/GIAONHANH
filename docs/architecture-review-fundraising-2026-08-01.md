# GIAONHANH 架构审视 · 融资 & 越南大规模推广版

> 评审日期：2026-08-01 ｜ 视角：技术尽职调查（VC / 战略投资方）+ 越南规模化运营
> 方法：基于仓库实代码逐文件核查（非凭记忆）。结论分「已证实 / 未证实 / 缺口」三档。

---

## 0. 一句话结论（Verdict）

**地基扎实、代码质量高于同类原型，但「未经运行证明」+「移动端未真正出包」+「盈利桥缺失」是融资前必须解决的三件事。**
好消息是：所有问题都属于**验证与工程补全**，而非**架构重做**——无需推倒重来。

| 维度 | 状态 | 判断 |
|------|------|------|
| 后端架构设计 | ✅ 已证实（代码级） | 服务分层清晰、资金逻辑单一事实源、fail-closed 安全 |
| 支付合规设计 | ✅ 已证实（代码级） | 规避二清、HMAC 验签、聚合器模式，越南友好 |
| 杀手锏（跨店合并单） | ✅ 已证实（代码级） | 真实实现 + 父/子单级联，差异化护城河 |
| 后端「能跑起来」 | 🟠 未证实 | 沙箱无 PHP；CI 已设计但未跑过；3 个测试从未执行 |
| 移动端 App | 🟠 未出包 | 仅 mobile-web PWA + Capacitor 桥桩，**无 APK/IPA、无原生工程** |
| 盈利模型 | 🔴 缺失 | 0 佣金 + 平台补贴配送 = 纯烧钱获客，无变现桥 |
| 越南规模化合规 | 🟠 缺口 | 数据本地化、电子发票、PSP 协议未落地 |

---

## 1. 架构现状全景（已实查）

**后端（Laravel 11，真实代码）**
- 分层合理：`Controllers/Api/*` + `Services/*`（PaymentSplit / PaymentGateway / Coupon / MerchantSettlement / Notification）+ `Models/*` + `Requests`（含表单校验）。
- 资金单一事实源 `PaymentSplitService`：0 佣金、配送补贴、合并单单次配送费，逻辑干净、有 `PaymentSplitServiceTest`。
- 支付 `PaymentGatewayService`：MoMo/ZaloPay 全程 HMAC-SHA256 签名 + IPN 验签，**fail-closed**（无默认密钥，防伪造「已支付」回调）；聚合器（Sepay/Payoo）模式规避资金池（二清）。
- 订单状态机、令牌轮换（2h access + 30d refresh）、合并单父/子级联取消——均落地。
- 测试：`tests/Feature/` 下 3 个（分账、网关签名、订单状态机）。

**前端（纯 Web 原型 + 设计系统）**
- 三端 Web：`index.html`(C 消费者) / `admin.html`(L 管理) / `merchant-web.html`(M 商家)，共享 `api.js`（LIVE/离线双模）。
- Ardot 设计稿 47 顶层屏 + 7 合并帧，已全量绑定 GIAONHANH 变量集（单一事实源、暗色可切换）。
- `native-bridge.js`：仅 `window.Capacitor.isNative` 时激活（GPS/语音/通知原生桥）——**代码为 Capacitor 就绪，但工程未搭**。

**部署基建（已写，未跑）**
- `docker-compose.yml`：app + mysql + redis 三件套；**无独立队列 worker、无负载均衡、单实例**。
- `.github/workflows/ci.yml`：contract + laravel 构建 + **backend-smoke（真启 MySQL 跑端到端）**——设计到位，但**从未在 CI 跑过**。
- `docs/`：DEPLOY / BACKEND_RUN / PAYMENT_COMPLIANCE / iOS-build（iOS 构建文档存在，但原生工程不存在）。

---

## 2. 真实优势（尽调可防御点）

这些是能在 DD 里**站得住**的，要重点讲：

1. **资金逻辑可审计**：所有分账走 `PaymentSplitService` 单一入口，0 佣金 + 补贴的会计口径清晰，方便投资人/审计核查单位经济。
2. **支付合规前置设计**：主动规避二清（用持牌聚合器）、验签 fail-closed、无硬编码密钥——说明团队懂越南支付雷区，不是事后补。
3. **杀手锏真实可用**：跨店合并单不是 PPT 概念，是带父/子单级联的真实实现，是区别于 Grab/ShopeeFood 的**结构性差异化**。
4. **工程纪律痕迹**：写了测试、写了 CI、写了合规文档、做了安全加固（令牌轮换、mass-assignment 修复）。对早期项目，这比功能数量更加分。
5. **设计系统统一**：变量集单一事实源，多端视觉一致、可主题化——规模化后品牌一致性成本极低。
6. **双模前端**：离线可演示、LIVE 可上线，同一套代码——路演 demo 不怕断网。

---

## 3. 关键风险（按严重度）

### 🔴 阻断级（融资/上线前必须解决）
- **R1 · 后端零运行证明**：沙箱无 PHP，所有迁移/路由/服务**从未真正执行**；3 个测试从未跑过；`verify-contract.mjs` 已知「FAIL 却退出 0」的假绿。投资人 DD 必问「demo 是录屏还是真跑？」——**必须让 CI 绿一次并保留证据**。
- **R2 · 盈利桥缺失**：0 佣金 + 平台补贴配送 = 每单净亏（补贴来自营销预算）。若无清晰变现路径（广告/商家 SaaS/规模后抽佣/金融服务/数据），估值逻辑站不住。**这是投资人第一个会追问的问题**，需先准备好单位经济模型（LTV/CAC、补贴回收期、take-rate 拐点）。

### 🟠 高优（规模化就绪缺口）
- **R3 · 移动端未出包**：没有 Capacitor 工程、没有 android/ios 目录、没有 APK/IPA。现状是**移动优先的 Web PWA**，不是已上架 App。**切勿在 BP 里写「已上线 CH Play/App Store」**——DD 一查便穿。应表述为「mobile-first PWA，原生壳构建中」。
- **R4 · 可扩展拓扑单薄**：compose 单实例、无 worker、CI 用 `QUEUE_CONNECTION=sync`。百万级订单需：LB + 多 app 实例 + 独立队列 worker（Horizon）+ 读副本 + 缓存策略。目前只有「能跑」，没有「能横向扩」。
- **R5 · 越南数据本地化**：Decree 53/2022 要求越南用户数据存储于境内。当前单 MySQL、未说明云区域。若部署在海外云无 VN 区域/本地 IDC，合规风险高。
- **R6 · 电子发票（Hóa đơn điện tử）**：越南强制交易开票（尤其对商家结算/补贴入账）。结算与打款流水需税务合规开票能力，当前未见。
- **R7 · PSP 协议未签署**：MoMo/ZaloPay/聚合器代码就绪，但**商户协议、费率、KYC 均未落地**——支付在真实环境仍是「配置即上线」状态。

### 🟡 中优（打磨项）
- **R8 · 无压测/无混沌/无限流证据**：`auth:60 / ipn:120` 限流规则写了，但无压测数据；无 DDoS/WAF 方案。
- **R9 · 监控可观测性缺失**：无日志聚合、无 APM、无告警——规模化后运维盲区。
- **R10 · 多区域/多城市模型**：合并单依赖地理就近（单次配送），但**骑手调度、商家密度、城市冷启动**的运营模型未在系统里体现（偏运营而非技术，但影响可行性）。

---

## 4. 越南大规模推广专项

| 议题 | 现状 | 建议 |
|------|------|------|
| 支付牌照/二清 | ✅ 已用聚合器规避 | 落地前签聚合器正式协议 + 留好分账凭证 |
| 数据本地化 | 🟠 未规划 | 选定 VN 区域云（如 VNG Cloud / AWS ap-southeast-1 加坡需评估）/ 本地 IDC；写进合规白皮书 |
| 电子发票 | 🟠 缺失 | 接入 VN 电子发票服务商（如 VNPT / BKAV / MISA）；结算单自动出票 |
| 竞争（Grab/ShopeeFood） | — | 护城河=跨店合并单 + 0 佣金吸引商家；需用「单店抽佣 vs 平台 0 佣金」做商家侧 TCO 对比 |
| 地推/城市密度 | 🟡 运营 | 合并单依赖「多店同区」密度，冷启动城市需补贴 + 商家招募节奏模型 |
| 税务（平台补贴入账） | 🟠 缺失 | 平台出的配送补贴在越南会计准则下如何列支/抵税，需本地会计所意见 |

---

## 5. 融资叙事建议（怎么讲才经得起问）

**讲得通的叙事（基于已证实事实）：**
- 「我们不是另一个外卖 App，而是**跨店合并下单**的结构创新——一次配送、只收一次配送费，解决越南多店分单的高配送成本痛点。」
- 「0 佣金是**商家侧获客武器**，资金逻辑从第一天就是单一事实源、可审计，支付合规前置规避二清。」
- 「技术栈 Laravel + 移动优先 PWA，已写测试与 CI，架构可审计、可快速扩。」

**DD 必答题（提前备好答案，别等被问）：**
1. 后端跑过吗？→ 答：CI 已设计真启栈端到端，X 月 X 日首次绿；附 artifacts。
2. 每单亏多少、何时盈利？→ 答：单位经济模型 + 变现拐点（take-rate/广告/SaaS）。
3. App 上架了吗？→ 诚实：PWA 已可演示，原生壳构建中，目标 X 月出包。
4. 支付合规吗？→ 答：聚合器模式不碰资金池 + 验签 fail-closed + 合规文档。
5. 数据在越南吗？→ 答：部署架构 + 本地化方案（别答「之后再说」）。

---

## 6. 修复优先级路线图（建议）

**P0 — 融资前 2~4 周（证明 + 诚实）**
- [ ] 让 `ci.yml` 的 `backend-smoke` **真跑一次并留绿**（解决 R1）。
- [ ] 本地 Docker `docker-compose up` 跑通 + 录一段真机下单→支付（mock）→查单视频。
- [ ] 准备单位经济模型与一个盈利桥假设（解决 R2）。
- [ ] BP/路演稿把「移动端」表述修正为「mobile-first PWA，原生壳构建中」（解决 R3 诚实性）。

**P1 — 上线前（规模化合规）**
- [ ] 部署拓扑：LB + 多实例 + 队列 worker + 读副本（R4）。
- [ ] 选定 VN 数据区域 + 电子发票接入（R5/R6）。
- [ ] 签署 PSP/聚合器正式协议（R7）。

**P2 — 增长期（运营 + 可观测）**
- [ ] 压测 + 限流 + WAF（R8）；监控/APM/告警（R9）。
- [ ] 城市密度 / 骑手调度运营模型数字化（R10）。

---

## 7. 附：核查证据索引
- 资金逻辑：`backend/app/Services/PaymentSplitService.php`（含 `computeMerged`）
- 支付：`backend/app/Services/PaymentGatewayService.php`（fail-closed 验签 + 聚合器）
- 合并单：`backend/app/Http/Controllers/Api/OrderController.php::storeMerged` + `database/migrations/2026_07_29_support_merged_orders.php`
- 合规：`backend/docs/PAYMENT_COMPLIANCE.md`
- 基建：`docker-compose.yml`、`.github/workflows/ci.yml`（backend-smoke）
- 前端壳：`app/native-bridge.js`（Capacitor 桥，未搭工程）、`docs/iOS-build.md`
- 设计系统：Ardot `GIAONHANH 管理后台 MVP`（705457628728649）全量变量绑定
