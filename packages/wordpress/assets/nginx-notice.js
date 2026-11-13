/* Background nginx-protection probe for the FluxFiles storage notice. Config: FluxFilesNginx. */
(function (c) {
  var n = document.getElementById('fluxfiles-nginx-notice');
  if (!c || !n || !window.fetch) { return; }
  var st = n.querySelector('.fluxfiles-nginx-status em');
  var b = new URLSearchParams({ action: 'fluxfiles_nginx_probe', _wpnonce: c.nonce });
  fetch(c.url, { method: 'POST', credentials: 'same-origin', body: b }).then(function (r) { return r.json(); }).then(function (j) {
    var d = (j && j.data) || {};
    var chip = document.getElementById('ff-status-nginx');
    if (chip) {
      chip.setAttribute('data-state', d.state === 'ok' ? 'ok' : (d.state === 'missing' ? 'bad' : 'warn'));
      var v = chip.querySelector('.ff-status-value');
      if (v) { v.textContent = d.state === 'ok' ? c.chipOk : (d.state === 'missing' ? c.chipMissing : c.chipUnknown); }
    }
    if (d.state === 'ok') { n.parentNode.removeChild(n); }
    else if (d.state === 'missing') { st.textContent = c.missing + ' ' + (d.missing || []).join(', ') + '.'; }
    else { st.textContent = c.unknown; }
  }).catch(function () { st.textContent = c.unknown; });
})(window.FluxFilesNginx);
