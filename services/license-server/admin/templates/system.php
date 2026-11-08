<h1><?= $v->e($v->t('system')) ?></h1>
<?php if ($cookieIsolationWarning ?? false): ?>
<p class="alert" role="alert"><?= $v->e($v->t('cookie_isolation_warning')) ?></p>
<?php endif ?>
<?php if ($v->canWrite()): ?>
<div class="actions">
    <form method="post" action="/system/test-telegram">
        <?= $v->csrf() ?>
        <button><?= $v->e($v->t('test_telegram')) ?></button>
    </form>
    <form method="post" action="/system/test-mail">
        <?= $v->csrf() ?>
        <button><?= $v->e($v->t('test_mail')) ?></button>
    </form>
</div>
<?php endif ?>
<?php foreach ($data as $key => $value): ?>
<section class="card">
    <h2><?= $v->e($v->t($key)) ?></h2>
    <?php if ($value === null || $value === '' || $value === []): ?>
    <p class="empty"><?= $v->e(
        $v->t(
            in_array($key, ['build', 'mail_tls', 'heartbeat'], true) ? 'empty_' . $key : 'empty_system',
        ),
    ) ?></p>
    <?php elseif ($key === 'health'): ?>
    <h3><?= $v->e($v->t('uptime_heading')) ?></h3>
    <?= $v->table($value['targets']) ?>
    <h3><?= $v->e($v->t('latest')) ?></h3>
    <?= $v->table($value['latest']) ?>
    <?php elseif (is_array($value)): ?>
    <?= $v->facts($value) ?>
    <?php else: ?>
    <p><?= $v->e(is_bool($value) ? ($value ? '✓' : '✗') : $value) ?></p>
    <?php endif ?>
</section>
<?php endforeach ?>

<?php if ($v->canWrite()): ?>
<section class="card">
    <h2><?= $v->e($v->t('backup_now')) ?></h2>
    <p><?= $v->e($v->t('backup_staging')) ?></p>
    <form method="post" action="/system/backup">
        <?= $v->csrf() ?>
        <label>
            <?= $v->e($v->t('code')) ?>
            <input
                name="code"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                autocomplete="one-time-code"
            />
        </label>
        <button><?= $v->e($v->t('backup_now')) ?></button>
    </form>
</section>
<?php endif ?>
