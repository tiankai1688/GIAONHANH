# GIAONHANH · 上线前合规与创业风险评估

> 视角：**技术创业者 + 合规顾问**。评估基于仓库实际代码（`composer.json` / `mobile/package.json` / `PaymentGatewayService.php` / 数据模型 / `docker-compose.yml` / `deploy.sh` / `PAYMENT_COMPLIANCE.md` / `native-bridge.js`）。
> 产品：越南同城 30–60 分钟极速达（美团式），VI+ZH 双语，市场越南，货币 ₫。
> 日期：2026-08-01

---

## 0. 评估范围

| 维度 | 结论速览 |
|---|---|
| 开源 LICENSE 商用 | ✅ 无风险（全 MIT / Apache-2.0，无 GPL/AGPL） |
| 第三方 API 条款 | ⚠️ 支付合规设计好，**但 PSP 手续费未计入成本模型**、Pusher/Geolocation 条款与数据处理待补 |
| 用户数据合规 | 🔴 多项缺口（无隐私政策/ToS、定位自动采集无同意、敏感字段明文落库、无删除/留存机制、跨境传输未评估） |
| 规模化算力/API 成本 | ⚠️ 单容器无水平扩展、实时推送与定位写入是成本/瓶颈点，**PSP 手续费是商业模型盲点** |
| 运维与回滚 | 🔴 无回滚机制（`:latest` 无版本标签、无迁移 down、无健康检查探针、无备份文档） |

---

## 1. 开源依赖 LICENSE —— 允许商用吗？

**结论：全部允许商用，无传染性 copyleft 风险。**

- **本项目自身**：`backend/composer.json` 声明 `"license": "MIT"`。MIT 对商业产品友好，仅要求保留版权声明，不构成对自有代码的 copyleft 约束（SaaS 形态下甚至不触发"分发"义务）。
- **后端依赖**（全部 **MIT**）：`laravel/framework ^11`、`laravel/sanctum ^4`、`laravel/tinker`、`doctrine/dbal ^3.8`；dev 依赖 `phpunit`、`pestphp/pest`、`laravel/pint`、`fakerphp/faker` 亦 MIT。
- **前端（Capacitor 6）**：`@capacitor/*` 系列（core/app/android/ios/geolocation/push-notifications/...）均为 **Apache-2.0** —— 商用友好，含专利授权，仅需保留 `NOTICE`。
- **未发现任何 GPL / AGPL / LGPL / MPL 依赖** → 不存在"开源病毒"传染自有闭源代码的风险。

**待办（小项）：**
- 仓库根目录 **没有 `LICENSE` 文件**（仅 `composer.json` 声明 MIT）。对外开源/接收贡献前需补一个根 `LICENSE`（MIT 全文）。
- `mobile/package.json` 未写 `license` 字段（已 `private:true`，可接受，但补上更规范）。

---

## 2. 第三方 API 服务条款 —— 是否违反？

| 第三方 | 用途 | 条款风险 | 处置建议 |
|---|---|---|---|
| **MoMo**（Payment Gateway v2） | 钱包收单 | 持牌 PSP；须注册为商户（`partnerCode`/KYC）、遵守其商户协议；禁止虚假交易/二清转嫁 | 走 **Model A 主商户** 或 **Model B 聚合商**；签署商户协议后方可上线 |
| **ZaloPay** | 钱包收单 | 同上，须 `appId` + key1/key2 + KYC | 同上 |
| **Sepay / Payoo**（聚合商） | 持牌中介 + 分账 | 聚合商协议通常**要求平台对子商户 KYC/AML 负责**；分账须逐商户签约 | 签约前逐条确认"子商户 KYC 责任归属"，避免成为事实上的支付中介 |
| **Pusher**（CDN 加载，`native-bridge.js`） | 实时订单/骑手推送 | SaaS；须签 **DPA**（数据处理协议）；cluster `ap1`=新加坡 → **跨境数据传输** | 上量前签 DPA；评估 PDPD 跨境条款；考虑自建 `laravel-websockets` 控成本+留数据在境内 |
| **Capacitor / Ionic** | 跨端壳 | Apache-2.0，无特殊限制 | 保留 NOTICE 即可 |
| **Google Play / App Store** | 分发 | 实物+本地配送 → 允许外部支付（MoMo/ZaloPay），**不需** Apple IAP / Google Play Billing | 苹果需对应 **External Purchase Entitlement**；越南区确认 Google Play 外部支付政策 |
| **浏览器 Geolocation / Capacitor Geolocation** | 定位 | 受 PDPD/GDPR 约束，**须用户明示同意** | 见 §3 |

**关键架构判断（正面）：** `PAYMENT_COMPLIANCE.md` 已明确"平台不当支付中介、不沉淀客户资金、通过持牌 PSP/聚合商分账"，并区分 Model A/B，方向正确，规避了"二清"红线。

