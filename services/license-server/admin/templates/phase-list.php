<div class="page-heading"><h1><?= $v->e($v->t($title)) ?></h1></div>
<?php if (in_array($page, ['customers', 'mail', 'activity'], true)): ?>
<form class="card filters" method="get">
    <?php foreach (
        $page === 'customers'
            ? ['q']
            : ($page === 'mail'
                ? ['kind', 'from']
                : ['kind', 'result', 'jti', 'from', 'to'])
        as $field
    ): ?>
    <label>
        <?= $v->e($v->t($field === 'q' ? 'search' : $field)) ?>
        <input
            name="<?= $field ?>"
            type="<?= in_array($field, ['from', 'to'], true) ? 'date' : 'text' ?>"
            value="<?= $v->e($_GET[$field] ?? '') ?>"
            maxlength="100"
            <?= $field === 'q' ? 'data-search' : '' ?>
        />
    </label>
    <?php endforeach ?>
    <?php if ($page === 'mail'): ?>
    <label class="check">
        <input name="failures" type="checkbox" value="1" <?= ($_GET['failures'] ?? '') === '1' ? 'checked' : '' ?> />
        <?= $v->e($v->t('failures')) ?>
    </label>
    <?php endif ?>
    <button><?= $v->e($v->t('filter')) ?></button>
</form>
<?php endif ?>
<section class="card">
    <?php if ($page === 'customers'): ?>
    <?= $v->table(
        $data['rows'],
        [
            'email',
            'active',
            'total',
            'plans',
            'first_purchase',
            'last_purchase',
            'net',
            'last_seen_at',
            'note',
            'identity_conflict',
        ],
        '/customers/',
    ) ?>
    <?php elseif ($page === 'mail'): ?>
    <?= $v->table($data['rows'], [
        'at',
        'kind',
        'recipient',
        'jti',
        'transport',
        'ok',
        'status',
        'actor',
    ]) ?>
    <?php if ($v->canWrite()): ?>
    <form
        method="post"
        action="/mail/redeliver"
        data-redeliver
        data-failed="<?= $v->e($v->t('delivery_failed')) ?>"
        data-remaining="<?= $v->e($v->t('remaining')) ?>"
    >
        <?= $v->csrf() ?>
        <button><?= $v->e($v->t('redeliver')) ?></button>
        <p role="status" data-backlog-status></p>
    </form>
    <?php foreach ($data['rows'] as $mail): ?>
    <?php if ($mail['jti'] && in_array($mail['kind'], ['licence', 'support'], true)): ?>
    <form method="post" action="/mail/<?= $v->e($mail['id']) ?>/resend">
        <?= $v->csrf() ?>
        <button><?= $v->e($v->t('resend')) ?> · <?= $v->e($mail['recipient']) ?></button>
    </form>
    <?php endif ?>
    <?php endforeach ?>
    <?php endif ?>
    <?php elseif ($page === 'activity'): ?>
    <?= $v->table($data['rows'], ['day', 'kind', 'result', 'jti', 'n', 'last_at']) ?>
    <?php elseif ($page === 'advisories'): ?>
    <?= $v->table($data['rows'], [
        'id',
        'component',
        'affected',
        'fixed_in',
        'severity',
        'title',
        'affected_count',
        'unknown_count',
    ]) ?>
    <?php foreach ($data['rows'] as $advisory): ?>
    <div class="actions">
        <?php if ($v->canWrite()): ?>
        <a class="button" href="/advisories?edit=<?= $v->e(rawurlencode($advisory['id'])) ?>">
            <?= $v->e($v->t('edit')) ?> · <?= $v->e($advisory['id']) ?>
        </a>
        <a class="button" href="/notices/new?advisory=<?= $v->e(rawurlencode($advisory['id'])) ?>"><?= $v->e($v->t('security_notice')) ?></a>
        <?php endif ?>
    </div>
    <?php endforeach ?>
    <?php else: ?>
    <?= $v->table($data['rows'], [
        'id',
        'advisory_id',
        'created_at',
        'subject',
        'recipients',
        'sent',
        'failed',
        'status',
    ]) ?>
    <?php endif ?>
</section>
<?php if ($data['next_cursor'] ?? null): ?>
<a class="button" href="?<?= $v->e(http_build_query(array_merge($_GET, ['cursor' => $data['next_cursor']]))) ?>"><?= $v->e($v->t('next')) ?></a>
<?php endif ?>
<?php if ($page === 'advisories' && $v->canWrite()): ?>
<?php $edit = [];
foreach ($data['rows'] as $row) {
    if ($row['id'] === ($_GET['edit'] ?? '')) {
        $edit = $row;
        break;
    }
} ?>
<section class="card">
    <h2><?= $v->e($v->t('advisory_edit')) ?></h2>
    <form method="post" action="/advisories">
        <?= $v->csrf() ?>
        <?php foreach (['id', 'component', 'affected', 'fixed_in', 'title', 'url'] as $field): ?>
        <label>
            <?= $v->e($v->t($field)) ?>
            <input
                name="<?= $field ?>"
                value="<?= $v->e($edit[$field] ?? '') ?>"
                maxlength="<?= $field === 'affected' ? 500 : 200 ?>"
                <?= in_array($field, ['id', 'component', 'affected', 'title'], true) ? 'required' : '' ?>
            />
        </label>
        <?php endforeach ?>
        <label>
            <?= $v->e($v->t('severity')) ?>
            <select name="severity">
                <?php foreach (['low', 'moderate', 'high', 'critical'] as $severity): ?>
                <option <?= ($edit['severity'] ?? 'high') === $severity ? 'selected' : '' ?> value="<?= $severity ?>"><?= $v->e($v->t($severity)) ?></option>
                <?php endforeach ?>
            </select>
        </label>
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
        <button><?= $v->e($v->t('save')) ?></button>
    </form>
</section>
<?php endif ?>
