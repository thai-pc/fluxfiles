<!doctype html>
<html lang="<?= $v->e($v->i18n->lang) ?>" data-theme="<?= $v->e($v->session['theme'] ?? 'auto') ?>" data-instance="<?= \FluxFiles\LicenseServer\Client\Config::sandbox() ? 'sandbox' : 'production' ?>">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf" content="<?= $v->e($v->session['csrf'] ?? '') ?>" />
        <title><?= \FluxFiles\LicenseServer\Client\Config::sandbox() ? '[SANDBOX] ' : '' ?><?= $v->e($v->t($title ?? 'app')) ?> · FluxFiles</title>
        <link rel="stylesheet" href="/assets/admin.css" />
        <script src="/assets/admin.js" defer></script>
    </head>
    <body <?php foreach (
        [
            'rate_limited',
            'invalid_input',
            'login_error',
            'csrf',
            'stepup_required',
            'api_unreachable',
            'internal',
            'unauthorized',
            'not_found',
            'archive_not_allowed',
            'invalid_status',
            'forbidden_scope',
            'preview_changed',
            'replay_unavailable',
            'feature_disabled',
            'export_too_large',
            'provider_unavailable',
        ]
        as $code
    ): ?> data-error-<?= str_replace('_', '-', $code) ?>="<?= $v->e($v->t($code)) ?>" <?php endforeach ?>>
        <?php if (\FluxFiles\LicenseServer\Client\Config::sandbox()): ?>
        <div class="sandbox-banner" role="status"><?= $v->e($v->t('sandbox')) ?></div>
        <?php endif ?>
        <header class="topbar">
            <a class="brand" href="/">
                FluxFiles
                <span><?= $v->e($v->t('app')) ?></span>
            </a>
            <div class="preferences">
                <?php if ($v->session): ?>
                <form method="post" action="/pref/lang">
                    <?= $v->csrf() ?>
                    <button name="value" value="<?= $v->i18n->lang === 'vi' ? 'en' : 'vi' ?>" aria-label="<?= $v->e($v->t('language')) ?>">
                        <?= $v->i18n->lang === 'vi' ? 'EN' : 'VI' ?>
                    </button>
                </form>
                <form method="post" action="/pref/theme">
                    <?= $v->csrf() ?>
                    <label class="sr-only" for="theme"><?= $v->e($v->t('theme')) ?></label>
                    <select id="theme" name="value">
                        <?php foreach (['auto', 'light', 'dark'] as $theme): ?>
                        <option value="<?= $theme ?>" <?= ($v->session['theme'] ?? 'auto') === $theme ? 'selected' : '' ?>>
                            <?= $v->e($v->t($theme)) ?>
                        </option>
                        <?php endforeach ?>
                    </select>
                    <button><?= $v->e($v->t('save_theme')) ?></button>
                </form>
                <?php if ($v->session['user_id']): ?>
                <span>
                    <?= $v->e($v->session['name']) ?>
                    <?php if (!$v->canWrite()): ?>
                    · <?= $v->e($v->t('viewer')) ?>
                    <?php endif ?>
                </span>
                <form method="post" action="/logout">
                    <?= $v->csrf() ?>
                    <button><?= $v->e($v->t('logout')) ?></button>
                </form>
                <?php endif ?>
                <?php endif ?>
            </div>
        </header>
        <div class="shell">
            <?php if ($v->session['user_id'] ?? false): ?>
            <nav aria-label="FluxFiles">
                <?php foreach (
                    [
                        '/' => 'dashboard',
                        '/licences' => 'licences',
                        '/customers' => 'customers',
                        '/stats/revenue' => 'revenue',
                        '/stats/customers' => 'customer_stats',
                        '/stats/installs' => 'installs',
                        '/stats/versions' => 'versions',
                        '/stats/downloads' => 'downloads',
                        '/stats/mail' => 'mail',
                        '/stats/growth' => 'growth',
                        '/stats/demo' => 'demo',
                        '/stats/anomalies' => 'anomalies',
                        '/events' => 'events',
                        '/mail' => 'mail_log',
                        '/activity' => 'activity',
                        '/advisories' => 'advisories',
                        '/notices' => 'notices',
                        '/system' => 'system',
                        '/audit' => 'audit',
                    ]
                    as $url => $label
                ): ?>
                <a href="<?= $url ?>" <?= ($title ?? '') === $label ? 'aria-current="page"' : '' ?>><?= $v->e($v->t($label)) ?></a>
                <?php endforeach ?>
                <button type="button" data-open="help"><?= $v->e($v->t('help')) ?> ?</button>
            </nav>
            <?php endif ?>
            <main id="main">
                <?php if (($_GET['notice'] ?? '') === 'success'): ?>
                <div class="toast" role="status" aria-live="polite">
                    <?= $v->e($v->t('success')) ?>
                    <?php if (
                        isset($data['licence']['jti']) &&
                        in_array($_GET['undo'] ?? '', ['mark-test', 'unmark-test'], true)
                    ): ?>
                    <form method="post" action="/licences/<?= $v->e($data['licence']['jti']) ?>/<?= $_GET['undo'] === 'mark-test' ? 'unmark-test' : 'mark-test' ?>">
                        <?= $v->csrf() ?>
                        <button><?= $v->e($v->t('undo')) ?></button>
                    </form>
                    <?php endif ?>
                </div>
                <?php endif ?>
                <?= $content ?>
            </main>
        </div>
        <dialog id="help">
            <h2><?= $v->e($v->t('help')) ?></h2>
            <p><?= $v->e($v->t('shortcuts')) ?></p>
            <button type="button" data-close><?= $v->e($v->t('close')) ?></button>
        </dialog>
    </body>
</html>