**但有一个商业/合规盲点（见 §4）：当前 0 佣金 + 平台补贴模型里，`PAYMENT_COMPLIANCE.md` 的账目（§3 表）**没有计入 MoMo/ZaloPay 的收单手续费（约 1.5%–3.5%/笔）**。这部分成本若由平台吸收，会侵蚀"仅 platform_subsidy 一项亏损"的假设；若转嫁商户则违背"0 佣金"承诺。必须在上线前确定承担方并写入协议与定价。

**Model A 的残余二清灰区：** 若 GIAONHANH 作为 MoMo/ZaloPay 主商户收到全部客户资金、再"代付"给众多子商户，实质上是代持+转发他人资金。严格按 Nghị định 101/2012（SBV 非银支付中介监管），**规模化后应优先用 Model B（聚合商分账，资金从源头直分商户）**，把"持牌中介"身份交给 Sepay/Payoo，平台仅收自己补贴 line。

---

## 3. 用户数据合规 —— 个保法 / GDPR / 越南 PDPD

**适用法律判定：**
- **越南 PDPD（Nghị định 13/2023/NĐ-CP，2023-07-01 生效）**：只要在越南处理个人数据即适用 → **必然适用**（市场越南）。
- **中国个保法 PIPL**：若处理**中国境内自然人**的个人信息（如中国籍用户/游客、中方团队数据），且属"向境内提供服务"或"分析境内自然人行为"，则触发；跨境提供还需走 CAC 安全评估/标准合同 + 单独同意。当前双语（ZH/VI）但面向越南市场，**若无中国境内用户则可不触发，但需主动确认不存在中国数据主体**，否则 PIPL 跨境条款立刻生效。
- **GDPR**：若向 EU 居民提供服务（如欧盟越侨）则适用；否则不直接适用，但 Pusher(ap1)/CDN 的欧盟节点可能引入边缘情形。

### 3.1 实际存储的 PII（来自数据模型）
- `users`：`name, phone, email, password(hash), role, lat, lng`（**精确地理位置**）
- `merchants`：`contact_name, phone, email, address, business_license, **bank_account**, **kyc_status**, lat, lng`
- `riders`：`name, phone, **id_card**（身份证号，敏感）, **kyc_status**, lat, lng`（**实时位置追踪**）
- `orders`：收货地址、商品、电话
- `refresh_tokens`、`device_token`（FCM/APNs 推送令牌）

### 3.2 🔴 必须解决的缺口（P0）
1. **无隐私政策 / 服务条款文件**：仓库内没有任何 `privacy*` / `terms*` 文档。App Store / Google Play 上架**强制要求**隐私政策链接；PDPD/GDPR 亦要求告知数据处理目的、依据、权利。**上线前必须发布双语（VI+ZH/EN）隐私政策 + ToS。**
2. **定位自动采集、无同意（直接违规）**：`native-bridge.js` 在 `DOMContentLoaded` 时**自动调用 `native.getLocation()`**（`getCurrentPosition`），未弹出任何定位同意告知。精确地理位置在 PDPD 与 GDPR 中均属敏感个人数据，**处理前必须获得明示、可撤回的同意**。骑手端连续位置追踪同理需单独同意 + 目的限定。
3. **敏感字段明文落库（未加密）**：`PAYMENT_COMPLIANCE.md §5` 写明"生产环境 `users.phone` 加密存储"，但代码中 `$hidden` 仅控制序列化、并不加密——`phone / email / address / id_card / bank_account` 在 DB 中均为明文。`id_card`（身份证）、`bank_account`（银行卡）属高敏，明文泄露后果严重。**生产前须对高敏字段做静态加密（或字段级加密 / 库加密），至少 id_card 与 bank_account。**
4. **无数据主体权利实现**：无"注销账号 / 删除我的数据"端点、无数据导出、无留存期限策略。PDPD/GDPR 的访问/更正/删除权无法满足。**须实现账户注销 + 关联数据删除/匿名化 + 留存策略（交易数据按税法/AML 留 ≥5 年，其余最短化）。**
5. **跨境传输未评估**：Pusher `ap1`(新加坡)、jsDelivr/CDN 全球节点、潜在云厂商区域 → 越南数据主体数据出境。PDPD 要求跨境提供需满足条件（告知 + 保障水平）。**须列清所有出境数据流、签署接收方 DPA、必要时取得同意。**

