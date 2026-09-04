# GIAONHANH（越南版美团）缺口联合评审报告

**日期**：2026-07-19
**场景**：GIAONHANH 缺口联合评审（产品功能 / 安全合规 / QA发布 / 代码质量 / 设计UX 五维度）
**参与成员**：产品官(gstack-product-reviewer) + 安全卫士(gstack-security-officer) + 质量门神(gstack-qa-lead) + 排障手(gstack-investigator) + 设计顾问(主 agent 亲自执行，遵守 ardot 禁子代理约束)

---

## 📌 TL;DR（执行摘要）

- 整体结论：🔴 **不通过（作为可上线产品）**；当前为约 **65–70% 原型级**——代码架构干净、前后端契约一致、设计稿 9 页闭环，但**存在多处 P0 安全红线 + 功能死按钮 + 零测试/零CI/零监控 + 不可独立运行打包**。
- 阻塞项数量：**11 项 P0**（安全 6 + 功能 1 + 工程 3 + 移动端 1），其中任一都会阻断生产 GA。
- 最关键三件事：① 修掉越权提权/二清/认证造假等**安全红线**；② 打通 C 端地址/领券/售后、商家上新等**功能死点**；③ 让后端**真正能跑起来**（PHP 环境 + 测试可执行 + CI + 可观测）。
- 下一步：按"安全红线 → 功能死点 → 工程就绪 → 可观测/发布 → 设计补全"顺序推进；建议先做 P0 闭环再谈灰度上线。

---

## 🎯 核心结论卡片

| 项目 | 内容 |
|------|------|
| Go / No-Go | 🔴 No-Go（生产上线）；🟡 演示原型可用 |
| 严重度分布 | 🔴 11 / 🟠 11 / 🟡 6（去重合并后） |
| 工程就绪度 | ~65–70%（prototype-grade，非 production-ready） |
| 关键行动项 | 6 条（5 条 P0/P1 具体见下） |
| 建议负责人 | 安全官+调查员（红线）/ 产品官+调查员（功能）/ 调查员+QA（工程）/ QA（发布）/ 设计顾问（设计） |

---

## 1. 各成员核心结论

### 🔍 产品官（产品评审）
- 核心判断：对照美团/GrabMart/ShopeeFood，当前**产品层"看起来全、点下去空"**——C 端地址管理、优惠券领券中心、退款售后三处是死按钮（toast"演示版未开放"）；商家端无 create-product 无法上新；骑手端仅静态地图无真实导航；平台"优惠券/活动"后台仅是 Ardot 设计稿，AdminController 无券 CRUD。
- 关键建议：先补**"地址+领券+售后"留存三件套** → 商家补"上新+入驻向导" → 骑手升级真实导航 → 平台补"活动+风控+报表"三支柱 → 统一"钱包/积分/会员"增长底座。

### 🛡️ 安全卫士（OWASP+STRIDE 审计）
- 核心判断：发现**6 处 P0 安全红线**——register 客户端可控 `role`(直获 admin)、login 无密码/无OTP(手机号接管)、`coupon_discount` 客户端可控套补贴、IPN 验签密钥缺失回退硬编码默认密钥(可伪造已支付)、默认 `PAYMENT_AGGREGATOR=none` 形成**资金池/二清风险**、KYC 证件与手机号明文未加密(违反越南 PDPD)。另有 token 无过期/吊销、IPN 未校验金额、退款无幂等、CORS `*`+credentials、无限流等 P1。
- 关键建议：register 移除客户端 role（admin 须服务端按审核发）；login 强制 OTP/密码；IPN fail-closed + 金额校验 + 去重表；默认启用持牌聚合源头分账(Model B)；KYC/PII 加密 + CORS 白名单 + 全路由 throttle + 安全响应头。

### ✅ 质量门神（QA测试与发布）
- 核心判断：**测试/CI/可观测三大底座全缺**——后端测试不可执行（缺 phpunit.xml、pest 语法但无 pest 依赖、沙箱无 PHP 从未真跑）；全仓零 CI/CD；零错误监控/APM/业务看板/告警，队列 sync 无 worker；"27/27 PASS"实为**静态契约匹配**非运行时覆盖；iOS 未构建(cleartext:true)、HTTPS/域名未确认。
- 关键建议：先统一测试框架(补 pest+phpunit.xml)在真实 PHP 跑绿；verify-contract 进 CI 但标"仅静态契约"；补真 HTTP 集成测试覆盖 44 路由(支付/IPN/抢单/退款)；可观测先行(Sentry+核心指标+告警)是 Canary 前提；发布走"封闭单城试点+特性开关+版本化回滚"，iOS 先 TestFlight。

