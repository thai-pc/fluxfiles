<h1><?= $v->e($data['email']) ?></h1>
<section class="card">
    <h2><?= $v->e($v->t('customer_note')) ?></h2>
    <?php if ($v->canWrite()): ?>
    <form method="post" action="/customers/<?= $v->e($data['chash']) ?>/note">
        <?= $v->csrf() ?>
        <label>
            <?= $v->e($v->t('customer_note')) ?>
            <textarea name="note" maxlength="500"><?= $v->e($data['note']['note'] ?? '') ?></textarea>
        </label>
        <button><?= $v->e($v->t('save')) ?></button>
    </form>
    <?php else: ?>
    <p><?= $v->e($data['note']['note'] ?? '') ?></p>
    <?php endif ?>
</section>
<section class="card">
    <h2><?= $v->e($v->t('licences')) ?></h2>
    <?= $v->table(
        $data['licences'],
        ['jti', 'plan', 'status', 'created_at', 'expires'],
        '/licences/',
    ) ?>
</section>
<section class="card">
    <h2><?= $v->e($v->t('orders')) ?></h2>
    <?= $v->table($data['orders'], [
        'order_id',

        'paid_at',

        'status',

        'currency',

        'net_amount',

        'refunded_amount',

        'subscription_id',
    ]) ?>
    <?php foreach (array_unique(array_filter(array_column($data['orders'], 'customer_id'))) as $id): ?>
    <?php $url=$v->polar('CUSTOMER',$id);if ($url): ?>
    <p>
        <a href="<?= $v->e($url) ?>" rel="noreferrer noopener" target="_blank">
            Polar · <?= $v->e($id) ?> ↗
        </a>
    </p>
    <?php endif ?>
    <?php endforeach ?>
</section>
<section class="card">
    <h2><?= $v->e($v->t('timeline')) ?></h2>
    <?= $v->table($data['timeline'], [
        'at',
        'jti',
        'kind',
        'from_status',
        'to_status',
        'actor',
    ]) ?>
</section>