### 3.3 ⚠️ 次高（P1）
- **推送令牌采集**：`Push.register()` 在 `native.ready()` 自动执行并上传 `device_token`；iOS/Android 13+ 有系统级通知权限弹窗，但**隐私披露与用途说明缺失**，且需与隐私政策一致。
- **登录无限流/锁定**（前序安全审计已指出）：不仅是安全问题，也是 GDPR/PDPD "处理安全"（Art.32 / 第 24 条）义务缺口 → 增加泄露风险。
- **日志可能含 PII**：`Log::error('MoMo create failed', $body)` 等若 `body` 含订单/用户信息，会落入 `storage/logs` 明文日志 → 需脱敏或限制访问。
- **`$hidden` 已遮挡 `bank_account` 于序列化**（已修），但需复核 `rider.lat/lng`、`push_token` 是否在响应中被不必要地暴露。

---

## 4. 规模化后的算力与 API 成本

### 4.1 算力 / 架构瓶颈
- **单容器单主机**：`docker-compose.yml` 一个 `app`（php-fpm+nginx+supervisor+queue worker 同体），`restart: unless-stopped`，**无水平扩展、无自动伸缩、无负载均衡**。命中量增长时单点瓶颈。
- **队列与实时**：生产应 `QUEUE_CONNECTION=redis`（compose 已起 redis，但 CI 用 `sync`）；worker 与 web 同容器，队列积压会拖垮 web。建议**独立 worker 服务 + 按负载扩副本**。
- **骑手位置写入**：`rider.lat/lng` 每次定位 ping 都 `UPDATE riders` 单行 → 高并发写放大。应**迁移到独立 `rider_positions` 时序表 / Redis Geo**，主表只保留最后已知点。
- **实时推送**：Pusher 按消息/连接计费，骑手+商家+订单事件全量上量后费用显著。可改用**自托管 `laravel-websockets`**（数据留境内 + 成本可控），或预留 Pusher 付费预算。

### 4.2 API / 第三方成本（越南语境估算）
| 成本项 | 估算 | 谁承担 | 备注 |
|---|---|---|---|
| **MoMo/ZaloPay 收单手续费** | **~1.5%–3.5% / 笔** | ❓**当前模型未定义** | **核心盲点**：0 佣金承诺不含此项；须明确由平台吸收（增亏）或另行约定 |
| Pusher | 免费额度 20 万消息/日；超出 ~$0.05/千条 | 平台 | 上量后需预算或自托管 |
| 短信 OTP（若加登录验证码） | ~₫200–800/条（~$0.01–0.03） | 平台 | 当前 auth 仅密码+token，未用 OTP |
| 地图/路径/ETA（若加） | Google Maps ~$5–10/千次调用 | 平台 | 当前无地图 SDK，规模化必需 |
| 云主机 + MySQL + Redis | 中等配置 ~$40–120/月 | 平台 | 单主机起步，扩展后上浮 |
| FCM/APNs 推送 | 免费 | — | — |

**成本模型建议**：把"PSP 手续费"单列进 `orders` 账目（类似 `platform_subsidy`），明确承担方；在招商与商户协议里写清"0 佣金 = 平台不抽成，但支付通道费 X% 由__方承担"。避免上线后因手续费导致单位经济（unit economics）为负却无人负责。

---

## 5. 上线后运维与回滚能力

### 🔴 关键缺口
1. **无回滚机制**：`deploy.sh` 用 `docker compose build app && up -d`，镜像固定 `giaonhanh-app:latest`——**没有版本标签、不保留上一个镜像、无"回退到上一版"步骤**。坏版本上线只能重新 build 旧 commit，慢且易错。
2. **迁移无回退**：`migrate --force` 全是前向迁移；破坏性迁移（改列/删列）一旦出错无 `down()` 演练。建议**迁移前向兼容化**（先加后删、用特性开关），并对破坏性变更单独演练回滚。
3. **容器无健康检查探针**：`docker-compose.yml` 的 `app` 没有 `healthcheck`；`deploy.sh` 仅 curl `/api/health` 一次。建议加 `healthcheck`（HTTP + DB 探活），配合 `depends_on: condition: service_healthy`。
4. **无蓝绿/金丝雀**：单副本 `up -d` 切换有短暂中断；规模化需零停机发布（多副本 + 就绪探针 + 滚动）。
5. **无备份/容灾文档**：`mysql_data` 是卷，但**没有备份脚本/演练/恢复 SOP**。订单、结算、商户资料一旦损坏不可逆。须定时备份 + 定期恢复演练。
6. **可观测性弱**：日志落 `storage/logs` 文件，无集中日志/指标/告警；异常无主动通知。规模化须接入集中日志 + 监控（如 Grafana/Loki 或云厂商托管）。
7. **密钥管理**：`.env` 在主机，默认 `DB_ROOT_PASSWORD=change_this_root` / `DB_PASSWORD=change_this_app`——**上线前必须改强口令**；建议引入密钥管理（Vault / 云 Secrets Manager）。`ADMIN_SEED_PASSWORD` 已改为随机（已修，好）。

