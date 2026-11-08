<section class="login card"><p class="eyebrow">FLUXFILES / ADMIN</p><h1><?= $v->e($v->t('login')) ?></h1><p class="muted"><?= $v->e($v->t('tunnel')) ?></p>
<?php if (isset($error)): ?><p class="alert" role="alert"><?= $v->e($v->t($error)) ?></p><?php endif ?>
<form method="post" action="/login"><?= $v->csrf() ?>
<label><?= $v->e($v->t('username')) ?><input name="username" autocomplete="username" maxlength="64" required autofocus></label>
<label><?= $v->e($v->t('password')) ?><input type="password" name="password" autocomplete="current-password" maxlength="1024" required></label>
<label><?= $v->e($v->t('code')) ?><input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label>
<button class="primary"><?= $v->e($v->t('login')) ?></button></form></section>
