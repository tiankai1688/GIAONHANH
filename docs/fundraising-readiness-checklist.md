# 融资前技术就绪 Checklist（Phase 0）+ 全路径 18 步

> 对应路线图：Phase 0（融资就绪，2–4 周）→ Phase 1（越南真闭环公测，4–8 周）→ Phase 2（300M 规模化，8–16 周）。
> 本文件是"到能交付/能过 DD"的可勾选清单。✅ = 已完成，⬜ = 待办。

---

## Part A — Phase 0：融资前技术就绪（6 步，~1 周可闭）

| # | 交付物 | 归属 | 状态 | 关键命令 / 说明 |
|---|---|---|---|---|
| 1 | CI 绿证（pest + backend-smoke） | 你侧 | ⬜ | 在自有仓库 `git push` 一次触发 `.github/workflows/ci.yml` 四 job |
| 2 | 真机演示视频 | 你侧 | ⬜ | 见文末"你侧动作确切命令" |
| 3 | 压测脚本（k6 / Node） | **已交** | ✅ | `tools/load-test.k6.js` + `tools/load-test.mjs` |
| 4 | BP 诚实叙事草案 | **已交** | ✅ | `docs/bp-honest-narrative.md` |
| 5 | 融资就绪 Checklist（本文件） | **已交** | ✅ | `docs/fundraising-readiness-checklist.md` |
| 6 | 营收模型决策（佣金% / 增值） | 你侧 | ⬜ | 需在 BP 标注假设；分账系统 `PaymentSplitService` 已支持配置切换 |

> 我（AI）可立即代写的 ③④⑤ 已交付。剩余卡点 = 你侧 2 动作（push + 录视频）+ 1 决策（营收）。

---

## Part B — 全路径 18 步总表

### Phase 0（证据与诚实）
- [x] 3 压测脚本
- [x] 4 BP 诚实叙事
- [x] 5 就绪 Checklist
- [ ] 1 CI 绿证（待 push）
- [ ] 2 真机演示视频
- [ ] 6 营收模型决策

### Phase 1（越南真闭环公测）
- [ ] 7 原生壳出包（Capacitor / APK / TestFlight）
- [ ] 8 PSP 生产签约 + 真实密钥（MoMo / ZaloPay）
- [ ] 9 真实短信网关（注册 OTP）
- [ ] 10 结算接真实出款 API（清掉台账模式）
- [ ] 11 专职调度服务原型（geohash/RTree + GPS + ETA + 自动派单）
- [ ] 12 数据：只读副本 + 分片设计启动 + Redis 集群
- [ ] 13 边缘：API 网关 + WAF + CDN；可观测：Prometheus + OTel + 日志 + 告警

### Phase 2（300M 规模化预备）
- [ ] 14 热路径服务拆分（调度/结算/通知独立部署 + 独立 DB）
- [ ] 15 全量地理空间 + ML 调度（ETA 预测、动态定价）
- [ ] 16 越南数据本地化多区 + 容灾（DR）
- [ ] 17 混沌工程 / SLO / on-call
- [ ] 18 回滚与发布工程固化

---

## Part C — DD 五题答案卡模板（填真实值）

1. **跑过吗？** 后端 smoke 全链路通过；CI 首次绿证日期：____；生产 7×24 运行：否。
2. **亏多少 / 单位经济？** 当前每单净成本（0 佣金 + 平台补贴）；佣金% / 增值服务变现：____（假设/已验证）。
3. **App 上架了吗？** PWA 可演示；原生上架：____（构建中 / 已上架）。
4. **合规吗？** OTP + PII 加密 + 隐私/条款/商户协议已备；PSP 生产签约：____；数据本地化：____。
5. **数据在越南吗？** 单区域部署已做网络隔离；多区本地化 + 容灾：Phase 2。

---

## Part D — 你侧动作确切命令

### D1. 触发 CI 绿证（第 1 步）
```bash
# 在已初始化 git 的本地仓库根目录
git add -A
git commit -m "ci: enable pest + backend-smoke gates"
git push            # 触发 .github/workflows/ci.yml 四 job
# 到 GitHub Actions 看 pest / backend-smoke 是否全绿，截图存档
```

### D2. 录真机演示视频（第 2 步）
```bash
# 1) 起本地服务（参照 backend/ 的 docker-compose 或 DEPLOY.md）
docker compose up -d
# 2) 跑一次端到端 smoke 证明"能跑"
API_BASE=http://localhost:8080 node tools/backend-smoke.mjs
# 3) 用浏览器/录屏工具录制：首页 → 浏览商家 → 加购 → 跨店合并下单 → 支付(COD) → 订单详情
# 4) 同时跑一次压测留底（scale-readiness 证据）
API_BASE=http://localhost:8080 DURATION=30 VUS=20 node tools/load-test.mjs
```

### D3. 营收模型拍板（第 6 步）
在 `backend/config/business.php` 的 `commission_rate` 设目标值（当前 0），并在 BP 风险章标注假设与切换时点。

---

> 维护：每完成一项，把 Part A / Part B 的 ⬜ 改为 ✅ 并补日期。CI 绿证与视频是 Phase 0 的"硬门槛"，缺一不可。
