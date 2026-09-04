# GIAONHANH — Thanh toán & Tuân thủ (Payment & Compliance)

> Vietnamese same-day delivery (美团式). This doc explains how the payment
> integration in `app/Services/PaymentGatewayService.php` + `PaymentController`
> stays compliant with Vietnamese law, and how to go live with real credentials.

---

## 1. The one rule that matters: no 二清 (illegal fund pooling)

Under Vietnamese law (**Nghị định 101/2012/NĐ-CP** on non-bank payment
intermediaries, supervised by the **State Bank of Vietnam — SBV**), only a
licensed entity may hold customer funds and forward them to third parties.

**GIAONHANH must NEVER:**

- collect a customer's payment into a GIAONHANH pool, then forward merchant
  share + rider share later, as if GIAONHANH were the payment intermediary.
- settle money to merchants from a GIAONHANH-held balance that mixes many
  customers' funds.

**GIAONHANH is a merchant / operator, not a payment intermediary.** Customer
money must be handled by a **licensed PSP** (MoMo, ZaloPay) or a **licensed
aggregator** (Sepay, Payoo). GIAONHANH only receives its own legitimate
proceeds and pays out via the PSP's official disbursement / payout API.

---

## 2. Two compliant models

### Model A — Direct MoMo / ZaloPay (recommended to start)
MoMo and ZaloPay **are licensed PSPs**. GIAONHANH registers as their
**main merchant (chủ tài khoản nhận tiền)**.

Flow:
```
Customer ──pays──> MoMo/ZaloPay (licensed) ──settles to──> GIAONHANH merchant account
                                                        │
                          GIAONHANH disburses (payout API) ──> Merchant (0% fee)
                                                        │
                          GIAONHANH pays rider subsidy ──> Rider (platform's own cost)
```

- `commission_rate = 0` → merchant receives **100% of product amount**.
- `platform_subsidy` (delivery subsidy) is paid by **GIAONHANH from its own
  account**, never carved out of the customer payment.
- This is compliant: GIAONHANH receives its own sales proceeds as a licensed
  merchant and pays merchants/rider via the PSP's disbursement API — no pooling
  of *other people's* money.

### Model B — Licensed aggregator with split (scale / many sub-merchants)
Set `PAYMENT_AGGREGATOR=sepay` (or `payoo`). The aggregator is the licensed
intermediary; the order carries **split instructions** so funds go straight to
the merchant at source.

- `PaymentGatewayService::createViaAggregator()` builds the order + `split`
  payload signed with `AGGREGATOR_API_KEY`.
- Merchant is a **sub-merchant** (KYC required per merchant at the aggregator).
- GIAONHANH never touches customer funds; it only verifies the callback mac and
  settles its own subsidy line.

Use Model B when you have many merchants and want source-side split settlement
without building your own disbursement orchestration.

---

## 3. 0-commission + delivery-subsidy accounting

The `orders` table encodes the compliant math:

| Column | Meaning |
|---|---|
| `product_amount` | what the merchant should receive (0% commission) |
| `delivery_fee` | shown to customer; **covered by platform subsidy** |
| `coupon_discount` | new-user coupon, **funded by platform** |
| `platform_subsidy` | `delivery_fee + coupon_discount` — platform's cost |
| `commission` | always `0` |
| `amount` | `product_amount + delivery_fee - coupon_discount` (customer pays) |
| `merchant_settlement` | = `product_amount` (full, no cut) |

So the platform's P&L hit is `platform_subsidy` only — exactly the "0 commission
+ 0 delivery fee (platform-subsidized)" promise from the招商 copy.

---

## 4. Go-live checklist (real credentials)

1. **Merchant KYC** at MoMo/ZaloPay (or aggregator): business license, bank
   account, representative ID. Get `partnerCode / accessKey / secretKey`
   (MoMo) or `appId / key1 / key2` (ZaloPay).
2. Fill `backend/.env`:
   ```
   MOMO_PARTNER_CODE=...
   MOMO_ACCESS_KEY=...
   MOMO_SECRET_KEY=...
   MOMO_IPN_URL=https://api.giaonhanh.vn/api/payments/momo/ipn
   MOMO_REDIRECT_URL=https://app.giaonhanh.vn/pay/result

   ZALOPAY_APP_ID=...
   ZALOPAY_KEY1=...
   ZALOPAY_KEY2=...
   ZALOPAY_REDIRECT_URL=https://app.giaonhanh.vn/pay/result

   PAYMENT_SANDBOX=false
   PAYMENT_AGGREGATOR=none        # or sepay / payoo
   ```
3. Point `APP_URL` at your domain so IPN/redirect URLs are reachable.
4. **Verify signatures before go-live** with the standalone script:
   ```
   php backend/scripts/verify-ipn.php      # proves HMAC pipeline
   ```
   (the same algorithm is mirrored in `tools/verify-ipn.mjs` for sandbox runs).
5. Whitelist the PSP/aggregator IP ranges on your IPN endpoint (defense in depth).
6. Reconcile daily: PSP settlement report vs `payments` table (`status=success`).

---

## 5. KYC / AML / Data protection

- **Merchant & rider KYC**: collect business license / ID at onboarding
  (`merchants.status` starts `pending`, admin approves after review — see
  `AdminController`). Do not let unverified merchants receive payouts.
- **AML**: monitor for structuring; keep transaction logs ≥ 5 years.
- **Personal data (Nghị định 13/2023 — PDPD)**: minimize data, get consent for
  location/phone, allow data deletion. `users.phone` is PII — encrypt at rest in
  production.

---

## 6. App store notes (Google Play / App Store)

- GIAONHANH sells **physical goods + local delivery** → external payments
  (MoMo/ZaloPay) are allowed; you do **not** need Apple IAP / Google Play Billing
  for digital goods.
- Keep payment flows inside the licensed PSP's SDK/webview; do not build a
  custom card vault (that would require PCI-DSS + a payment license).
- Vietnam: confirm Google Play's external-offer policy for the region before
  launch; Apple permits external payment links for physical goods with the
  appropriate Entitlement.
- Capacitor wrapper (see `mobile/`) keeps the same web payment flow on both
  Android and iOS.

---

## 7. Sandbox behavior (current default)

With `PAYMENT_SANDBOX=true` and no real keys, `PaymentGatewayService` still runs
the **full real HMAC signing + verification pipeline** but points the user at
`public/pay-mock.html`. The signature embedded in that mock URL is generated
**server-side and verified server-side** when you click "I have paid" — only the
external PSP HTTP call is stubbed. So the integration is genuinely exercised
end-to-end before any real key is added.
