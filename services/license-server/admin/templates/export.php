<h1><?= $v->e($v->t('export')) ?></h1><form class="card issue-form" method="post" action="/export"><?= $v->csrf() ?>
<label><?= $v->e($v->t('from')) ?><input type="date" name="from" value="<?= $v->e($_GET['from']??date('Y-m-01')) ?>" required></label><label><?= $v->e($v->t('to')) ?><input type="date" name="to" value="<?= $v->e($_GET['to']??date('Y-m-d')) ?>" required></label>
<label><?= $v->e($v->t('code')) ?><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"></label><button class="primary"><?= $v->e($v->t('export')) ?></button></form>
