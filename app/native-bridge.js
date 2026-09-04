/* GIAONHANH native bridge
 * Activates ONLY inside a Capacitor native shell (window.Capacitor.isNative).
 * In a plain browser it degrades gracefully (geolocation via navigator,
 * clicks do nothing extra). Safe to load alongside the prototype.
 */

/* ===================== GN.voice =====================
 * Voice reminders (TTS + beep fallback) for order events.
 *   - SpeechSynthesis for Vietnamese / Chinese narration.
 *   - Web Audio short beep as a guaranteed audible cue even when no
 *     TTS voice pack is installed (common on bare WebViews).
 *   - Mute flag persisted to localStorage so riders/customers keep it.
 * Autoplay policy: AudioContext + speechSynthesis start suspended, so we
 * warm them up on the first user gesture (tap). */
(function () {
  const GN = (window.GN = window.GN || {});
  const KEY = 'gn_voice_muted';
  let muted = (localStorage.getItem(KEY) === '1');
  let ctx = null;

  function ensureCtx() {
    try {
      if (!ctx) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (AC) ctx = new AC();
      }
      if (ctx && ctx.state === 'suspended') ctx.resume().catch(() => {});
    } catch (e) {}
  }
  function beep(freq, dur) {
    try {
      ensureCtx();
      if (!ctx) return;
      const o = ctx.createOscillator(), g = ctx.createGain();
      o.connect(g); g.connect(ctx.destination);
      o.type = 'sine'; o.frequency.value = freq;
      const t = ctx.currentTime;
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(0.18, t + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, t + (dur / 1000) + 0.02);
      o.start(t); o.stop(t + (dur / 1000) + 0.04);
    } catch (e) {}
  }

  const phrases = {
    order_placed:   { vi: 'Đặt hàng thành công, đang tìm shipper',  zh: '下单成功，正在为您调度骑手' },
    new_order:       { vi: 'Có đơn hàng mới, vui lòng nhận đơn',   zh: '新订单来了，请尽快抢单' },
    order_accepted:  { vi: 'Đã nhận đơn, hãy đến lấy hàng',        zh: '已接单，请前往商家取货' },
    merchant_accepted:{ vi: 'Đã nhận đơn, đang chuẩn bị hàng',     zh: '已接单，正在备货' },
    order_ready:     { vi: 'Đã đóng gói xong, shipper sắp đến',    zh: '备货完成，骑手马上来取' },
    order_completed: { vi: 'Đơn đã giao thành công',               zh: '订单已送达，交易完成' },
    paid:            { vi: 'Đơn hàng đã được thanh toán',           zh: '订单已支付成功' },
  };

  function langOf(override) {
    if (override) return override;
    const attr = document.documentElement.getAttribute('lang');
    if (attr === 'zh' || attr === 'vi') return attr;
    const l = (navigator.language || 'vi').slice(0, 2);
    return (l === 'zh') ? 'zh' : 'vi';
  }

  function say(key, override) {
    if (muted) return;
    const lng = langOf(override);
    const text =
      (phrases[key] && phrases[key][lng]) ||
      (phrases[key] && phrases[key].vi) || key;
    // guaranteed audible cue (beep) regardless of TTS availability
    if (key === 'new_order') { beep(1040, 200); setTimeout(() => beep(1240, 180), 160); }
    else if (key === 'order_accepted') { beep(880, 160); setTimeout(() => beep(1175, 200), 150); }
    else { beep(660, 200); }
    // TTS narration
    try {
      const ss = window.speechSynthesis;
      if (ss) {
        ss.cancel(); // avoid backlog when many events fire
        const u = new SpeechSynthesisUtterance(text);
        u.lang = (lng === 'zh') ? 'zh-CN' : 'vi-VN';
        u.rate = 1.02; u.pitch = 1.0; u.volume = 1.0;
        ss.speak(u);
      }
    } catch (e) {}
  }

  function setMuted(v) { muted = !!v; localStorage.setItem(KEY, muted ? '1' : '0'); }
  function toggle() { setMuted(!muted); return muted; }
  function isMuted() { return muted; }

  GN.voice = { say, setMuted, toggle, isMuted, beep };

  // Warm up audio + speech on first user gesture (autoplay policy).
  function warm() {
    ensureCtx();
    try { const ss = window.speechSynthesis; if (ss) ss.cancel(); } catch (e) {}
    document.removeEventListener('click', warm);
    document.removeEventListener('touchend', warm);
  }
  document.addEventListener('click', warm);
  document.addEventListener('touchend', warm);
})();

/* ===================== GN.toast =====================
 * Lightweight toast for the three prototypes (consumer / rider / merchant).
 * Self-contained: injects its style + a root container once, then renders
 * glassmorphic toasts with success/info/error tones. Hosted inside .device
 * when present (so it tracks the phone mockup), else fixed to the viewport.
 * Safe everywhere — wrapped in try/catch, no external deps. */
