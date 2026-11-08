<h1><?= $v->e($v->t('dashboard')) ?></h1>
<div class="card alert" role="alert">
    <?= $v->e($v->t('api_unreachable')) ?>
    <code><?= $v->e(\FluxFiles\LicenseServer\Client\Config::api()) ?></code>
    <a href="/" class="button"><?= $v->e($v->t('retry')) ?></a>
</div>
<div class="kpis">
    <?php foreach (['net_month', 'mrr', 'founding', 'active_30d', 'backup', 'telegram'] as $key): ?>
    <section class="card kpi">
        <h2><?= $v->e($v->t($key)) ?></h2>
        <strong>—</strong>
        <p class="muted"><?= $v->e($v->t('data_unavailable')) ?></p>
    </section>
    <?php endforeach ?>
</div>
<section class="card">
    <h2><?= $v->e($v->t('session')) ?></h2>
    <p><?= $v->e($v->session['name']) ?> · <?= $v->e($v->session['role']) ?></p>
</section>
