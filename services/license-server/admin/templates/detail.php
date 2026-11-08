<?php $r = $data['licence'];
$base = '/licences/' . rawurlencode($r['jti']); ?>
<div class="page-heading">
    <div>
        <p class="eyebrow"><?= $v->e($r['plan']) ?> / <?= $v->e($r['edition']) ?></p>
        <h1><?= $v->e($r['customer'] ?: $r['email']) ?></h1>
        <p>
            <?= $v->e($r['email']) ?> ·
            <code><?= $v->e($r['jti']) ?></code>
            <button type="button" data-copy="<?= $v->e($r['jti']) ?>"><?= $v->e($v->t('copy')) ?></button>
        </p>
    </div>
    <a class="button" href="/licences"><?= $v->e($v->t('back')) ?></a>
</div>
<section class="card">
    <?= $v->table(
        [$r],
        [
            'plan',

            'status',

            'modules',

            'enforcement',

            'issued',

            'expires',

            'fingerprint',

            'is_test',

            'archived_at',
        ],
    ) ?>
    <?php if ($v->canWrite()): ?>
    <form method="post" action="<?= $v->e($base) ?>/note">
        <?= $v->csrf() ?>
        <label>
            <?= $v->e($v->t('licence_note')) ?>
            <textarea name="note" maxlength="500"><?= $v->e($r['note']) ?></textarea>
        </label>
        <button><?= $v->e($v->t('save')) ?></button>
    </form>
    <?php else: ?>
    <p><?= $v->e($r['note']) ?></p>
    <?php endif ?>
</section>
<?php if ($v->canWrite()): ?>
<div class="actions sticky-actions">
    <?php if (in_array($r['status'], ['active', 'superseded'], true)): ?>
    <a class="button danger" href="<?= $v->e($base) ?>?confirm=revoke"><?= $v->e($v->t('revoke')) ?></a>
    <a class="button danger" href="<?= $v->e($base) ?>?confirm=refund"><?= $v->e($v->t('refund')) ?></a>
    <?php else: ?>
    <a class="button" href="<?= $v->e($base) ?>?confirm=restore"><?= $v->e($v->t('restore')) ?></a>
    <?php endif ?>
    <?php if ($r['subscription_id']): ?>
    <a class="button danger" href="<?= $v->e($base) ?>?confirm=subscription-revoke">
        <?= $v->e($v->t('subscription_revoke')) ?>
    </a>
    <?php endif ?>
    <a class="button" href="<?= $v->e($base) ?>?confirm=reveal"><?= $v->e($v->t('reveal')) ?></a>
    <?php if (!in_array($r['status'], ['revoked', 'refunded'], true)): ?>
    <form method="post" action="<?= $v->e($base) ?>/resend">
        <?= $v->csrf() ?>
        <button><?= $v->e($v->t('resend')) ?> · <?= $v->e($r['email']) ?></button>
    </form>
    <?php endif ?>
    <form method="post" action="<?= $v->e($base) ?>/<?= $r['is_test'] ? 'unmark-test' : 'mark-test' ?>">
        <?= $v->csrf() ?>
        <button><?= $v->e($v->t($r['is_test'] ? 'unmark_test' : 'mark_test')) ?></button>
    </form>
    <?php if ($r['is_test'] && $r['status'] !== 'active'): ?>
    <a class="button" href="<?= $v->e($base) ?>?confirm=archive"><?= $v->e($v->t('archive')) ?></a>
    <?php endif ?>
</div>
<?php endif ?>
<div class="two-col">
    <section class="card">
        <h2><?= $v->e($v->t('polar')) ?></h2>
        <dl>
            <?php foreach ($data['polar'] as $kind => $id): ?>
            <dt><?= $v->e($v->t($kind)) ?></dt>
            <dd>
                <code><?= $v->e($id) ?></code>
                <?php $url = $kind ===    'checkout_id' ? null : $v->polar(strtoupper(str_replace('_id','',$kind)),(string)$id);


                     if ($url): ?>
                <a href="<?= $v->e($url) ?>" target="_blank" rel="noreferrer noopener">Polar ↗</a>
                <?php endif ?>
            </dd>
            <?php endforeach ?>
        </dl>
    </section>
    <section class="card">
        <h2><?= $v->e($v->t('installs')) ?></h2>
        <p><?= $v->e($v->t('first_seen_at')) ?>: <?= $v->time($data['install']['first_seen_at']) ?></p>
        <p><?= $v->e($v->t('last_seen_at')) ?>: <?= $v->time($data['install']['last_seen_at']) ?></p>
        <p><?= $v->e($v->t('nets_30d')) ?>: <?= $v->e($data['install']['nets_30d']) ?></p>
        <?= $v->table($data['install']['versions']) ?>
    </section>
</div>
<section class="card">
    <h2><?= $v->e($v->t('chain')) ?></h2>
    <?= $v->table(
        $data['chain'],
        ['jti', 'plan', 'status', 'issued', 'expires'],
        '/licences/',
    ) ?>
</section>
<section class="card">
    <h2><?= $v->e($v->t('timeline')) ?></h2>
    <?php if ($data['timeline'] === []): ?>
    <p class="empty"><?= $v->e($v->t('empty_timeline')) ?></p>
    <?php endif ?>
    <?php foreach ($data['timeline'] as $item): ?>
    <details>
        <summary><?= $v->time($item['at']) ?> · <?= $v->e($v->t($item['source'])) ?> · <?= $v->e(
            $v->t(
                $item['item']['kind'] ??
                    ($item['item']['action'] ?? ($item['item']['outcome'] ?? 'update-status')),
            ),
        ) ?></summary>
        <?= $v->table([$item['item']]) ?>
    </details>
    <?php endforeach ?>
</section>

<section class="card">
    <h2><?= $v->e($v->t('customer_note')) ?></h2>
    <?php if ($v->canWrite()): ?>
    <form method="post" action="/customers/<?= $v->e($data['chash']) ?>/note">
        <?= $v->csrf() ?>
        <label>
            <?= $v->e($v->t('customer_note')) ?>
            <textarea name="note" maxlength="500"><?= $v->e($data['customer_note']) ?></textarea>
        </label>
        <button><?= $v->e($v->t('save')) ?></button>
    </form>
    <?php else: ?>
    <p><?= $v->e($data['customer_note']) ?></p>
    <?php endif ?>
</section>

<?php if ($v->canWrite() && ($data['polar_actions'] ?? false)): ?>
<section class="card">
    <h2><?= $v->e($v->t('cancel_billing')) ?></h2>
    <p><?= $v->e($v->t('polar_token_once')) ?></p>
    <form method="post" action="/subscriptions/<?= $v->e(rawurlencode($r['subscription_id'])) ?>/cancel" autocomplete="off">
        <?= $v->csrf() ?>
        <label>
            <?= $v->e($v->t('polar_token')) ?>
            <input type="password" name="polar_token" autocomplete="off" maxlength="512" required />
        </label>
        <label>
            <?= $v->e($v->t('type_subscription_id')) ?>
            <input name="confirmation" autocomplete="off" required />
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
        <button class="danger"><?= $v->e($v->t('cancel_billing')) ?></button>
    </form>
</section>
<?php endif ?>