(function () {
  const GN = (window.GN = window.GN || {});
  let injected = false;

  function ensureStyles() {
    if (injected) return;
    const s = document.createElement('style');
    s.textContent = `
.gn-toast-root{position:absolute;left:50%;bottom:92px;transform:translateX(-50%);z-index:9999;
  display:flex;flex-direction:column;gap:10px;align-items:center;pointer-events:none;}
.gn-toast-root.fixed{position:fixed;bottom:96px;}
.gn-toast{max-width:300px;padding:12px 18px;border-radius:14px;font:700 14px/1.4 var(--font,system-ui,sans-serif);
  color:#fff;background:rgba(20,22,30,.92);border:1px solid rgba(255,255,255,.14);
  box-shadow:0 16px 40px -12px rgba(0,0,0,.6);-webkit-backdrop-filter:blur(14px) saturate(160%);backdrop-filter:blur(14px) saturate(160%);
  opacity:0;transform:translateY(16px) scale(.96);transition:opacity .28s cubic-bezier(.16,1,.3,1),transform .28s cubic-bezier(.16,1,.3,1);
  display:flex;align-items:center;gap:9px;white-space:nowrap;cursor:pointer;}
.gn-toast.show{opacity:1;transform:none;}
.gn-toast .ic{font-size:17px;line-height:1;}
.gn-toast.success{background:linear-gradient(135deg,rgba(43,212,167,.96),rgba(27,163,124,.96));border-color:rgba(255,255,255,.24);}
.gn-toast.error{background:linear-gradient(135deg,rgba(255,86,86,.96),rgba(200,40,40,.96));border-color:rgba(255,255,255,.24);}
.gn-toast.info{background:linear-gradient(135deg,rgba(80,140,255,.96),rgba(40,90,220,.96));border-color:rgba(255,255,255,.24);}`;
    document.head.appendChild(s);
    injected = true;
  }

  function toast(msg, type, ms) {
    try {
      ensureStyles();
      const host = document.querySelector('.device');
      let root = document.getElementById('gn-toast-root');
      if (!root) {
        root = document.createElement('div');
        root.id = 'gn-toast-root';
        root.className = 'gn-toast-root' + (host ? '' : ' fixed');
        (host || document.body).appendChild(root);
      }
      const el = document.createElement('div');
      el.className = 'gn-toast ' + (type || 'success');
      const icon = type === 'error' ? '⚠️' : type === 'info' ? 'ℹ️' : '✅';
      el.innerHTML =
        '<span class="ic">' + icon + '</span><span>' +
        String(msg).replace(/</g, '&lt;') + '</span>';
      root.appendChild(el);
      void el.offsetWidth; // force reflow for entry transition
      el.classList.add('show');
      const close = () => { el.classList.remove('show'); setTimeout(() => el.remove(), 320); };
      setTimeout(close, ms || 2200);
      el.addEventListener('click', close);
    } catch (e) {}
  }

  GN.toast = toast;
})();