### 🔧 排障手（调试与代码质量）
- 核心判断：代码**架构干净**（控制器精简、Service 分层清晰、真实 MoMo/ZaloPay HMAC、合规聚合无二清、27 端点契约前后一致、前端真对接非静态 demo），但**工程就绪缺口明显**——Auth 无密码漏洞（与安审互证）；backend 是 source drop-in 不可独立运行(缺 public/index.php、tests/TestCase.php)；测试仅 3 个 service 级；实时/push 禁用(BROADCAST_DRIVER=null)、FCM/APNs 未配；root index.html 是 app/index.html 的 stale 副本(47KB vs 76KB)；缺 API 参考+部署文档、无 CI、无 model factories。
- 关键建议：补齐运行引导(public/index.php + tests 引导 + pest)让后端真跑；扩测试到 Auth/Merchant/Rider/Admin/Agent/退款/推送；启用广播+配 FCM/APNs；删 stale 副本、补 API/DEPLOY 文档与 factories。

### 🎨 设计顾问（设计系统与视觉，主 agent 亲自）
- 核心判断：管理后台 **9 页已全部闭环**（结构对齐+语义审计+3 项遗留缺陷全修，capture_layout 0 问题），但**画布覆盖的只是"运营中台"**——缺 C 端消费者 APP、商家端、骑手端的高保真设计稿；缺管理员登录页、权限/角色管理页、通知中心、系统设置页、帮助中心；交互态与原型连线(prototype wiring/hover/抽屉关联)未做；暗色模式/响应式未涉及；空状态已规范化但仅作"预览卡+规范表"，未在生产数据态验证；像素级视觉 QA 因模型无法读图仍待人工/多模态复核。
- 关键建议：补 C 端/商家端/骑手端三套设计稿（与后台统一设计语言）；补登录页+权限页；做原型连线与交互态；安排一次人工视觉 QA；后续再考虑暗色/响应式。

---

## 2. 综合审查发现（去重合并后按严重度排序）

