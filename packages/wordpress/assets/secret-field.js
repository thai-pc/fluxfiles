/* Show/hide and generate buttons for the write-only secret fields on the settings page. */
document.addEventListener('click', function (e) {
  var b = e.target.closest('.fluxfiles-secret-eye,.fluxfiles-secret-gen');
  if (!b) { return; }
  var i = document.getElementById(b.getAttribute('data-target'));
  if (!i) { return; }
  if (b.classList.contains('fluxfiles-secret-eye')) {
    var show = i.type === 'password';
    i.type = show ? 'text' : 'password';
    var l = show ? b.getAttribute('data-hide') : b.getAttribute('data-show');
    b.setAttribute('aria-label', l); b.title = l; b.setAttribute('aria-pressed', show ? 'true' : 'false');
    return;
  }
  var a = new Uint8Array(32);
  crypto.getRandomValues(a);
  i.value = Array.prototype.map.call(a, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  i.type = 'text';
  var eye = document.querySelector('.fluxfiles-secret-eye[data-target="' + i.id + '"]');
  if (eye) {
    var h = eye.getAttribute('data-hide');
    eye.setAttribute('aria-label', h); eye.title = h; eye.setAttribute('aria-pressed', 'true');
  }
  i.focus(); i.select();
});