### ✅ 已有基础
- CI 四 job（contract / laravel / pest / backend-smoke）验证构建与端到端可运行。
- `deploy.sh` 做了 migrate + seed + config/route 缓存 + 健康检查，流程清晰。
- `restart: unless-stopped` 提供基本进程自愈。

---

## 6. 上线前必须解决的合规清单

### 🔴 P0（不发版红线）— 落地状态（2026-08-01「立即修」）

| # | 项 | 状态 | 落地物 |
|---|---|---|---|
| 1 | 双语隐私政策 + 服务条款 | 🟡 模板已写，待填实体/联系方式/生效日期 + App 内挂链 | `docs/privacy-policy.md`、`docs/terms-of-service.md`、`docs/merchant-agreement.md` |
| 2 | 定位/推送采集加明示同意 | ✅ 代码已改 | `app/native-bridge.js`：移除自动 `getLocation()`，`GN.consent` 门控；push 注册同样门控 |
| 3 | 高敏字段加密 | 🟡 `id_card`/`bank_account` 已加密；`users.phone` 待盲索引方案 | `Rider.id_card`、`Merchant.bank_account` 加 `encrypted` cast + 列宽 512 迁移 |
| 4 | 账户注销 + 删除/匿名化 + 留存 | ✅ 已实现 | `DELETE /api/v1/account` + `User` SoftDeletes + PII 匿名化（保留订单/结算审计 ≥5 年） |
| 5 | 跨境数据流清单 + DPA | ❌ 需法务签署（数据流已在隐私政策披露） | 待签 Pusher/CDN/云 DPA |
| 6 | 明确 PSP 手续费承担方 | 🟡 已记账 + 默认平台吸收，待财务/法务确认 | `orders.psp_fee`/`psp_fee_bearer` 列 + `config/payment.psp_fee_bearer` + 商户协议条款 |
| 7 | 支付通道签约完成 | ❌ 需商务/法务签约 | MoMo/ZaloPay KYC 或 Sepay/Payoo 聚合商协议 |
| 8 | 登录限流/锁定 | ✅ 已实现 | `AuthController::login` 失败 5 次锁 15 分钟 + `auth` 限流 60→10/分 |

> 备注：`users.phone` 因登录按手机号查询（`where('phone',...)`），直接 `encrypted` 会破坏查询，需采用**盲索引（hash 列）**方案，列为后续项。其余 P0 代码/文档层均已落地；仅"需签约/需法务确认"两类（#5/#7 及 #1/#3/#6 的填空）非代码可独立完成。

### ⚠️ P1（上线后 30 天内）
- [ ] 部署**回滚机制**：镜像版本标签 + 保留上一镜像 + 一键回退步骤；迁移前向兼容 + 破坏性变更演练。
- [ ] 容器 `healthcheck` + 就绪探针；规划多副本/零停机发布。
- [ ] **数据库备份 + 恢复演练 SOP**；密钥改强口令 + 引入 Secrets Manager。
- [ ] 集中日志/监控/告警；日志 PII 脱敏。
- [ ] 评估 Pusher 上量成本或切换自托管 WebSocket。
- [ ] 骑手位置迁移到独立位置表/Redis，避免主表写放大。
- [ ] 完成 PIPL 触发判定（是否存在中国数据主体）；若触发则走跨境合规。

### 🟢 P2（规模化准备）
- [ ] 水平扩展（web/worker 分离、读副本、缓存层）。
- [ ] 地图/路径/ETA 服务选型与成本建模。
- [ ] 短信 OTP（如需）供应商与成本。
- [ ] 根 `LICENSE` 文件 + `mobile` license 字段补全。
- [ ] 反洗钱监控（大额/结构化交易预警，留存 ≥5 年）。

---

## 7. 已具备的合规优势（肯定）

- **支付架构合规设计到位**：明确不当支付中介、不沉淀客户资金，Model A/B 规避二清红线（`PAYMENT_COMPLIANCE.md` 详尽）。
- **开源零传染风险**：全 MIT / Apache-2.0，无 GPL/AGPL。
- **签名验签 fail-closed**：网关回调 HMAC 验签在缺密钥时拒绝而非回退默认密钥（前序安全审计已加固），杜绝伪造"已支付"回调。
- **`$hidden` 已遮挡 `bank_account`**，admin 列表不再裸泄露卡号。
- **CI 门禁 + 端到端 smoke** 保证可构建、可运行。
- **0 佣金 + 平台补贴账目**在 `orders` 表清晰编码，商业承诺可审计。

---

*本评估基于静态代码审阅，不构成法律意见。涉及 SBV 支付牌照、PIPL/GDPR/PDPD 具体合规，建议上线前聘请越南当地合规律师 + 数据保护官（DPO）复核。*
