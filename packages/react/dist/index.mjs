// src/FluxFiles.tsx
import { forwardRef, useImperativeHandle } from "react";

// src/useFluxFiles.ts
import { useCallback, useEffect, useRef, useState } from "react";
var SOURCE = "fluxfiles";
var VERSION = 1;
function uid() {
  return "ff-" + Math.random().toString(36).slice(2, 11) + Date.now().toString(36);
}
function useFluxFiles(options) {
  const iframeElRef = useRef(null);
  const [ready, setReady] = useState(false);
  const optionsRef = useRef(options);
  optionsRef.current = options;
  const endpoint = (options.endpoint || "").replace(/\/+$/, "");
  const iframeSrc = endpoint + "/public/index.html";
  const post = useCallback((type, payload = {}) => {
    const el = iframeElRef.current;
    if (!el?.contentWindow) return;
    el.contentWindow.postMessage(
      { source: SOURCE, type, v: VERSION, id: uid(), payload },
      "*"
    );
  }, []);
  const sendConfig = useCallback(() => {
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
  useEffect(() => {
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
  useEffect(() => {
    if (ready) {
      sendConfig();
    }
  }, [options.token, options.disk, options.mode, options.multiple, options.locale, ready, sendConfig]);
  const command = useCallback(
    (action, data = {}) => {
      post("FM_COMMAND", { action, ...data });
    },
    [post]
  );
  const navigate = useCallback((path) => command("navigate", { path }), [command]);
  const setDisk = useCallback((disk) => command("setDisk", { disk }), [command]);
  const refresh = useCallback(() => command("refresh"), [command]);
  const search = useCallback((q) => command("search", { q }), [command]);
  const crossCopy = useCallback((dstDisk, dstPath) => command("crossCopy", { dst_disk: dstDisk, dst_path: dstPath || "" }), [command]);
  const crossMove = useCallback((dstDisk, dstPath) => command("crossMove", { dst_disk: dstDisk, dst_path: dstPath || "" }), [command]);
  const crop = useCallback((x, y, width, height, savePath) => command("crop", { x, y, width, height, save_path: savePath || "" }), [command]);
  const aiTag = useCallback(() => command("aiTag"), [command]);
  const setLocale = useCallback((locale) => command("setLocale", { locale }), [command]);
  const updateToken = useCallback((token) => post("FM_TOKEN_UPDATED", { token }), [post]);
  const iframeRef = useCallback((el) => {
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
import { jsx } from "react/jsx-runtime";
var FluxFiles = forwardRef(
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
    useImperativeHandle(ref, () => ({
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
    return /* @__PURE__ */ jsx("div", { className, style: containerStyle, children: /* @__PURE__ */ jsx(
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
import { useCallback as useCallback2, useEffect as useEffect2 } from "react";
import { jsx as jsx2 } from "react/jsx-runtime";
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
  useEffect2(() => {
    if (!open) return;
    function onKeyDown(e) {
      if (e.key === "Escape") {
        onClose?.();
      }
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, onClose]);
  useEffect2(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = prev;
    };
  }, [open]);
  const handleOverlayClick = useCallback2(
    (e) => {
      if (e.target === e.currentTarget) {
        onClose?.();
      }
    },
    [onClose]
  );
  if (!open) return null;
  return /* @__PURE__ */ jsx2(
    "div",
    {
      className: overlayClassName,
      style: overlayClassName ? void 0 : defaultOverlayStyle,
      onClick: handleOverlayClick,
      role: "dialog",
      "aria-modal": "true",
      "aria-label": "FluxFiles File Manager",
      children: /* @__PURE__ */ jsx2(
        "div",
        {
          className: modalClassName,
          style: modalClassName ? void 0 : defaultModalStyle,
          children: /* @__PURE__ */ jsx2(
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
export {
  FluxFiles,
  FluxFilesModal,
  useFluxFiles
};