(function () {
  const GN = (window.GN = window.GN || {});
  const native = {};

  const isNative = !!(window.Capacitor && window.Capacitor.isNative);
  native.isNative = isNative;

  // Capacitor registers plugins on window.Capacitor.Plugins at runtime.
  const P = (name) =>
    window.Capacitor && window.Capacitor.Plugins
      ? window.Capacitor.Plugins[name]
      : null;

  native.ready = async function () {
    if (!isNative) return;
    try {
      const Splash = P('SplashScreen');
      if (Splash) await Splash.hide();
    } catch (e) {}
    try {
      const SB = P('StatusBar');
      if (SB) await SB.setStyle({ style: 'DARK' });
    } catch (e) {}
    // PUSH CONSENT GATE (compliance): do NOT auto-register for push. Only resume
    // push registration for returning users who previously opted in. First-time
    // users must explicitly opt in via native.requestPushPermission().
    if (GN.consent.get('push')) {
      native.registerPush();
    }
  };

  native.getLocation = function () {
    if (isNative) {
      const Geo = P('Geolocation');
      if (Geo) {
        return Geo.getCurrentPosition({
          enableHighAccuracy: true,
          timeout: 8000,
        }).then((p) => ({ lat: p.coords.latitude, lng: p.coords.longitude }))
          .catch(() => null);
      }
      return Promise.resolve(null);
    }
    // Web fallback
    return new Promise((res) => {
      if (!navigator.geolocation) return res(null);
      navigator.geolocation.getCurrentPosition(
        (pos) => res({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
        () => res(null),
        { timeout: 8000 }
      );
    });
  };

  native.registerPush = async function () {
    if (!isNative) return;
    const Push = P('PushNotifications');
    if (!Push) return;
    try {
      await Push.register();
      Push.addListener('registration', (t) => {
        window.GN_pushToken = t.value;
        console.log('[GN] push token', t.value);
        native.flushPushToken();
      });
      Push.addListener('pushNotificationReceived', (n) => {
        console.log('[GN] push', n);
      });
    } catch (e) {
      console.warn('[GN] push register failed', e);
    }
  };

  /* Upload the FCM/APNs token to the backend so it can target push
     notifications at this device. Best-effort: requires a logged-in token. */
  native.flushPushToken = async function () {
    if (!window.GN || !GN.token || !window.GN_pushToken) return;
    try {
      if (GN.API && GN.API.deviceToken) {
        const platform = isNative
          ? (window.Capacitor && window.Capacitor.platform) || 'android'
          : 'web';
        await GN.API.deviceToken(window.GN_pushToken, platform);
      }
    } catch (e) {
      console.warn('[GN] push token upload failed', e);
    }
  };

  native.haptic = function (style) {
    if (!isNative) return;
    const H = P('Haptics');
    if (!H) return;
    const v = (H.ImpactStyle && H.ImpactStyle[style || 'Medium']) || 'Medium';
    H.impact({ style: v }).catch(() => {});
  };

  native.openURL = async function (url) {
    const B = P('Browser');
    if (isNative && B) {
      try {
        await B.open({ url });
        return;
      } catch (e) {}
    }
    window.open(url, '_blank');
  };

  /* ===================== Consent management (compliance) =====================
   * PDPD / GDPR require EXPLICIT, revocable consent BEFORE collecting precise
   * geolocation or push tokens. We therefore NEVER auto-collect on page load.
   * The app must call the request*Permission() methods from a user gesture
   * (a real in-app consent screen in production — purpose + withdrawal). */
  const CONSENT_KEYS = { location: 'gn_consent_location', push: 'gn_consent_push' };
  GN.consent = {
    get(type) { return localStorage.getItem(CONSENT_KEYS[type]) === '1'; },
    grant(type) { localStorage.setItem(CONSENT_KEYS[type], '1'); },
    revoke(type) { localStorage.removeItem(CONSENT_KEYS[type]); },
  };

  // Resolve the user's real location, but ONLY after location consent is granted.
  native.startLocation = function () {
    if (!GN.consent.get('location')) return Promise.resolve(null);
    return native.getLocation().then((loc) => {
      if (loc && typeof window.__setRealLocation === 'function') {
        window.__setRealLocation(loc);
      }
      return loc;
    });
  };

  // Called from a user gesture (e.g. an in-app "允许定位 / Allow location"
  // button). Records consent, then starts tracking. In production precede this
  // with a proper consent dialog explaining purpose and withdrawal.
  native.requestLocationPermission = function () {
    GN.consent.grant('location');
    return native.startLocation();
  };

  // Called from a user gesture for push opt-in. Records consent, then registers.
  native.requestPushPermission = function () {
    GN.consent.grant('push');
    return native.registerPush();
  };

  GN.native = native;

  /* ===================== GN.initEcho =====================
   * Shared real-time helper (Rider + Merchant apps).
   * Dynamically loads Pusher 7.6 + laravel-echo@11 (CDN, no build step)
   * and returns a Promise resolving to the Echo instance (or null if the
   * key isn't configured or the CDN fails — callers fall back to polling).
   *   - Public channels:  just pass { key, cluster }.
   *   - Private channels: pass authEndpoint (full URL incl. /api/broadcasting/auth)
   *                       + auth.headers with the Sanctum Bearer token.
   * Loaded scripts are cached on window.GN.echo so repeated calls are cheap. */
  GN.initEcho = function (config) {
    return new Promise((resolve) => {
      if (window.GN.echo) { resolve(window.GN.echo); return; }
      if (!config || !config.key) { resolve(null); return; }
      const load = (src) => new Promise((res, rej) => {
        const s = document.createElement('script');
        s.src = src; s.async = true;
        s.onload = res;
        s.onerror = () => rej(new Error('load failed: ' + src));
        document.head.appendChild(s);
      });
      Promise.all([
        load('https://js.pusher.com/7.6/pusher.min.js'),
        load('https://cdn.jsdelivr.net/npm/laravel-echo@11/dist/echo.iife.js'),
      ]).then(() => {
        const echo = new Echo({
          broadcaster: 'pusher',
          key: config.key,
          cluster: config.cluster || 'ap1',
          forceTLS: true,
          authEndpoint: config.authEndpoint || '/api/broadcasting/auth',
          auth: config.auth || { headers: {} },
        });
        window.GN.echo = echo;
        resolve(echo);
      }).catch((err) => {
        console.warn('[GN] Echo 不可用，回退轮询', err);
        resolve(null);
      });
    });
  };

  // Auto: haptic feedback on interactive taps (native only).
  document.addEventListener('click', (e) => {
    const t = e.target.closest('.tab, .btn, .prod-add, .cart-ico');
    if (t) native.haptic('Light');
  });

  // COMPLIANCE: do NOT auto-collect location on load. Only resume location
  // tracking for returning users who previously granted consent; first-time
  // users must tap an explicit opt-in (native.requestLocationPermission()).
  document.addEventListener('DOMContentLoaded', () => {
    native.ready();
    if (GN.consent.get('location')) {
      native.startLocation();
    }
  });
})();