| # | 严重度 | 类别 | 位置 | 问题描述 | 建议 | 来源成员 |
|---|--------|------|------|---------|------|---------|
| 1 | 🔴 | 安全/越权 | AuthController.php:22,31 | register 客户端可控 `role`，传 `role:admin` 直获 admin 令牌，绕过 `/admin/*` 网关 | role 必须服务端按审核发放，禁止客户端传入 | 安全/排障 |
| 2 | 🔴 | 安全/认证 | AuthController.php:42-57 | login 无密码/无 OTP，仅凭手机号接管账号 | 强制密码+OTP 双因子 | 安全/排障 |
| 3 | 🔴 | 安全/逻辑 | CreateOrderRequest.php:27 + OrderController.php:48 | `coupon_discount` 客户端可控、无上限、无单人限额，伪造大额券套取平台补贴 | 券服务端计算+核销记账 | 安全 |
| 4 | 🔴 | 安全/支付 | PaymentGatewayService.php:33,143,250,359 | IPN 验签密钥缺失时回退硬编码默认密钥，生产漏配可伪造"已支付" | 密钥缺失必须 fail-closed(return false) | 安全 |
| 5 | 🔴 | 合规/二清 | config + SettlementService + PaymentGatewayService | 默认 `PAYMENT_AGGREGATOR=none` 资金先沉淀平台商户号、无代付实现=事实资金池/二清 | 默认启用持牌聚合源头分账(Model B)或取牌照+实现代付 | 安全/QA |
| 6 | 🔴 | 合规/隐私 | Merchant.php:14-19, User.php | KYC 证件(营业执照/银行账号)与 users.phone 明文未加密，违反越南 PDPD | PII 加密存储 | 安全 |
| 7 | 🔴 | 产品/功能死点 | app/index.html (profile 区) + AdminController | C 端地址/领券/售后=toast"演示版未开放"；商家无 create-product；骑手仅静态地图；优惠券后台无 CRUD | 打通留存三件套+商家上新+骑手导航+券后台 | 产品 |
| 8 | 🔴 | 工程/可运行 | backend/ (public/, tests/) | backend 是 source drop-in 不可独立运行：缺 public/index.php、tests/TestCase.php+CreatesApplication.php | 补运行引导，真实 PHP 跑 migrate+seed | 排障/QA |
| 9 | 🔴 | 工程/CI | 仓库根 | 全仓零 CI/CD（无 .github/gitlab/circleci/jenkins） | 搭 CI：lint+静态分析+契约校验+测试+composer audit | QA |
| 10 | 🔴 | 工程/可观测 | 全栈 | 零错误监控(Sentry)、零 APM、零业务看板、零告警；队列 sync 无 worker | Sentry(PHP+JS)+核心指标+阈值告警 | QA |
| 11 | 🔴 | 移动/上线 | mobile/ios/ 缺失; capacitor.config cleartext:true | iOS 未构建（需 Mac）、cleartext:true、HTTPS/域名未确认、真实支付号未到位 | iOS 构建+TestFlight+cleartext:false+HTTPS | QA |
| 12 | 🟠 | 安全/会话 | AuthController createToken | Sanctum token 无过期/无吊销接口 | 加 expires_at + 吊销端点 | 安全 |
| 13 | 🟠 | 安全/支付 | PaymentController.php:104-119; OrderController.php:119-124 | IPN 未校验回调金额==order.amount；退款无并发幂等锁；无重放去重表 | 金额校验+幂等锁+去重表 | 安全 |
| 14 | 🟠 | 安全/基础 | config/cors.php:19,29; routes/api.php | CORS 默认 `*` 且 supports_credentials=true；全路由无限流 throttle | CORS 白名单+全路由 throttle | 安全 |
| 15 | 🟠 | 合规/PDPD | 全局 | 无同意记录、无用户删除权接口、无数据本地化(越南)配置 | 补 PDPD 同意/删除权/本地化 | 安全 |
| 16 | 🟠 | 测试 | tools/verify-contract.mjs; app/ | "27/27 PASS"是静态契约匹配非运行时；支付 E2E 仅 Node 桩；前端零测试 | 补真 HTTP 集成测试覆盖 44 路由+前端测试 | QA |
| 17 | 🟠 | 质量门禁 | composer.json / devDeps | 无 lint/静态分析(phpstan/eslint)接入、无覆盖率门槛、无 composer audit | 接 phpstan+eslint+audit 入 CI | QA |
| 18 | 🟠 | 发布 | 仓库根 | 无 VERSION/CHANGELOG/semver；回滚预案仅文档未自动化 | 版本管理+特性开关+自动回滚 | QA |
| 19 | 🟠 | 实时 | config(broadcasting/BROADCAST_DRIVER=null) | 实时/push 禁用，骑手抢单靠轮询；FCM/APNs 未配 | 启用广播+配 FCM/APNs | 排障 |
| 20 | 🟠 | 测试 | backend/tests | 测试覆盖窄（仅 OrderStateMachine/PaymentGatewaySignature/PaymentSplitService 3 个） | 扩至 Auth/Merchant/Rider/Admin/Agent/退款/推送 | 排障/QA |
| 21 | 🟠 | 设计/覆盖 | Ardot 画布(705457628728649) | 仅运营中台 9 页；缺 C 端/商家端/骑手端设计稿、登录页、权限页 | 补三端设计稿+登录/权限页 | 设计 |
| 22 | 🟠 | 设计/交互 | Ardot 画布 | 交互态与原型连线(prototype wiring/hover/抽屉关联)未做；视觉 QA 待人工 | 做连线+交互态+人工视觉 QA | 设计 |
| 23 | 🟡 | 安全/响应头 | 全局中间件 | 无 CSP/HSTS/X-Frame-Options；无 TrustHosts/TrustProxies | 加安全头中间件 | 安全 |
| 24 | 🟡 | 安全/XSS | API 返回 + app/index.html | PII 入日志；API 返回未过滤用户输入(name/address/note)，前端 innerHTML 直插→存储型 XSS | 日志脱敏+输出转义 | 安全 |
| 25 | 🟡 | 安全/上传 | KYC 端点 | KYC 证件上传端点未实现(仅字符串字段)，缺安全上传校验 | 实现带校验的安全上传 | 安全 |
| 26 | 🟡 | 工程/冗余 | 根 index.html (47KB) | 是 app/index.html(76KB 双模 live/demo) 的 stale 副本 | 删除或同步 | 排障 |
| 27 | 🟡 | 文档 | 仓库根 | 缺 API 参考+部署文档；无 model factories | 补 API/DEPLOY 文档+factories | 排障 |
| 28 | 🟡 | 设计/模式 | Ardot 画布 | 暗色模式/响应式未涉及；空状态仅预览卡未生产态验证 | 后续按需补 | 设计 |

