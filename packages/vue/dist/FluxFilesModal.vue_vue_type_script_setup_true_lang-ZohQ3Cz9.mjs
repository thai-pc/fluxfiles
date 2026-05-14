import { ref as F, computed as f, onMounted as O, onUnmounted as C, watch as b, defineComponent as S, openBlock as w, createElementBlock as D, normalizeStyle as g, createElementVNode as _, unref as k, createBlock as N, Teleport as z, normalizeClass as T, createCommentVNode as A } from "vue";
const M = "fluxfiles", I = 1;
function K() {
  return "ff-" + Math.random().toString(36).slice(2, 11) + Date.now().toString(36);
}
function R(c) {
  const p = F(null), s = F(!1), e = f(() => c.value ?? c), d = f(() => (e.value.endpoint || "").replace(/\/+$/, "")), n = f(() => d.value + "/public/index.html"), u = f(() => {
    try {
      return new URL(n.value, window.location.href).origin;
    } catch {
      return window.location.origin;
    }
  });
  function a(o, l = {}) {
    const t = p.value;
    t != null && t.contentWindow && t.contentWindow.postMessage(
      { source: M, type: o, v: I, id: K(), payload: l },
      u.value
    );
  }
  function i() {
    const o = e.value;
    a("FM_CONFIG", {
      disk: o.disk || "local",
      token: o.token || "",
      mode: o.mode || "picker",
      multiple: !!o.multiple,
      allowedTypes: o.allowedTypes || null,
      maxSize: o.maxSize || null,
      endpoint: o.endpoint || "",
      locale: o.locale || null
    });
  }
  function m(o) {
    var v, y, x, E;
    if (u.value && o.origin !== u.value) return;
    const l = o.data;
    if (!l || l.source !== M) return;
    const t = e.value;
    switch (l.type) {
      case "FM_READY":
        s.value = !0, i(), (v = t.onReady) == null || v.call(t);
        break;
      case "FM_SELECT":
        (y = t.onSelect) == null || y.call(t, l.payload);
        break;
      case "FM_EVENT":
        (x = t.onEvent) == null || x.call(t, l.payload);
        break;
      case "FM_TOKEN_REFRESH":
        if (t.onTokenRefresh) {
          const L = l.payload;
          Promise.resolve(t.onTokenRefresh(L)).then((h) => {
            h ? a("FM_TOKEN_UPDATED", { token: h }) : a("FM_TOKEN_FAILED", { reason: "refresh_returned_null" });
          }).catch((h) => {
            a("FM_TOKEN_FAILED", { reason: h.message || "refresh_error" });
          });
        } else
          a("FM_TOKEN_FAILED", { reason: "no_handler" });
        break;
      case "FM_CLOSE":
        (E = t.onClose) == null || E.call(t);
        break;
    }
  }
  O(() => {
    window.addEventListener("message", m);
  }), C(() => {
    window.removeEventListener("message", m);
  }), b(
    () => [e.value.token, e.value.disk, e.value.mode, e.value.multiple, e.value.locale],
    () => {
      s.value && i();
    }
  );
  function r(o, l = {}) {
    a("FM_COMMAND", { action: o, ...l });
  }
  return {
    iframeRef: p,
    iframeSrc: n,
    ready: s,
    command: r,
    navigate: (o) => r("navigate", { path: o }),
    setDisk: (o) => r("setDisk", { disk: o }),
    refresh: () => r("refresh"),
    search: (o) => r("search", { q: o }),
    crossCopy: (o, l) => r("crossCopy", { dst_disk: o, dst_path: l || "" }),
    crossMove: (o, l) => r("crossMove", { dst_disk: o, dst_path: l || "" }),
    crop: (o, l, t, v, y) => r("crop", { x: o, y: l, width: t, height: v, save_path: y || "" }),
    aiTag: () => r("aiTag"),
    setLocale: (o) => r("setLocale", { locale: o }),
    updateToken: (o) => a("FM_TOKEN_UPDATED", { token: o })
  };
}
const B = ["src"], X = /* @__PURE__ */ S({
  __name: "FluxFiles",
  props: {
    endpoint: {},
    token: {},
    disk: { default: "local" },
    mode: { default: "picker" },
    multiple: { type: Boolean, default: !1 },
    allowedTypes: {},
    maxSize: {},
    locale: {},
    width: { default: "100%" },
    height: { default: "600px" }
  },
  emits: ["select", "close", "ready", "event"],
  setup(c, { expose: p, emit: s }) {
    const e = c, d = s, n = R({
      endpoint: e.endpoint,
      token: e.token,
      disk: e.disk,
      mode: e.mode,
      multiple: e.multiple,
      allowedTypes: e.allowedTypes,
      maxSize: e.maxSize,
      locale: e.locale,
      onSelect: (a) => d("select", a),
      onClose: () => d("close"),
      onReady: () => d("ready"),
      onEvent: (a) => d("event", a)
    }), u = f(() => ({
      width: typeof e.width == "number" ? `${e.width}px` : e.width,
      height: typeof e.height == "number" ? `${e.height}px` : e.height
    }));
    return p({
      command: n.command,
      navigate: n.navigate,
      setDisk: n.setDisk,
      refresh: n.refresh,
      search: n.search,
      crossCopy: n.crossCopy,
      crossMove: n.crossMove,
      crop: n.crop,
      aiTag: n.aiTag,
      ready: n.ready
    }), (a, i) => (w(), D("div", {
      style: g(u.value)
    }, [
      _("iframe", {
        ref: (m) => {
          k(n).iframeRef.value = m;
        },
        src: k(n).iframeSrc.value,
        style: { width: "100%", height: "100%", border: "none" },
        allow: "clipboard-write",
        title: "FluxFiles File Manager"
      }, null, 8, B)
    ], 4));
  }
}), U = ["src"], Z = /* @__PURE__ */ S({
  __name: "FluxFilesModal",
  props: {
    open: { type: Boolean },
    endpoint: {},
    token: {},
    disk: { default: "local" },
    mode: { default: "picker" },
    multiple: { type: Boolean, default: !1 },
    allowedTypes: {},
    maxSize: {},
    locale: {},
    overlayClass: {},
    modalClass: {}
  },
  emits: ["select", "close", "ready", "event", "update:open"],
  setup(c, { emit: p }) {
    const s = c, e = p, d = R({
      endpoint: s.endpoint,
      token: s.token,
      disk: s.disk,
      mode: s.mode,
      multiple: s.multiple,
      allowedTypes: s.allowedTypes,
      maxSize: s.maxSize,
      locale: s.locale,
      onSelect: (i) => e("select", i),
      onClose: () => {
        e("close"), e("update:open", !1);
      },
      onReady: () => e("ready"),
      onEvent: (i) => e("event", i)
    });
    function n(i) {
      i.key === "Escape" && (e("close"), e("update:open", !1));
    }
    function u(i) {
      i.target === i.currentTarget && (e("close"), e("update:open", !1));
    }
    let a = "";
    return b(() => s.open, (i) => {
      i ? (a = document.body.style.overflow, document.body.style.overflow = "hidden", document.addEventListener("keydown", n)) : (document.body.style.overflow = a, document.removeEventListener("keydown", n));
    }), C(() => {
      document.body.style.overflow = a, document.removeEventListener("keydown", n);
    }), (i, m) => (w(), N(z, { to: "body" }, [
      c.open ? (w(), D("div", {
        key: 0,
        class: T(c.overlayClass),
        style: g(c.overlayClass ? void 0 : {
          position: "fixed",
          inset: "0",
          background: "rgba(0, 0, 0, 0.5)",
          zIndex: 99999,
          display: "flex",
          alignItems: "center",
          justifyContent: "center"
        }),
        role: "dialog",
        "aria-modal": "true",
        "aria-label": "FluxFiles File Manager",
        onClick: u
      }, [
        _("div", {
          class: T(c.modalClass),
          style: g(c.modalClass ? void 0 : {
            width: "90vw",
            maxWidth: "1200px",
            height: "85vh",
            background: "#fff",
            borderRadius: "8px",
            overflow: "hidden",
            boxShadow: "0 25px 50px rgba(0, 0, 0, 0.25)"
          })
        }, [
          _("iframe", {
            ref: (r) => {
              k(d).iframeRef.value = r;
            },
            src: k(d).iframeSrc.value,
            style: { width: "100%", height: "100%", border: "none" },
            allow: "clipboard-write",
            title: "FluxFiles File Manager"
          }, null, 8, U)
        ], 6)
      ], 6)) : A("", !0)
    ]));
  }
});
export {
  X as _,
  Z as a,
  R as u
};
