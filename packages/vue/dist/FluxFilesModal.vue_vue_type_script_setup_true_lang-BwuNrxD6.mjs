import { ref as F, computed as y, onMounted as O, onUnmounted as C, watch as b, defineComponent as S, openBlock as k, createElementBlock as D, normalizeStyle as w, createElementVNode as g, unref as h, createBlock as N, Teleport as z, normalizeClass as T, createCommentVNode as A } from "vue";
const M = "fluxfiles", I = 1;
function K() {
  return "ff-" + Math.random().toString(36).slice(2, 11) + Date.now().toString(36);
}
function R(r) {
  const p = F(null), l = F(!1), e = y(() => r.value ?? r), u = y(() => (e.value.endpoint || "").replace(/\/+$/, "")), n = y(() => u.value + "/public/index.html");
  function c(o, a = {}) {
    const t = p.value;
    t != null && t.contentWindow && t.contentWindow.postMessage(
      { source: M, type: o, v: I, id: K(), payload: a },
      "*"
    );
  }
  function d() {
    const o = e.value;
    c("FM_CONFIG", {
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
  function s(o) {
    var m, f, x, E;
    const a = o.data;
    if (!a || a.source !== M) return;
    const t = e.value;
    switch (a.type) {
      case "FM_READY":
        l.value = !0, d(), (m = t.onReady) == null || m.call(t);
        break;
      case "FM_SELECT":
        (f = t.onSelect) == null || f.call(t, a.payload);
        break;
      case "FM_EVENT":
        (x = t.onEvent) == null || x.call(t, a.payload);
        break;
      case "FM_TOKEN_REFRESH":
        if (t.onTokenRefresh) {
          const L = a.payload;
          Promise.resolve(t.onTokenRefresh(L)).then((v) => {
            v ? c("FM_TOKEN_UPDATED", { token: v }) : c("FM_TOKEN_FAILED", { reason: "refresh_returned_null" });
          }).catch((v) => {
            c("FM_TOKEN_FAILED", { reason: v.message || "refresh_error" });
          });
        } else
          c("FM_TOKEN_FAILED", { reason: "no_handler" });
        break;
      case "FM_CLOSE":
        (E = t.onClose) == null || E.call(t);
        break;
    }
  }
  O(() => {
    window.addEventListener("message", s);
  }), C(() => {
    window.removeEventListener("message", s);
  }), b(
    () => [e.value.token, e.value.disk, e.value.mode, e.value.multiple, e.value.locale],
    () => {
      l.value && d();
    }
  );
  function i(o, a = {}) {
    c("FM_COMMAND", { action: o, ...a });
  }
  return {
    iframeRef: p,
    iframeSrc: n,
    ready: l,
    command: i,
    navigate: (o) => i("navigate", { path: o }),
    setDisk: (o) => i("setDisk", { disk: o }),
    refresh: () => i("refresh"),
    search: (o) => i("search", { q: o }),
    crossCopy: (o, a) => i("crossCopy", { dst_disk: o, dst_path: a || "" }),
    crossMove: (o, a) => i("crossMove", { dst_disk: o, dst_path: a || "" }),
    crop: (o, a, t, m, f) => i("crop", { x: o, y: a, width: t, height: m, save_path: f || "" }),
    aiTag: () => i("aiTag"),
    setLocale: (o) => i("setLocale", { locale: o }),
    updateToken: (o) => c("FM_TOKEN_UPDATED", { token: o })
  };
}
const B = ["src"], Q = /* @__PURE__ */ S({
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
  setup(r, { expose: p, emit: l }) {
    const e = r, u = l, n = R({
      endpoint: e.endpoint,
      token: e.token,
      disk: e.disk,
      mode: e.mode,
      multiple: e.multiple,
      allowedTypes: e.allowedTypes,
      maxSize: e.maxSize,
      locale: e.locale,
      onSelect: (d) => u("select", d),
      onClose: () => u("close"),
      onReady: () => u("ready"),
      onEvent: (d) => u("event", d)
    }), c = y(() => ({
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
    }), (d, s) => (k(), D("div", {
      style: w(c.value)
    }, [
      g("iframe", {
        ref: (i) => {
          h(n).iframeRef.value = i;
        },
        src: h(n).iframeSrc.value,
        style: { width: "100%", height: "100%", border: "none" },
        allow: "clipboard-write",
        title: "FluxFiles File Manager"
      }, null, 8, B)
    ], 4));
  }
}), $ = ["src"], X = /* @__PURE__ */ S({
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
  setup(r, { emit: p }) {
    const l = r, e = p, u = R({
      endpoint: l.endpoint,
      token: l.token,
      disk: l.disk,
      mode: l.mode,
      multiple: l.multiple,
      allowedTypes: l.allowedTypes,
      maxSize: l.maxSize,
      locale: l.locale,
      onSelect: (s) => e("select", s),
      onClose: () => {
        e("close"), e("update:open", !1);
      },
      onReady: () => e("ready"),
      onEvent: (s) => e("event", s)
    });
    function n(s) {
      s.key === "Escape" && (e("close"), e("update:open", !1));
    }
    function c(s) {
      s.target === s.currentTarget && (e("close"), e("update:open", !1));
    }
    let d = "";
    return b(() => l.open, (s) => {
      s ? (d = document.body.style.overflow, document.body.style.overflow = "hidden", document.addEventListener("keydown", n)) : (document.body.style.overflow = d, document.removeEventListener("keydown", n));
    }), C(() => {
      document.body.style.overflow = d, document.removeEventListener("keydown", n);
    }), (s, i) => (k(), N(z, { to: "body" }, [
      r.open ? (k(), D("div", {
        key: 0,
        class: T(r.overlayClass),
        style: w(r.overlayClass ? void 0 : {
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
        onClick: c
      }, [
        g("div", {
          class: T(r.modalClass),
          style: w(r.modalClass ? void 0 : {
            width: "90vw",
            maxWidth: "1200px",
            height: "85vh",
            background: "#fff",
            borderRadius: "8px",
            overflow: "hidden",
            boxShadow: "0 25px 50px rgba(0, 0, 0, 0.25)"
          })
        }, [
          g("iframe", {
            ref: (_) => {
              h(u).iframeRef.value = _;
            },
            src: h(u).iframeSrc.value,
            style: { width: "100%", height: "100%", border: "none" },
            allow: "clipboard-write",
            title: "FluxFiles File Manager"
          }, null, 8, $)
        ], 6)
      ], 6)) : A("", !0)
    ]));
  }
});
export {
  Q as _,
  X as a,
  R as u
};