---

## ✅ 行动清单（关键可执行项）

| # | 行动 | 负责方 | 紧急度 | 期望完成 |
|---|------|--------|--------|---------|
| 1 | 安全红线闭环：register 移除客户端 role + login 强制 OTP/密码 + IPN fail-closed(密钥缺失 return false)+金额校验+去重 + 默认启用持牌聚合(Model B) + KYC/PII 加密 | 安全官 + 排障手 | P0 | 上线前必须 |
| 2 | 功能死点打通：C 端地址/领券/售后 + 商家 create-product 上新 + 骑手真实导航 + 优惠券后台 CRUD | 产品官 + 排障手 | P0 | 灰度前 |
| 3 | 后端真正可运行：补 public/index.php + tests 引导(pest+phpunit.xml) + 真实 PHP 跑 migrate --seed + 现有用例跑绿 | 排障手 + QA | P0 | 两周内 |
| 4 | 可观测 + CI：Sentry(PHP+JS)+核心指标(订单量/支付成功率/崩溃率)+告警；CI(lint+静态分析+契约校验+测试+composer audit) 门禁阻断 | QA | P0 | 灰度前 |
| 5 | 移动端上线就绪：iOS 构建+TestFlight 内测 + cleartext:false + HTTPS/域名 + 真实支付商户号 | QA | P1 | GA 前 |
| 6 | 设计补全：C/商家/骑手三端设计稿 + 登录页 + 权限/角色管理页 + 原型连线 + 一次人工视觉 QA | 设计顾问 | P1 | 并行推进 |

---

## ⚠️ 待完善 / 已知局限

- 本次为**只读评审**，未改动任何源码/画布；所有结论基于实读代码、原型、记忆与 Ardot 画布审计结论。
- 设计维度由主 agent 亲自完成（ardot skill 硬规则禁止子代理操作画布）；其余 4 维度由对应子代理独立分析后回传。
- 沙箱无 PHP，后端"能跑"为基于代码结构的推断，未真机验证；IPN 验签链路 Node 端到端真跑过、外部 PSP 仅桩。
- 画布视觉对齐（像素级）因模型无法读图，仍依赖人工/多模态复核。
- Ardot 画布读取在本次会话末段出现适配器超时，设计维度结论沿用本会话全程参与的画布状态，非实时重读。

---

## 📚 成员产出索引

- gstack-product-reviewer（产品官）原始产出：C 端/商家端/骑手端/平台运营四模块功能缺口清单(P0 命门：地址/领券/售后死按钮、商家无上新、骑手静态地图、优惠券后台无 CRUD) + 5 条路线图建议（经 team 消息回传）。
- gstack-security-officer（安全卫士）原始产出：OWASP API Top10 + STRIDE 审计，6 处 P0（越权/认证/套补贴/IPN伪造/二清/PII明文）+ P1（token/CORS/限流/PDPD）+ 二清合规结论(Model A 不合规/Model B 合规)（经 team 消息回传）。
- gstack-qa-lead（质量门神）原始产出：测试/CI/可观测/上线就绪度缺口（11 项严重度分级）+ 上线前 Must-have 8 条 + 5 条建议（经 team 消息回传）。
- gstack-investigator（排障手）原始产出：代码完整性/可运行性/测试覆盖/实时推送/文档 技术债清单 + 工程就绪度 ~65–70% + 5 条建议（经 team 消息回传）。
- 设计顾问（主 agent 亲自）原始产出：基于 Ardot 画布 11 顶层帧审计的"仅运营中台、缺三端/登录/权限/连线/视觉QA"设计缺口结论（见本报告 §1 设计顾问段）。

---

> 本报告由软件工坊 AI 协作生成，关键决策（尤其二清合规与支付牌照）请由工程负责人与法务复核。
