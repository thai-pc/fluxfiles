"use strict";
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// src/index.ts
var index_exports = {};
__export(index_exports, {
  FluxFiles: () => FluxFiles,
  FluxFilesModal: () => FluxFilesModal,
  useFluxFiles: () => useFluxFiles
});
module.exports = __toCommonJS(index_exports);

// src/FluxFiles.tsx
var import_react2 = require("react");

// src/useFluxFiles.ts
var import_react = require("react");
var SOURCE = "fluxfiles";
var VERSION = 1;
function uid() {
  return "ff-" + Math.random().toString(36).slice(2, 11) + Date.now().toString(36);
}
function useFluxFiles(options) {
  const iframeElRef = (0, import_react.useRef)(null);
  const [ready, setReady] = (0, import_react.useState)(false);
  const optionsRef = (0, import_react.useRef)(options);
  optionsRef.current = options;
  const endpoint = (options.endpoint || "").replace(/\/+$/, "");
  const iframeSrc = endpoint + "/public/index.html";
  const post = (0, import_react.useCallback)((type, payload = {}) => {
    const el = iframeElRef.current;
    if (!el?.contentWindow) return;
    el.contentWindow.postMessage(
      { source: SOURCE, type, v: VERSION, id: uid(), payload },
      "*"
    );
  }, []);
  const sendConfig = (0, import_react.useCallback)(() => {
    const opts = optionsRef.current;
    post("FM_CONFIG", {
      disk: opts.disk || "local",
      token: opts.token || "",
      mode: opts.mode || "picker",
      multiple: !!opts.multiple,
      allowedTypes: opts.allowedTypes || null,
      maxSize: opts.maxSize || null,
      endpoint: opts.endpoint || "",
      locale: opts.locale || null
    });
  }, [post]);
  (0, import_react.useEffect)(() => {
    function onMessage(e) {
      const msg = e.data;
      if (!msg || msg.source !== SOURCE) return;
      const opts = optionsRef.current;
      switch (msg.type) {
        case "FM_READY":
          setReady(true);
          sendConfig();
          opts.onReady?.();
          break;
        case "FM_SELECT":
          opts.onSelect?.(msg.payload);
          break;
        case "FM_EVENT":
          opts.onEvent?.(msg.payload);
          break;
        case "FM_TOKEN_REFRESH":
          if (opts.onTokenRefresh) {
            const payload = msg.payload;
            Promise.resolve(opts.onTokenRefresh(payload)).then((newToken) => {
              if (newToken) {
                post("FM_TOKEN_UPDATED", { token: newToken });
              } else {
                post("FM_TOKEN_FAILED", { reason: "refresh_returned_null" });
              }
            }).catch((err) => {
              post("FM_TOKEN_FAILED", { reason: err.message || "refresh_error" });
            });
          } else {
            post("FM_TOKEN_FAILED", { reason: "no_handler" });
          }
          break;
        case "FM_CLOSE":
          opts.onClose?.();
          break;
      }
    }
    window.addEventListener("message", onMessage);
    return () => {
      window.removeEventListener("message", onMessage);
    };
  }, [sendConfig]);
  (0, import_react.useEffect)(() => {
    if (ready) {
      sendConfig();
    }
  }, [options.token, options.disk, options.mode, options.multiple, options.locale, ready, sendConfig]);
  const command = (0, import_react.useCallback)(
    (action, data = {}) => {
      post("FM_COMMAND", { action, ...data });
    },
    [post]
  );
  const navigate = (0, import_react.useCallback)((path) => command("navigate", { path }), [command]);
  const setDisk = (0, import_react.useCallback)((disk) => command("setDisk", { disk }), [command]);
  const refresh = (0, import_react.useCallback)(() => command("refresh"), [command]);
  const search = (0, import_react.useCallback)((q) => command("search", { q }), [command]);
  const crossCopy = (0, import_react.useCallback)((dstDisk, dstPath) => command("crossCopy", { dst_disk: dstDisk, dst_path: dstPath || "" }), [command]);
  const crossMove = (0, import_react.useCallback)((dstDisk, dstPath) => command("crossMove", { dst_disk: dstDisk, dst_path: dstPath || "" }), [command]);
  const crop = (0, import_react.useCallback)((x, y, width, height, savePath) => command("crop", { x, y, width, height, save_path: savePath || "" }), [command]);
  const aiTag = (0, import_react.useCallback)(() => command("aiTag"), [command]);
  const setLocale = (0, import_react.useCallback)((locale) => command("setLocale", { locale }), [command]);
  const updateToken = (0, import_react.useCallback)((token) => post("FM_TOKEN_UPDATED", { token }), [post]);
  const iframeRef = (0, import_react.useCallback)((el) => {
    iframeElRef.current = el;
    if (!el) {
      setReady(false);
    }
  }, []);
  return {
    iframeRef,
    iframeSrc,
    ready,
    command,
    navigate,
    setDisk,
    refresh,
    search,
    crossCopy,
    crossMove,
    crop,
    aiTag,
    setLocale,
    updateToken
  };
}

