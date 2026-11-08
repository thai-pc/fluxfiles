<h1><?= $v->e($v->t('security_notice')) ?> · <?= $v->e($data['advisory']['id']) ?></h1>
<form class="card filters" method="get" action="/notices/new">
    <input type="hidden" name="advisory" value="<?= $v->e($data['advisory']['id']) ?>" />
    <label class="check">
        <input type="checkbox" name="include_unknown" value="1" <?= $data['include_unknown'] ? 'checked' : '' ?> />
        <?= $v->e($v->t('include_unknown')) ?>
    </label>
    <button><?= $v->e($v->t('preview')) ?></button>
</form>
<section class="card">
    <h2><?= $v->e($v->t('recipients')) ?>: <?= $v->e($data['recipients']) ?></h2>
    <ul>
        <?php foreach ($data['samples'] as $sample): ?>
        <li><?= $v->e($sample) ?></li>
        <?php endforeach ?>
    </ul>
    <h3 data-preview-subject><?= $v->e($data['subject']) ?></h3>
    <pre class="notice-preview" data-preview-body><?= $v->e($data['body_text']) ?></pre>
</section>
<?php if ($v->canWrite()): ?>
<form
    class="card"
    method="post"
    action="/notices/send"
    data-notice-form
    data-footer="Reply to stop receiving these security notices."
    <?php foreach ($data['example'] ?? [] as $name => $value): ?>
    data-example-<?= $v->e($name) ?>="<?= $v->e($value) ?>"
    <?php endforeach ?>
>
    <?= $v->csrf() ?>
    <input type="hidden" name="advisory" value="<?= $v->e($data['advisory']['id']) ?>" />
    <input type="hidden" name="request_id" value="<?= $v->e($requestId) ?>" />
    <input type="hidden" name="preview_fingerprint" value="<?= $v->e($data['preview_fingerprint'] ?? '') ?>" />
    <?php if ($data['include_unknown']): ?>
    <input type="hidden" name="include_unknown" value="1" />
    <?php endif ?>
    <label>
        <?= $v->e($v->t('subject')) ?>
        <input name="subject" value="<?= $v->e($data['template_subject']) ?>" maxlength="200" required />
    </label>
    <label>
        <?= $v->e($v->t('body_text')) ?>
        <textarea name="body_text" maxlength="10000" required><?= $v->e($data['template_body']) ?></textarea>
    </label>
    <label>
        <?= $v->e($v->t('type_advisory_id')) ?>
        <input name="confirmation" required pattern="<?= $v->e($data['advisory']['id']) ?>" autocomplete="off" />
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
    <button class="primary"><?= $v->e($v->t('send_notice')) ?></button>
</form>
<?php endif ?>
