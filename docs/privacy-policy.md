# Chính sách Bảo mật · GIAONHANH
## 隐私政策 · GIAONHANH 越南全域小时达

> 版本 / Version: 2026-08-01 · 生效日期 / Effective: [待上线填日期]
> 适用产品 / Applies to: GIAONHANH 移动应用（消费者 / 骑手 / 商家）及网站
> 运营主体 / Data controller: [公司法律实体名称，上线前补全]

---

### 1. 我们是谁 / Who we are
- **VI**: GIAONHANH là nền tảng giao hàng nhanh trong thành phố tại Việt Nam (giao hàng 30–60 phút). Chúng tôi là bên kiểm soát dữ liệu (data controller) theo Nghị định 13/2023/NĐ-CP (PDPD).
- **ZH**: GIAONHANH 是在越南提供同城 30–60 分钟极速达的平台。我们作为数据控制方，受越南《个人数据保护法令》（PDPD, Nghị định 13/2023）约束。

### 2. 我们收集哪些数据 / What we collect
| 类别 / Category | 字段 / Fields | 来源 / Source |
|---|---|---|
| 账户 / Account | 姓名、手机号、邮箱、密码（哈希） | 注册时提供 |
| 精确位置 / Precise location | 经纬度（lat/lng） | **仅在您明示同意并授予定位权限后**采集 |
| 身份核验 / KYC | 身份证号（骑手）、营业执照、银行卡号（商家） | 入驻/接单核验 |
| 订单 / Orders | 收货地址、商品、联系方式 | 下单时 |
| 设备 / Device | 推送令牌（FCM/APNs）、设备型号 | **仅在您同意推送后**上传 |

> **VI**: Chúng tôi KHÔNG thu thập vị trí hoặc token push cho đến khi bạn chủ động đồng ý. Việc tự động thu thập đã bị loại bỏ.
> **ZH**: 在您主动同意前，我们**不会**自动采集定位或推送令牌（已移除自动采集逻辑）。

### 3. 处理目的与法律依据 / Purposes & legal basis
- **合同履行（PDPD 第 9 条 / GDPR Art.6(1)(b)）**：下单、配送、结算所必需。
- **明示同意（Art.6(1)(a)）**：精确位置追踪、营销推送——可随时撤回。
- **法定义务**：交易与结算数据按税法/反洗钱要求留存 ≥ 5 年。

### 4. 数据共享与第三方 / Sharing & processors
- **持牌支付机构（MoMo / ZaloPay / Sepay / Payoo）**：仅传输完成支付所必需的信息；我们不沉淀客户资金、不经手二清。
- **实时推送（Pusher, cluster ap1=新加坡）**、**CDN（jsDelivr）**：属跨境传输，我们已与之签署数据处理协议（DPA），并采取加密传输。
- **绝不出售数据**。

### 5. 跨境传输 / Cross-border transfer
- **VI**: Dữ liệu của bạn được LƯU TRỮ TẠI VIỆT NAM. Một số dữ liệu vận hành có thể được xử lý ngoài Việt Nam (Pusher tại Singapore, CDN toàn cầu) — chúng tôi ký DPA với bên nhận và áp dụng biện pháp bảo vệ phù hợp theo Chương V Nghị định 13/2023/NĐ-CP (PDPD).
  - **Đội ngũ phát triển tại nước ngoài**: GIAONHANH được phát triển bởi đội ngũ tại Trung Quốc, nhưng CÔNG TY VẬN HÀNH là thực thể tại Việt Nam (bên kiểm soát dữ liệu). Quyền truy cập dữ liệu sản xuất của đội ngũ phát triển bị KIỂM SOÁT NGHIÊM NGẶT: (1) KHÔNG truy cập trực tiếp cơ sở dữ liệu sản xuất; (2) chỉ truy cập bản sao ẨN DANH / TỔNG HỢP qua cầu nối có phê duyệt; (3) KHÔNG gửi bất kỳ PII sản xuất nào về Trung Quốc qua telemetry/log. Mọi xử lý xuyên biên giới tuân thủ PDPD.
- **ZH**: 您的数据**存储于越南**。部分运维数据可能在越南境外处理（Pusher 新加坡节点、全球 CDN），我们签署接收方 DPA 并按 PDPD 第五章采取保护措施。
  - **中国开发团队**：GIAONHANH 由中国团队开发，但运营主体为越南实体（数据控制方）。开发团队对生产数据的访问受到严格管控：(1) 不直接访问生产数据库；(2) 仅通过需审批的桥接访问**匿名化/聚合**副本；(3) **不**通过遥测/日志将任何生产 PII 传回中国。一切跨境处理均遵守越南 PDPD。

### 6. 您的数据主体权利 / Your rights
- 访问、更正、删除、撤回同意、数据可携。
- **账号注销与删除**：App 内 `设置 → 注销账号`（对应 `DELETE /api/v1/account`）会匿名化您的 PII、吊销所有会话并软删除账号；为履行税务/反洗钱留存义务，历史订单与结算记录将保留（仅保留必要的商业与审计字段，识别性字段已清除）。
- 行使权利请联系 / Contact: [dpo@giaonhanh.vn]（上线前补全 DPO 联系方式）。

### 7. 安全 / Security
- 高敏字段（身份证号、银行卡号）**静态加密存储**（Laravel encrypted cast）。
- 银行卡号等 PII 不在 API 响应中序列化（`$hidden`）；支付回调 HMAC 验签 fail-closed。
- 登录失败 5 次锁定 15 分钟；传输全程 TLS。

### 8. 儿童 / Children
不向 18 岁以下提供注册（越南法律要求）。

### 9. 政策变更 / Changes
重大变更将提前在 App 内通知。

---
*本文件为上线前模板，不构成法律意见。建议在发布前由越南当地合规律师 + DPO 复核，并补全 [括号] 中的实体信息、联系方式与生效日期。*
