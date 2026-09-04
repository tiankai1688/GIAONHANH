/* GIAONHANH — shared "数据模式" (Data Mode) overlay
 * One source of truth for switching between the offline demo and a live
 * Laravel backend, plus Pusher real-time config. Used by all three mobile
 * shells (consumer / merchant / rider) so the configuration stays consistent
 * across the product.
 *
 * Writes the SAME localStorage keys read by each app's GN_CONFIG resolver:
 *   gn_api_base · gn_use_api · gn_pusher_key · gn_pusher_cluster
 */
(function () {
  if (!window.GN) window.GN = {};

  const I18N = {
    vi: {
      title: "Cài đặt dữ liệu",
      desc: "Để trống là bản demo offline. Nhập địa chỉ Laravel (vd: https://api.giaonhanh.vn) và chuyển sang «Trực tuyến» để lấy dữ liệu thật.",
      api: "Địa chỉ backend",
      demo: "Demo",
      live: "Trực tuyến",
      save: "Lưu & áp dụng",
      warn: "Chế độ «Trực tuyến» cần địa chỉ backend.",
      rt: "⚡ Realtime (Pusher)",
      pkey: "Pusher App Key",
      cluster: "Cluster",
      echo: "App Shipper/Merchant cần Pusher Key để reo chuông realtime; không nhập thì lấy theo polling.",
      mode: "Dữ liệu",
    },
    zh: {
      title: "数据模式",
      desc: "留空为离线演示；填写 Laravel 后端地址（如 https://api.giaonhanh.vn）并切换到「在线」即连真实数据。",
      api: "后端地址",
      demo: "演示",
      live: "在线",
      save: "保存并应用",
      warn: "「在线」模式需要先填写后端地址。",
      rt: "⚡ 实时广播（Pusher）",
      pkey: "Pusher App Key",
      cluster: "Cluster",
      echo: "骑手/商家端需填 Pusher Key 才能实时响铃；不填则按轮询拉取。",
      mode: "数据模式",
    },
  };
  const lang = () => ((document.documentElement.getAttribute("lang") || "vi") === "zh" ? "zh" : "vi");
  const T = (k) => (I18N[lang()] && I18N[lang()][k]) || I18N.vi[k];

  const CSS = `
.overlay{position:absolute;inset:0;z-index:60;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;padding:24px;animation:gn-ov-fade .25s var(--ease);}
.overlay.show{display:flex;}
@keyframes gn-ov-fade{from{opacity:0;}to{opacity:1;}}
.overlay-card{width:100%;max-width:340px;background:var(--panel);border:1px solid var(--border);border-radius:20px;padding:20px;box-shadow:var(--shadow);}
.ov-head{display:flex;align-items:center;justify-content:space-between;font-size:16px;font-weight:850;}
.ov-x{width:30px;height:30px;display:grid;place-items:center;border-radius:50%;background:var(--surface);cursor:pointer;font-size:15px;}
.ov-x:active{background:var(--surface-2);}
.ov-desc{font-size:12.5px;color:var(--dim);margin:10px 0 14px;line-height:1.5;}
.ov-input{width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:14px;outline:none;transition:border-color .2s var(--ease);}
.ov-input:focus{border-color:var(--brand);}
.ov-seg{display:flex;gap:10px;margin:14px 0 0;}
.ov-opt{flex:1;text-align:center;padding:11px;border-radius:12px;border:1px solid var(--border);font-size:14px;font-weight:700;color:var(--dim);cursor:pointer;transition:.2s var(--ease);}
.ov-opt:active{transform:scale(.97);}
.ov-opt.on{background:var(--brand);color:#fff;border-color:var(--brand);}
.ov-warn{display:none;color:#ff7a8a;font-size:12px;margin:8px 0 0;}
.ov-warn.show{display:block;}
.ov-sec{margin:16px 0 8px;font-size:12.5px;font-weight:800;color:var(--dim);}
.ov-row{margin-bottom:10px;}
.ov-row label{display:block;font-size:11.5px;color:var(--dim);margin-bottom:5px;}
.btn-block{display:block;width:calc(100% - 32px);margin:6px 16px;padding:14px;border-radius:14px;border:none;background:linear-gradient(135deg,var(--brand),var(--brand-2));color:#fff;font-weight:800;font-size:15px;cursor:pointer;}
.btn-block:active{transform:scale(.98);}
`;

  function injectCSS() {
    if (document.getElementById("gn_ov_style")) return;
    const s = document.createElement("style");
    s.id = "gn_ov_style";
    s.textContent = CSS;
    document.head.appendChild(s);
  }

  function mount() {
    if (window.__GN_OV_MOUNTED__) { wire(); return; }
    injectCSS();
    const dev = document.querySelector(".device");
    if (!dev) return;
    const ov = document.createElement("div");
    ov.className = "overlay";
    ov.id = "gn_ov_overlay";
    ov.innerHTML =
      '<div class="overlay-card">' +
        '<div class="ov-head"><span>' + T("title") + '</span><div class="ov-x" data-gn-ov-close>✕</div></div>' +
        '<p class="ov-desc">' + T("desc") + '</p>' +
        '<input class="ov-input" id="gn_ov_api" placeholder="https://api.giaonhanh.vn" />' +
        '<div class="ov-warn" id="gn_ov_warn">' + T("warn") + '</div>' +
        '<div class="ov-seg">' +
          '<div class="ov-opt" data-mode="0">' + T("demo") + '</div>' +
          '<div class="ov-opt" data-mode="1">' + T("live") + '</div>' +
        '</div>' +
        '<div class="ov-sec">' + T("rt") + '</div>' +
        '<div class="ov-row"><label>' + T("pkey") + '</label><input class="ov-input" id="gn_ov_pkey" placeholder="xxxxxxxxxxxxxxxxxxxx" /></div>' +
        '<div class="ov-row"><label>' + T("cluster") + '</label><input class="ov-input" id="gn_ov_pcluster" placeholder="ap1" /></div>' +
        '<div class="ov-warn" id="gn_ov_echo" style="display:block">' + T("echo") + '</div>' +
        '<button class="btn-block" data-gn-ov-save>' + T("save") + '</button>' +
      '</div>';
    dev.appendChild(ov);
    window.__GN_OV_MOUNTED__ = true;
    wire();
  }

  function wire() {
    const ov = document.getElementById("gn_ov_overlay");
    if (!ov || ov.__wired__) return;
    ov.__wired__ = true;
    ov.addEventListener("click", (e) => { if (e.target === ov) close(); });
    ov.querySelectorAll("[data-gn-ov-close]").forEach((b) => (b.onclick = close));
    ov.querySelectorAll(".ov-opt").forEach((o) =>
      (o.onclick = () => {
        const live = +o.dataset.mode === 1;
        ov.querySelectorAll(".ov-opt").forEach((x) => x.classList.toggle("on", x === o));
        const warn = document.getElementById("gn_ov_warn");
        if (live && !document.getElementById("gn_ov_api").value.trim()) warn.classList.add("show");
        else warn.classList.remove("show");
      })
    );
    ov.querySelector("[data-gn-ov-save]").onclick = () => {
      const base = document.getElementById("gn_ov_api").value.trim().replace(/\/+$/, "");
      const onOpt = ov.querySelector(".ov-opt.on");
      const live = !!(onOpt && +onOpt.dataset.mode === 1);
      if (live && !base) { document.getElementById("gn_ov_warn").classList.add("show"); return; }
      const pkey = document.getElementById("gn_ov_pkey").value.trim();
      const pcluster = document.getElementById("gn_ov_pcluster").value.trim() || "ap1";
      localStorage.setItem("gn_api_base", base);
      localStorage.setItem("gn_use_api", live ? "1" : "0");
      localStorage.setItem("gn_pusher_key", pkey);
      localStorage.setItem("gn_pusher_cluster", pcluster);
      close();
      location.reload();
    };
  }

  function open() {
    mount();
    const ov = document.getElementById("gn_ov_overlay");
    if (!ov) return;
    const cfg = window.GN_CONFIG || {};
    document.getElementById("gn_ov_api").value = cfg.apiBase || "";
    document.getElementById("gn_ov_pkey").value = cfg.pusherKey || "";
    document.getElementById("gn_ov_pcluster").value = cfg.pusherCluster || "ap1";
    const useLive = !!cfg.useApi;
    ov.querySelectorAll(".ov-opt").forEach((o) => o.classList.toggle("on", +o.dataset.mode === 1) === useLive);
    document.getElementById("gn_ov_warn").classList.remove("show");
    ov.classList.add("show");
  }

  function close() {
    const ov = document.getElementById("gn_ov_overlay");
    if (ov) ov.classList.remove("show");
  }

  GN.mountDataModeOverlay = mount;
  GN.openDataModeOverlay = open;
  GN.closeDataModeOverlay = close;

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", mount);
  else mount();
})();
