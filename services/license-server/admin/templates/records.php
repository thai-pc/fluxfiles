<h1><?= $v->e($v->t($title)) ?></h1>
<form class="filters card" method="get">
    <label>
        <?= $v->e($v->t('from')) ?>
        <input type="date" name="from" value="<?= $v->e($_GET['from'] ?? '') ?>" />
    </label>
    <?php foreach (
        $title === 'events' ? ['source', 'outcome', 'type'] : ['actor', 'action', 'target']
        as $field
    ): ?>
    <label>
        <?= $v->e($v->t($field)) ?>
        <input name="<?= $field ?>" maxlength="100" value="<?= $v->e($_GET[$field] ?? '') ?>" />
    </label>
    <?php endforeach ?>
    <?php if ($title === 'events'): ?>
    <label>
        <?= $v->e($v->t('failures')) ?>
        <select name="failures">
            <option value="1"><?= $v->e($v->t('only')) ?></option>
            <option value="0" <?= ($_GET['failures'] ?? '') === '0' ? 'selected' : '' ?>><?= $v->e($v->t('all')) ?></option>
        </select>
    </label>
    <?php else: ?>
    <label class="check">
        <input type="checkbox" name="key_only" value="1" <?= ($_GET['key_only'] ?? '') === '1' ? 'checked' : '' ?> />
        <?= $v->e($v->t('key_only')) ?>
    </label>
    <?php endif ?>
    <button><?= $v->e($v->t('filter')) ?></button>
</form>
<section class="card flush"><?= $v->table(
    $data['rows'],
    $title === 'events'
        ? [
            'received_at',
            'source',
            'type',
            'verify',
            'outcome',
            'http_status',
            'order_id',
            'product_id',
            'attempts',
            'resolved_at',
        ]
        : ['at', 'actor', 'client', 'action', 'target', 'result', 'reason'],
    $title === 'events' ? '/events/' : null,
) ?></section>
<?php if ($data['next_cursor']): ?>
<a class="button" href="?<?= $v->e(http_build_query(array_merge($_GET, ['cursor' => $data['next_cursor']]))) ?>"><?= $v->e($v->t('next')) ?> →</a>
<?php endif ?>
