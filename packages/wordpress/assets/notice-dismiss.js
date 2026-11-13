/* Remember a dismissed FluxFiles admin notice (per user). Config: FluxFilesNotice. */
(function (c) {
  if (!c || !window.fetch) { return; }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.notice-dismiss');
    var n = btn && btn.closest('[data-fluxfiles-notice]');
    if (!n) { return; }
    fetch(c.url, {
      method: 'POST', credentials: 'same-origin',
      body: new URLSearchParams({ action: 'fluxfiles_dismiss_notice', _wpnonce: c.nonce, notice: n.getAttribute('data-fluxfiles-notice') })
    });
  });
})(window.FluxFilesNotice);