// src/FluxFiles.tsx
var import_jsx_runtime = require("react/jsx-runtime");
var FluxFiles = (0, import_react2.forwardRef)(
  function FluxFiles2(props, ref) {
    const {
      endpoint,
      token,
      disk,
      mode,
      multiple,
      allowedTypes,
      maxSize,
      width = "100%",
      height = "600px",
      className,
      style,
      onSelect,
      onClose,
      onReady,
      onEvent,
      onTokenRefresh
    } = props;
    const handle = useFluxFiles({
      endpoint,
      token,
      disk,
      mode,
      multiple,
      allowedTypes,
      maxSize,
      onSelect,
      onClose,
      onReady,
      onEvent,
      onTokenRefresh
    });
    (0, import_react2.useImperativeHandle)(ref, () => ({
      command: handle.command,
      navigate: handle.navigate,
      setDisk: handle.setDisk,
      refresh: handle.refresh,
      search: handle.search,
      crossCopy: handle.crossCopy,
      crossMove: handle.crossMove,
      crop: handle.crop,
      aiTag: handle.aiTag,
      setLocale: handle.setLocale,
      updateToken: handle.updateToken,
      ready: handle.ready
    }), [handle]);
    const containerStyle = {
      width: typeof width === "number" ? `${width}px` : width,
      height: typeof height === "number" ? `${height}px` : height,
      ...style
    };
    return /* @__PURE__ */ (0, import_jsx_runtime.jsx)("div", { className, style: containerStyle, children: /* @__PURE__ */ (0, import_jsx_runtime.jsx)(
      "iframe",
      {
        ref: handle.iframeRef,
        src: handle.iframeSrc,
        style: { width: "100%", height: "100%", border: "none" },
        allow: "clipboard-write",
        title: "FluxFiles File Manager"
      }
    ) });
  }
);

// src/FluxFilesModal.tsx
var import_react3 = require("react");
var import_jsx_runtime2 = require("react/jsx-runtime");
var defaultOverlayStyle = {
  position: "fixed",
  inset: 0,
  background: "rgba(0, 0, 0, 0.5)",
  zIndex: 99999,
  display: "flex",
  alignItems: "center",
  justifyContent: "center"
};
var defaultModalStyle = {
  width: "90vw",
  maxWidth: "1200px",
  height: "85vh",
  background: "#fff",
  borderRadius: "8px",
  overflow: "hidden",
  boxShadow: "0 25px 50px rgba(0, 0, 0, 0.25)"
};
function FluxFilesModal({
  open,
  endpoint,
  token,
  disk,
  mode = "picker",
  multiple = false,
  allowedTypes,
  maxSize,
  onSelect,
  onClose,
  onReady,
  onEvent,
  onTokenRefresh,
  overlayClassName,
  modalClassName
}) {
  const handle = useFluxFiles({
    endpoint,
    token,
    disk,
    mode,
    multiple,
    allowedTypes,
    maxSize,
    onSelect,
    onClose,
    onReady,
    onEvent,
    onTokenRefresh
  });
  (0, import_react3.useEffect)(() => {
    if (!open) return;
    function onKeyDown(e) {
      if (e.key === "Escape") {
        onClose?.();
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, onClose]);
  (0, import_react3.useEffect)(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = prev;
    };
  }, [open]);
  const handleOverlayClick = (0, import_react3.useCallback)(
    (e) => {
      if (e.target === e.currentTarget) {
        onClose?.();
      }
    },
    [onClose]
  );
  if (!open) return null;
  return /* @__PURE__ */ (0, import_jsx_runtime2.jsx)(
    "div",
    {
      className: overlayClassName,
      style: overlayClassName ? void 0 : defaultOverlayStyle,
      onClick: handleOverlayClick,
      role: "dialog",
      "aria-modal": "true",
      "aria-label": "FluxFiles File Manager",
      children: /* @__PURE__ */ (0, import_jsx_runtime2.jsx)(
        "div",
        {
          className: modalClassName,
          style: modalClassName ? void 0 : defaultModalStyle,
          children: /* @__PURE__ */ (0, import_jsx_runtime2.jsx)(
            "iframe",
            {
              ref: handle.iframeRef,
              src: handle.iframeSrc,
              style: { width: "100%", height: "100%", border: "none" },
              allow: "clipboard-write",
              title: "FluxFiles File Manager"
            }
          )
        }
      )
    }
  );
}
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  FluxFiles,
  FluxFilesModal,
  useFluxFiles
});
