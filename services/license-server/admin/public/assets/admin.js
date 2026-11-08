"use strict";
(() => {
  document
    .querySelector("[data-nav-toggle]")
    ?.addEventListener("click", (event) => {
      const button = event.currentTarget;
      const nav = document.getElementById(button.getAttribute("aria-controls"));
      const open = button.getAttribute("aria-expanded") !== "true";
      button.setAttribute("aria-expanded", String(open));
      nav?.classList.toggle("is-open", open);
    });
  const clearKey = (dialog) => {
    dialog
      .querySelectorAll("[data-key], input[type=password]")
      .forEach((field) => {
        field.value = "";
        field.textContent = "";
      });
  };
  const armKey = (dialog) => {
    window.setTimeout(() => {
      clearKey(dialog);
      dialog.close();
    }, 60000);
  };
  document.querySelectorAll("dialog").forEach((dialog) => {
    dialog.addEventListener("close", () => clearKey(dialog));
    dialog.addEventListener("cancel", () => clearKey(dialog));
    if (dialog.open && dialog.querySelector("[data-key]")) armKey(dialog);
  });
  document.querySelectorAll("[data-close]").forEach((button) =>
    button.addEventListener("click", () => {
      const dialog = button.closest("dialog");
      clearKey(dialog);
      dialog.close();
    }),
  );
  document.querySelectorAll("[data-open]").forEach((button) =>
    button.addEventListener("click", (event) => {
      const target = document.getElementById(button.dataset.open);
      if (target instanceof HTMLDialogElement) {
        event.preventDefault();
        target.showModal();
      } else if (target) {
        target.querySelector("input")?.focus();
      }
    }),
  );
  document.querySelectorAll("[data-copy]").forEach((button) =>
    button.addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText(button.dataset.copy);
      } catch (_) {
        /* Plain IDs remain selectable. */
      }
    }),
  );
  document.querySelectorAll("[data-copy-key]").forEach((button) =>
    button.addEventListener("click", async () => {
      const field = button.closest("dialog").querySelector("[data-key]");
      try {
        await navigator.clipboard.writeText(field.value);
      } catch (_) {
        field.focus();
        field.select();
      }
    }),
  );
  document.querySelectorAll("[data-reveal-form]").forEach((form) =>
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = form.querySelector("button");
      const error = form.querySelector("[data-form-error]");
      button.disabled = true;
      form.setAttribute("aria-busy", "true");
      error.textContent = "";
      try {
        const result = await fetch(form.action, {
          method: "POST",
          credentials: "same-origin",
          headers: { Accept: "application/json" },
          body: new URLSearchParams(new FormData(form)),
        });
        const data = await result.json();
        if (!result.ok) throw new Error(form.dataset.error);
        const dialog = document.getElementById("revealed");
        dialog.querySelector("[data-key]").value = data.license_key;
        dialog.showModal();
        armKey(dialog);
        form.reset();
      } catch (_) {
        error.textContent = form.dataset.error;
      } finally {
        button.disabled = false;
        form.removeAttribute("aria-busy");
      }
    }),
  );
  document
    .querySelectorAll(
      "form[method=post]:not([data-reveal-form]):not([data-redeliver])",
    )
    .forEach((form) =>
      form.addEventListener("submit", async (event) => {
        // Native Chromium POST navigations serialize Origin as null under no-referrer.
        // Fetch in CORS mode preserves the real Origin while keeping referrers private.
        event.preventDefault();
        const button = event.submitter;
        const body = new URLSearchParams(new FormData(form));
        if (button?.name) body.append(button.name, button.value);
        const error =
          form.querySelector("[data-request-error]") ||
          document.createElement("p");
        error.dataset.requestError = "";
        error.setAttribute("role", "alert");
        error.classList.add("alert");
        error.textContent = "";
        if (!error.parentNode) form.append(error);
        if (button) button.disabled = true;
        form.setAttribute("aria-busy", "true");
        try {
          const response = await fetch(form.action, {
            method: "POST",
            mode: "cors",
            credentials: "same-origin",
            headers: { Accept: "application/json" },
            body,
          });
          if (response.redirected) {
            window.location.assign(response.url);
            return;
          }
          const data = await response.json();
          const message = document.body.getAttribute(
            "data-error-" +
              String(data.error || "internal").replaceAll("_", "-"),
          );
          error.textContent =
            message || document.body.getAttribute("data-error-internal");
        } catch (_) {
          error.textContent = document.body.getAttribute(
            "data-error-api-unreachable",
          );
        } finally {
          if (button) button.disabled = false;
          form.removeAttribute("aria-busy");
        }
      }),
    );
  document.querySelectorAll("[data-redeliver]").forEach((form) =>
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = event.submitter;
      const status = form.querySelector("[data-backlog-status]");
      if (button) button.disabled = true;
      try {
        for (let batch = 0; batch < 100; batch++) {
          const response = await fetch(form.action, {
            method: "POST",
            mode: "cors",
            credentials: "same-origin",
            headers: { Accept: "application/json" },
            body: new URLSearchParams(new FormData(form)),
          });
          const data = await response.json();
          if (!response.ok) throw new Error();
          status.textContent = form.dataset.remaining + ": " + data.remaining;
          if (data.failed > 0 || (data.sent === 0 && data.remaining > 0)) {
            status.textContent += " · " + form.dataset.failed;
            break;
          }
          if (data.remaining === 0) break;
        }
      } catch (_) {
        status.textContent = document.body.getAttribute(
          "data-error-api-unreachable",
        );
      } finally {
        if (button) button.disabled = false;
      }
    }),
  );
  document.querySelectorAll("[data-notice-form]").forEach((form) => {
    const replace = (value) =>
      value.replace(
        /\{(edition|version|fixed_in|url)\}/g,
        (_, key) => form.getAttribute("data-example-" + key) || "unknown",
      );
    const refresh = () => {
      document.querySelector("[data-preview-subject]").textContent = replace(
        form.elements.subject.value,
      );
      document.querySelector("[data-preview-body]").textContent =
        replace(form.elements.body_text.value) + "\n\n" + form.dataset.footer;
    };
    form.addEventListener("input", refresh);
  });
  window.addEventListener("pagehide", () => {
    document.querySelectorAll("dialog").forEach(clearKey);
    document.querySelectorAll("[name=polar_token]").forEach((input) => {
      input.value = "";
    });
  });
  window.addEventListener("pageshow", () =>
    document.querySelectorAll("[name=polar_token]").forEach((input) => {
      input.value = "";
    }),
  );
  window.setTimeout(
    () =>
      document.querySelectorAll(".toast").forEach((toast) => {
        toast.hidden = true;
      }),
    5000,
  );
  const panel = document.querySelector("[data-confirm-panel]");
  if (panel) {
    const dialog = document.createElement("dialog");
    dialog.append(panel);
    document.body.append(dialog);
    dialog.showModal();
    dialog.addEventListener("close", () =>
      window.location.assign(panel.dataset.return),
    );
  }
  let selected = -1;
  let prefix = false;
  document.addEventListener("keydown", (event) => {
    if (
      event.ctrlKey ||
      event.metaKey ||
      event.altKey ||
      /^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName)
    )
      return;
    const rows = Array.from(document.querySelectorAll("[data-row-link]"));
    if (prefix) {
      prefix = false;
      const routes = {
        d: "/",
        l: "/licences",
        s: "/stats/revenue",
        w: "/events",
        a: "/audit",
      };
      if (routes[event.key]) {
        event.preventDefault();
        window.location.assign(routes[event.key]);
      }
      return;
    }
    if (event.key === "g") {
      prefix = true;
      window.setTimeout(() => {
        prefix = false;
      }, 1000);
    }
    if (event.key === "/") {
      event.preventDefault();
      document.querySelector("[data-search]")?.focus();
    }
    if (event.key === "?") {
      event.preventDefault();
      document.getElementById("help").showModal();
    }
    if (event.key === "j" || event.key === "k") {
      event.preventDefault();
      if (!rows.length) return;
      selected = Math.max(
        0,
        Math.min(rows.length - 1, selected + (event.key === "j" ? 1 : -1)),
      );
      rows.forEach((row) => row.classList.remove("selected"));
      rows[selected].classList.add("selected");
      rows[selected].focus();
    }
    if (
      event.key === "Enter" &&
      selected >= 0 &&
      event.target === rows[selected]
    )
      window.location.assign(rows[selected].dataset.rowLink);
    if (event.key === "x" && selected >= 0) {
      const check = rows[selected].querySelector("input[type=checkbox]");
      if (check && !check.disabled) check.checked = !check.checked;
    }
    if (event.key === "Escape")
      document
        .querySelectorAll("dialog[open]")
        .forEach((dialog) => dialog.close());
  });
})();
