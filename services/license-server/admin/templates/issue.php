<h1><?= $v->e($v->t('issue')) ?></h1>
<form class="card issue-form" method="post" action="/issue">
    <?= $v->csrf() ?>
    <label>
        <?= $v->e($v->t('email')) ?>
        <input type="email" name="email" required maxlength="254" />
    </label>
    <label>
        <?= $v->e($v->t('plan')) ?>
        <select name="plan" required>
            <?php foreach ($meta['plans'] as $plan => $definition): ?>
            <option value="<?= $v->e($plan) ?>"><?= $v->e($plan) ?></option>
            <?php endforeach ?>
        </select>
    </label>
    <label>
        <?= $v->e($v->t('customer')) ?>
        <input name="customer" maxlength="200" />
    </label>
    <label>
        <?= $v->e($v->t('order_id')) ?>
        <input name="order_id" value="manual-<?= bin2hex(random_bytes(6)) ?>" maxlength="100" />
    </label>
    <label>
        <?= $v->e($v->t('note')) ?>
        <textarea name="note" maxlength="500"></textarea>
    </label>
    <label class="check">
        <input type="checkbox" name="is_test" value="1" checked />
        <?= $v->e($v->t('test')) ?>
    </label>
    <fieldset>
        <legend><?= $v->e($v->t('delivery')) ?></legend>
        <label class="check">
            <input type="radio" name="deliver" value="mail" required />
            <?= $v->e($v->t('deliver_mail')) ?>
        </label>
        <label class="check">
            <input type="radio" name="deliver" value="manual" required />
            <?= $v->e($v->t('deliver_manual')) ?>
        </label>
    </fieldset>
    <label>
        <?= $v->e($v->t('code')) ?>
        <input
            name="code"
            inputmode="numeric"
            autocomplete="one-time-code"
            pattern="[0-9]{6}"
            maxlength="6"
        />
    </label>
    <button class="primary"><?= $v->e($v->t('issue')) ?></button>
</form>
