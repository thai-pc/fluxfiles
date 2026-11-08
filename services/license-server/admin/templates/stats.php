<div class="page-heading">
    <h1><?= $v->e($v->t($title)) ?></h1>
    <?php if (\FluxFiles\LicenseServer\Client\Config::sandbox()): ?>
    <span class="pill test"><?= $v->e($v->t('test_data')) ?></span>
    <?php endif ?>
</div>
<?php if ($title !== 'mail'): ?>
<form class="filters card" method="get">
    <label>
        <?= $v->e($v->t('from')) ?>
        <input type="date" name="from" value="<?= $v->e($_GET['from'] ?? date('Y-m-01', strtotime('-11 months'))) ?>" />
    </label>
    <label>
        <?= $v->e($v->t('to')) ?>
        <input type="date" name="to" value="<?= $v->e($_GET['to'] ?? date('Y-m-d')) ?>" />
    </label>
    <?php if ($title === 'revenue'): ?>
    <label>
        <?= $v->e($v->t('currency')) ?>
        <select name="currency">
            <option value=""><?= $v->e($v->t('all')) ?></option>
            <?php foreach ($data['currencies'] as $c): ?>
            <option value="<?= $v->e($c['currency']) ?>" <?= ($_GET['currency'] ?? '') === $c['currency'] ? 'selected' : '' ?>><?= $v->e(strtoupper($c['currency'])) ?></option>
            <?php endforeach ?>
        </select>
    </label>
    <?php endif ?>
    <button><?= $v->e($v->t('filter')) ?></button>
</form>
<?php endif ?>
<?php if ($title === 'revenue'): ?>
<?php if ($v->canWrite()): ?>
<a class="button" href="/export?<?= $v->e(http_build_query(array_intersect_key($_GET, array_flip(['from', 'to'])))) ?>"><?= $v->e($v->t('export')) ?></a>
<?php endif ?>
<p class="muted">
    <?= $v->e($v->t('revenue_caveat')) ?>
    <a href="https://polar.sh/dashboard" target="_blank" rel="noreferrer noopener">Polar ↗</a>
</p>
<?php foreach ($data['currencies'] as $c): ?>
<section class="card">
    <h2><?= $v->e(strtoupper($c['currency'])) ?></h2>
    <div class="kpis">
        <?php foreach (['net_month', 'net_previous', 'mrr', 'arr', 'refunds_30d'] as $metric): ?>
        <div class="kpi">
            <span><?= $v->e($v->t($metric)) ?></span>
            <strong><?= $v->e(\FluxFiles\LicenseServer\Admin\View::money((int) $c[$metric], $c['currency'])) ?></strong>
        </div>
        <?php endforeach ?>
    </div>
    <?= \FluxFiles\LicenseServer\Admin\Svg::stackedBar(
        array_values(
            array_filter(
                $data['series'],
                static fn(array $r): bool => $r['currency'] === $c['currency'],
            ),
        ),
        'month',
        'net',
        $v->t('revenue') . ' ' . strtoupper($c['currency']),
        $v->t('data_table'),
    ) ?> <?= \FluxFiles\LicenseServer\Admin\Svg::line(
        array_values(
            array_filter(
                $data['mrr_series'],
                static fn(array $r): bool => $r['currency'] === $c['currency'],
            ),
        ),
        'month',
        'mrr',
        $v->t('mrr') . ' ' . strtoupper($c['currency']),
        $v->t('data_table'),
    ) ?>
</section>
<?php endforeach ?>
<section class="card">
    <h2><?= $v->e($v->t('refunds_monthly')) ?></h2>
    <?= $v->table($data['refunds_monthly']) ?><?= $v->table($data['refunds']) ?>
</section>
<section class="card">
    <h2><?= $v->e($v->t('revenue_by_plan')) ?></h2>
    <?= $v->table($data['plans']) ?>
    <h2><?= $v->e($v->t('discounts')) ?></h2>
    <?= $v->table($data['discounts']) ?>
</section>
<?php elseif ($title === 'installs'): ?>
<p class="muted"><?= $v->e($v->t('install_caveat')) ?></p>
<div class="kpis">
    <?php foreach (['active_30d', 'never_installed', 'gone_quiet'] as $metric): ?>
    <div class="card kpi">
        <span><?= $v->e($v->t($metric)) ?></span>
        <strong><?= $v->e($data[$metric]) ?></strong>
    </div>
    <?php endforeach ?>
</div>
<section class="card"><?= \FluxFiles\LicenseServer\Admin\Svg::line(
    $data['series'],
    'day',
    'active',
    $v->t('active_30d'),
    $v->t('data_table'),
) ?></section>
<?php foreach (['never' => 'never_installed', 'quiet' => 'gone_quiet'] as $key => $label): ?>
<section class="card">
    <h2><?= $v->e($v->t($label)) ?></h2>
    <?= $v->table(
        $data[$key],
        ['jti', 'email', 'plan', 'created_at', 'last_seen_at'],
        '/licences/',
    ) ?>
    <?php if ($v->canWrite()): ?>
    <?php foreach ($data[$key] as $licence): ?>
    <form method="post" action="/licences/<?= $v->e($licence['jti']) ?>/resend">
        <?= $v->csrf() ?>
        <button><?= $v->e($v->t('resend')) ?> · <?= $v->e($licence['email']) ?></button>
    </form>
    <?php endforeach ?>
    <?php endif ?>
</section>
<?php endforeach ?>
<?php else: ?>
<?php if (!$data['webhook_enabled']): ?>
<p class="alert"><?= $v->e($v->t('mail_caveat')) ?></p>
<?php endif ?>
<?php foreach ($data['periods'] as $days => $rows): ?>
<section class="card">
    <h2><?= $v->e($days) ?> <?= $v->e($v->t('period_days')) ?></h2>
    <?= $v->table($rows) ?>
</section>
<?php endforeach ?>
<section class="card">
    <h2><?= $v->e($v->t('failures')) ?></h2>
    <?= $v->table(
        $data['bounces'],
        ['at', 'kind', 'jti', 'recipient', 'status'],
        '/licences/',
    ) ?>
</section>
<?php endif ?>

<?php if ($title === 'revenue'): ?>
<section class="card">
    <h2><?= $v->e($v->t('past_due')) ?></h2>
    <?= $v->table($data['past_due'] ?? []) ?>
</section>
<?php endif ?>
