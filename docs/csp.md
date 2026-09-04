# Content-Security-Policy 部署指南（XSS 严格化 · 哈希 meta CSP）

> 配套第 6 轮「待处理」修复：SPA 内联事件属性（`onclick="..."`）全部消除、改为
> `data-action` 委托；每个静态 HTML 注入**哈希 meta CSP**（`script-src` 不带
> `'unsafe-inline'`）。由 `tools/strictify-csp.mjs` 确定性生成。

## 1. 为什么是哈希 meta CSP，不是 nonce

静态 SPA（Capacitor 本地文件 / 静态主机）**没有服务端**来逐响应生成 nonce。
而 meta CSP **禁止 nonce**，但**允许 `sha256-` 哈希**。本项目的内联 `<script>`
块都是**首方静态代码、内容固定**，所以可以对每个块精确哈希并加入白名单——
这样既去掉 `'unsafe-inline'`，又无需服务器。

> 注：`frame-ancestors` 在 meta CSP 中被忽略（仅 HTTP 头生效），此处保留无害。

## 2. SPA 现在的 CSP（每个 HTML 的 `<head>` 内注入，由脚本生成）

```
default-src 'self';
script-src 'self' 'sha256-…' 'sha256-…' https://unpkg.com;
style-src 'self' 'unsafe-inline' https://unpkg.com;
img-src 'self' data: https:;
font-src 'self' data:;
connect-src 'self' https: wss:;
frame-ancestors 'none';
object-src 'none';
base-uri 'self';
```

- **`script-src`**：`'self'`（外部 `api.js` 等）+ 每个内联 `<script>` 的 `sha256-`
  哈希 + `https://unpkg.com`（地图库 Leaflet）。**不再有 `'unsafe-inline'`** →
  任何内联事件属性、未哈希的内联脚本都**被浏览器拒绝执行**。
- **`connect-src 'self' https: wss:`**：live 模式要调外部 API（`GN_CONFIG.apiBase`）
  与 Pusher 实时通道（`wss:`），故放行 `https:`/`wss:`；离线 demo 只打同源，安全。
- **`style-src 'unsafe-inline'`**：内联 `<style>` 块庞大，保留 `unsafe-inline`
  （CSS 注入危害远低于 JS，且 `GN.esc()` 已中和数据反射 XSS）。属已知残留，见第 5 节。
- **`https://unpkg.com`**：`index.html` / `rider.html` 经 CDN 加载 Leaflet
  脚本与 CSS，地图瓦片走 `openstreetmap`（`img-src https:` 已覆盖）。

## 3. 内联事件属性的消除（危险 sink 已根除）

原 `onclick="closeDrawer()"` / `switchPage('orders')` / 剪贴板调用等全部改为：

```html
<button data-action="closeDrawer">…</button>
<button data-action="switchPage" data-args='["orders"]'>…</button>
<div data-action="copyOrderNo" data-args='["GN123"]'>复制订单号</div>
```

`app/api.js` 中的**单一委托监听器** `document.addEventListener('click', …)` 读取
`data-action`，按 `window[fn]` 分发，并把 `data-args`（JSON）解析后传入。这与已有的
`.onclick =` 属性赋值（CSP 不约束，本来就合规）互不冲突。`copyOrderNo` 由
`api.js` 暴露为全局函数。

> 删除/新增内联 `<script>` 或内联事件属性后，必须**重跑脚本**重新生成哈希，否则
> 浏览器会因哈希不匹配而拒绝执行该脚本（见第 4 节回归护栏）。

## 4. 如何重新生成（开发流程）

```bash
# 预览将改什么（不写文件）
node tools/strictify-csp.mjs --check

# 实际生成：消除内联 handler + 重算哈希 + 注入/更新 meta CSP
node tools/strictify-csp.mjs
```

脚本扫描 `app/`、`mobile/www/`、`mobile/android/.../assets/public/`，对每文件：
1. 把已知的 `onclick="..."` 属性转换为 `data-action`（覆盖 `closeDrawer` /
   `switchPage('orders')` / 剪贴板三种形态）；
2. 对无属性的内联 `<script>` 块算 `sha256`；
3. 注入或更新 `<meta http-equiv="Content-Security-Policy">`；
4. 报告是否还有残留内联 handler（有则退出码 2）。

`backend/public/pay-mock.html` 由 API nginx 头 CSP 管辖（注入 meta 会与头策略取
交集而拦断其内联脚本），故**不参与** meta 注入；其 `onclick` 已手工改为
`addEventListener`。

## 5. CI 回归护栏

`ci.yml` 的 `contract` job 新增一步 `node tools/strictify-csp.mjs --check`：
任何内联 `<script>` / 内联事件属性被重新引入时，该步非零退出 → CI 红，防止
严格化被悄悄回退。

## 6. 已知残留 / 后续加固

- **`style-src 'unsafe-inline'`**：内联样式未哈希。可后续改为构建期注入
  `nonce`（需构建步骤）或将样式外链，进一步收紧。当前 CSS 注入风险低。
- **`https://unpkg.com` 在 `script-src`**：依赖公共 CDN。更安全的做法是**自托管
  Leaflet**（把 `leaflet.js`/`leaflet.css` 放进 `app/` 以 `'self'` 加载），从而
  去掉 CDN 源。已记录为加固项。
- **`connect-src` 放行 `https:`**：为兼容可配置 API 基址的必需妥协；若未来 API
  基址固定，可收紧为具体域名。

> 结论：XSS 的两条主链路——「数据反射进 DOM」（`GN.esc()` 中和）+「内联事件属性
> 执行」（已根除，改 `data-action` 委托）+「未授权脚本执行」（哈希 meta CSP 禁止
> `'unsafe-inline'`）——均已闭环。刷新令牌的 XSS 接管风险已由 HttpOnly Cookie
> 在更早轮次从根本上消除。
