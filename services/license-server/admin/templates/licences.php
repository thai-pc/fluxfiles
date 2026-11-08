<div class="page-heading">
    <div>
        <p class="eyebrow">FLUXFILES / ADMIN</p>
        <h1><?= $v->e($v->t('licences')) ?></h1>
    </div>
    <?php if ($v->canWrite()): ?>
    <div class="actions">
        <a href="#lookup" class="button" data-open="lookup-dialog"><?= $v->e($v->t('lookup')) ?></a>
        <a class="button primary" href="/issue">+ <?= $v->e($v->t('issue')) ?></a>
    </div>
    <?php endif ?>
</div>
<form class="filters card" method="get" action="/licences">
    <label class="search">
        <?= $v->e($v->t('search')) ?>
        <input type="search" name="q" data-search maxlength="100" value="<?= $v->e($_GET['q'] ?? '') ?>" />
    </label>
    <?php foreach (
        [
            'plan' => array_keys($meta['plans']),
            'status' => $meta['statuses'],
            'gateway' => ['polar', 'manual'],
            'test' => ['hide', 'all', 'only'],
            'archived' => ['hide', 'all', 'only'],
        ]
        as $name => $options
    ): ?>
    <label>
        <?= $v->e($v->t($name)) ?>
        <select name="<?= $name ?>">
            <?php if (!in_array($name, ['test', 'archived'], true)): ?>
            <option value=""><?= $v->e($v->t('all')) ?></option>
            <?php endif ?>
            <?php foreach ($options as $option): ?>
            <option value="<?= $v->e($option) ?>" <?= ($_GET[$name] ?? '') === $option ? 'selected' : '' ?>><?= $v->e($v->t($option)) ?></option>
            <?php endforeach ?>
        </select>
    </label>
    <?php endforeach ?>
    <label>
        <?= $v->e($v->t('collapse')) ?>
        <select name="collapse">
            <option value="1"><?= $v->e($v->t('collapse_on')) ?></option>
            <option value="0" <?= ($_GET['collapse'] ?? '') === '0' ? 'selected' : '' ?>><?= $v->e($v->t('all')) ?></option>
        </select>
    </label>
    <button><?= $v->e($v->t('filter')) ?></button>
</form>
<section class="card flush"><?= $v->table(
    $data['rows'],
    [
        'select',
        'created_at',
        'email',
        'plan',
        'status',
        'expires',
        'gateway',
        'jti',
        'fingerprint',
        'last_seen_at',
        'cycles',
        'is_test',
        'archived_at',
    ],
    '/licences/',
    'empty_licences',
) ?></section>
<?php if ($data['next_cursor']): ?>
<a class="button" href="/licences?<?= $v->e(http_build_query(array_merge($_GET, ['cursor' => $data['next_cursor']]))) ?>"><?= $v->e($v->t('next')) ?> →</a>
<?php endif ?>
<?php if ($v->canWrite()): ?>
<section id="lookup" class="card lookup-panel">
    <h2><?= $v->e($v->t('lookup')) ?></h2>
    <form method="post" action="/lookup-key">
        <?= $v->csrf() ?>
        <label>
            <?= $v->e($v->t('lookup')) ?>
            <input
                type="password"
                name="license_key"
                autocomplete="off"
                maxlength="4096"
                required
            />
        </label>
        <button><?= $v->e($v->t('lookup')) ?></button>
    </form>
</section>

<form id="archive-selected" class="filters card" method="post" action="/licences/archive-test">
    <?= $v->csrf() ?>
    <label class="check">
        <input type="checkbox" name="archive_confirm" value="1" required />
        <?= $v->e($v->t('archive_selected')) ?>
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
    <button><?= $v->e($v->t('archive')) ?></button>
</form>
<dialog id="lookup-dialog">
    <h2><?= $v->e($v->t('lookup')) ?></h2>
    <form method="post" action="/lookup-key">
        <?= $v->csrf() ?>
        <label>
            <?= $v->e($v->t('lookup')) ?>
            <input
                type="password"
                name="license_key"
                autocomplete="off"
                maxlength="4096"
                required
            />
        </label>
        <button><?= $v->e($v->t('lookup')) ?></button>
    </form>
    <button type="button" data-close><?= $v->e($v->t('close')) ?></button>
</dialog>

<?php endif ?>
