<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

use FluxFiles\LicenseServer\Client\ApiClient;
use FluxFiles\LicenseServer\Client\Config;

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Access.php';
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/../../client/ApiClient.php';

final class App
{
    private Auth $auth;
    private View $view;
    private ApiClient $api;

    public static function headers(): void
    {
        header(
            "Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; require-trusted-types-for 'script'",
        );
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header('Content-Type: text/html; charset=utf-8');
    }

    public function run(): void
    {
        self::headers();
        $this->view = new View(new I18n(Config::get('FLUXFILES_ADMIN_LANG', 'vi')), null);
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $allowed = implode(
            ',',
            array_filter(
                array_map(
                    'trim',
                    explode(',', Config::get('FLUXFILES_ADMIN_HOSTS', 'localhost,127.0.0.1')),
                ),
                static fn(string $host): bool => Csrf::localHost($host),
            ),
        );
        if (Access::enabled()) {
            $allowed = Config::get('FLUXFILES_ADMIN_ACCESS_HOST');
        }
        if (
            !Csrf::host($host, $allowed) ||
            !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        ) {
            $this->error('bad_host', 400);
            return;
        }
        if (!Access::allows($_SERVER)) {
            $this->error('access_denied', 403);
            return;
        }
        if (Access::enabled() && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            $_SERVER['HTTPS'] = 'on';
        }
        try {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (
                in_array($path, ['/assets/admin.css', '/assets/admin.js'], true) &&
                $method === 'GET'
            ) {
                header(
                    'Content-Type: ' .
                        (str_ends_with($path, '.css') ? 'text/css' : 'text/javascript') .
                        '; charset=utf-8',
                );
                readfile(__DIR__ . '/../public' . $path);
                return;
            }
            $this->auth = new Auth();
            $this->auth->load();
            if (!$this->auth->session) {
                $this->auth->create();
            }
            $this->view = new View(new I18n($this->auth->session['lang']), $this->auth->session);
            $this->api = new ApiClient(
                Config::secret('FLUXFILES_ADMIN_UI_TOKEN'),
                'ui:' . ($this->auth->session['name'] ?? 'unknown'),
                null,
                30,
                $this->auth->session['role'] ?? 'viewer',
            );
            if (!in_array($method, ['GET', 'POST'], true)) {
                $this->error('method_not_allowed', 405);
                return;
            }
            if ($method === 'POST') {
                if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
                    $this->error('invalid_input', 400);
                    return;
                }
                if (
                    !Csrf::check(
                        $_SERVER,
                        $this->auth->session['csrf'],
                        (string) ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')),
                    )
                ) {
                    $this->error('csrf', 403);
                    return;
                }
            }
            if ($path === '/login') {
                if ($method === 'POST') {
                    $ok = $this->auth->login(
                        substr((string) ($_POST['username'] ?? ''), 0, 64),
                        substr((string) ($_POST['password'] ?? ''), 0, 1024),
                        (string) ($_POST['code'] ?? ''),
                    );
                    $this->api = new ApiClient(
                        Config::secret('FLUXFILES_ADMIN_UI_TOKEN'),
                        'ui:' . ($ok ? $this->auth->session['name'] : 'unknown'),
                        null,
                        30,
                        $this->auth->session['role'] ?? 'viewer',
                    );
                    try {
                        $this->api->request('POST', '/admin/audit', [
                            'action' => $ok ? 'login.ok' : 'login.fail',
                        ]);
                    } catch (\Throwable $e) {
                        if ($ok) {
                            $this->auth->logout();
                        }
                        throw new \RuntimeException('api_unreachable');
                    }
                    if ($ok) {
                        $this->redirect('/');
                        return;
                    }
                    if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                        $this->error('login_error', 401);
                    } else {
                        $this->view->render(
                            'login',
                            ['title' => 'login', 'error' => 'login_error'],
                            401,
                        );
                    }
                    return;
                }
                $this->view->render('login', ['title' => 'login']);
                return;
            }
            if (preg_match('~^/pref/(lang|theme)$~D', $path, $m)) {
                if ($method !== 'POST') {
                    $this->error('method_not_allowed', 405);
                    return;
                }
                $this->auth->preference($m[1], (string) ($_POST['value'] ?? ''));
                $this->redirect($this->auth->session['user_id'] ? '/' : '/login');
                return;
            }
            if (!$this->auth->session['user_id']) {
                $this->redirect('/login');
                return;
            }
            if (
                !$this->view->canWrite() &&
                (($method === 'POST' && $path !== '/logout') ||
                    in_array(
                        $path,
                        ['/issue', '/export', '/export/orders.csv', '/notices/new'],
                        true,
                    ) ||
                    isset($_GET['confirm']))
            ) {
                $this->error('forbidden_scope', 403);
                return;
            }
            if ($method === 'POST') {
                if ($this->extendedPost($path)) {
                    return;
                }
                $this->post($path);
                return;
            }
            $this->get($path);
        } catch (\Throwable $e) {
            error_log(
                'admin UI: ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine(),
            );
            $code = $e instanceof \InvalidArgumentException ? 'invalid_input' : $e->getMessage();
            if (!array_key_exists($code, require __DIR__ . '/../lang/en.php')) {
                $code = 'internal';
            }
            $this->error(
                $code,
                [
                    'api_unreachable' => 503,
                    'not_found' => 404,
                    'internal' => 500,
                    'stepup_required' => 403,
                    'csrf' => 403,
                    'rate_limited' => 429,
                    'forbidden_scope' => 403,
                    'unauthorized' => 401,
                ][$code] ?? 400,
            );
        }
    }

    private function get(string $path): void
    {
        if ($this->extendedGet($path)) {
            return;
        }
        if ($path === '/') {
            try {
                $data = $this->api->request('GET', '/admin/stats');
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'api_unreachable') {
                    throw $e;
                }
                $this->view->render('dashboard-unavailable', ['title' => 'dashboard'], 503);
                return;
            }
            $this->view->render('dashboard', ['title' => 'dashboard', 'data' => $data]);
            return;
        }
        if ($path === '/licences') {
            $data = $this->api->request('GET', '/admin/licences', $_GET);
            $meta = $this->api->request('GET', '/admin/meta');
            $this->view->render('licences', [
                'title' => 'licences',
                'data' => $data,
                'meta' => $meta,
            ]);
            return;
        }
        if ($path === '/issue') {
            $this->view->render('issue', [
                'title' => 'issue',
                'meta' => $this->api->request('GET', '/admin/meta'),
            ]);
            return;
        }
        if (preg_match('~^/licences/([a-f0-9]{24})$~D', $path, $m)) {
            $data = $this->api->request('GET', '/admin/licences/' . $m[1]);
            $data['polar_actions'] = false;
            if ($data['licence']['subscription_id'] && $this->view->canWrite()) {
                $meta = $this->api->request('GET', '/admin/meta');
                $data['polar_actions'] = $meta['capabilities']['polar_cancel'] ?? false;
            }
            $data['chash'] = substr(hash('sha256', strtolower($data['licence']['email'])), 0, 16);
            try {
                $customer = $this->api->request('GET', '/admin/customers/' . $data['chash']);
                $data['customer_note'] = $customer['note']['note'] ?? '';
            } catch (\RuntimeException $e) {
                $data['customer_note'] = '';
            }
            $action = (string) ($_GET['confirm'] ?? '');
            if ($action !== '') {
                if (
                    !in_array(
                        $action,
                        ['revoke', 'refund', 'restore', 'subscription-revoke', 'archive', 'reveal'],
                        true,
                    )
                ) {
                    $this->error('not_found', 404);
                    return;
                }
                $preview = $this->api->request(
                    'GET',
                    '/admin/licences/' . $m[1] . '/cascade-preview',
                    [
                        'status' =>
                            $action === 'restore'
                                ? 'active'
                                : ($action === 'refund'
                                    ? 'refunded'
                                    : 'revoked'),
                    ],
                );
                if ($action === 'subscription-revoke') {
                    $preview = ['affected' => $data['chain']];
                }
                $this->view->render('confirm', [
                    'title' => $action === 'subscription-revoke' ? 'subscription_revoke' : $action,
                    'data' => $data,
                    'preview' => $preview,
                    'action' => $action,
                ]);
                return;
            }
            $this->view->render('detail', ['title' => 'licences', 'data' => $data]);
            return;
        }
        if ($path === '/events' || $path === '/audit') {
            $data = $this->api->request(
                'GET',
                $path === '/events' ? '/admin/events' : '/admin/audit',
                $_GET,
            );
            $this->view->render('records', [
                'title' => $path === '/events' ? 'events' : 'audit',
                'data' => $data,
            ]);
            return;
        }
        if (preg_match('~^/events/([0-9]+)$~D', $path, $m)) {
            $this->view->render('event', [
                'title' => 'events',
                'data' => $this->api->request('GET', '/admin/events/' . $m[1]),
            ]);
            return;
        }
        if (preg_match('~^/stats/(revenue|installs|mail)$~D', $path, $m)) {
            $this->view->render('stats', [
                'title' => $m[1],
                'data' => $this->api->request('GET', '/admin/stats/' . $m[1], $_GET),
            ]);
            return;
        }
        if ($path === '/system') {
            $this->view->render('system', [
                'title' => 'system',
                'data' => $this->api->request('GET', '/admin/system'),
                'cookieIsolationWarning' =>
                    Config::sandbox() &&
                    !str_ends_with(
                        explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''), 2)[0],
                        '.localhost',
                    ) &&
                    !Access::enabled(),
            ]);
            return;
        }
        if (
            preg_match(
                '~/(reveal|revoke|restore|resend|mark-test|unmark-test|note|resolve|archive-test)$~D',
                $path,
            ) ||
            in_array($path, ['/logout', '/lookup-key'], true)
        ) {
            $this->error('method_not_allowed', 405);
            return;
        }
        $this->error('not_found', 404);
    }

    private function extendedGet(string $path): bool
    {
        if (
            in_array($path, ['/customers', '/mail', '/activity', '/advisories', '/notices'], true)
        ) {
            $api = [
                '/customers' => '/admin/customers',
                '/mail' => '/admin/mail',
                '/activity' => '/admin/activity',
                '/advisories' => '/admin/advisories',
                '/notices' => '/admin/notices',
            ][$path];
            $title = $path === '/mail' ? 'mail_log' : ltrim($path, '/');
            $this->view->render('phase-list', [
                'title' => $title,
                'page' => ltrim($path, '/'),
                'data' => $this->api->request('GET', $api, $_GET),
            ]);
            return true;
        }
        if (preg_match('~^/customers/([a-f0-9]{16})$~D', $path, $m)) {
            $this->view->render('customer', [
                'title' => 'customers',
                'data' => $this->api->request('GET', '/admin/customers/' . $m[1]),
            ]);
            return true;
        }
        if (
            preg_match(
                '~^/stats/(customers|versions|downloads|growth|demo|anomalies)$~D',
                $path,
                $m,
            )
        ) {
            $this->view->render('statistics-extra', [
                'title' => $m[1] === 'customers' ? 'customer_stats' : $m[1],
                'kind' => $m[1],
                'data' => $this->api->request('GET', '/admin/stats/' . $m[1], $_GET),
            ]);
            return true;
        }
        if ($path === '/export') {
            $this->view->render('export', ['title' => 'export']);
            return true;
        }
        if ($path === '/export/orders.csv') {
            if (!Csrf::download($_SERVER)) {
                throw new \RuntimeException('csrf');
            }
            $this->requireStepup();
            $csv = $this->api->csv(
                '/admin/export/orders.csv',
                array_intersect_key($_GET, array_flip(['from', 'to'])),
            );
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=fluxfiles-orders.csv');
            echo \FluxFiles\LicenseServer\Client\Mask::safe($csv);
            return true;
        }
        if ($path === '/notices/new') {
            $data = $this->api->request(
                'GET',
                '/admin/notices/preview',
                array_intersect_key(
                    $_GET,
                    array_flip(['advisory', 'include_unknown', 'subject', 'body_text']),
                ),
            );
            $this->view->render('notice', [
                'title' => 'notices',
                'data' => $data,
                'requestId' => bin2hex(random_bytes(16)),
            ]);
            return true;
        }
        return false;
    }

    private function extendedPost(string $path): bool
    {
        $b = $_POST;
        if (preg_match('~^/customers/([a-f0-9]{16})/note$~D', $path, $m)) {
            $this->api->request('POST', '/admin/customers/' . $m[1] . '/note', [
                'note' => (string) ($b['note'] ?? ''),
            ]);
            $this->redirect('/customers/' . $m[1] . '?notice=success');
            return true;
        }
        if (preg_match('~^/events/([0-9]+)/replay$~D', $path, $m)) {
            $this->requireStepup();
            $this->api->request('POST', '/admin/events/' . $m[1] . '/replay');
            $this->redirect('/events/' . $m[1] . '?notice=success');
            return true;
        }
        if (preg_match('~^/mail/([0-9]+)/resend$~D', $path, $m)) {
            $this->api->request('POST', '/admin/mail/' . $m[1] . '/resend');
            $this->redirect('/mail?notice=success');
            return true;
        }
        if ($path === '/mail/redeliver') {
            $out = $this->api->request('POST', '/redeliver', ['limit' => 10]);
            if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($out);
                return true;
            }
            $this->redirect('/mail?notice=success');
            return true;
        }
        if ($path === '/export') {
            $this->requireStepup();
            $q = array_intersect_key($b, array_flip(['from', 'to']));
            $this->redirect('/export/orders.csv?' . http_build_query($q));
            return true;
        }
        if ($path === '/advisories') {
            $this->requireStepup();
            $this->api->request(
                'POST',
                '/admin/advisories',
                array_intersect_key(
                    $b,
                    array_flip([
                        'id',
                        'component',
                        'affected',
                        'fixed_in',
                        'severity',
                        'title',
                        'url',
                    ]),
                ),
            );
            $this->redirect('/advisories?notice=success');
            return true;
        }
        if ($path === '/notices/send') {
            $this->requireStepup();
            $out = $this->api->request(
                'POST',
                '/admin/notices/send',
                array_intersect_key(
                    $b,
                    array_flip([
                        'advisory',
                        'subject',
                        'body_text',
                        'confirmation',
                        'request_id',
                        'preview_fingerprint',
                    ]),
                ) + ['include_unknown' => isset($b['include_unknown'])],
            );
            $this->redirect('/notices?notice=success');
            return true;
        }
        if ($path === '/system/backup') {
            $this->requireStepup();
            $this->api->request('POST', '/admin/backup');
            $this->redirect('/system?notice=success');
            return true;
        }
        if (preg_match('~^/ops-items/([0-9]+)/resolve$~D', $path, $m)) {
            $this->api->request('POST', '/admin/ops-items/' . $m[1] . '/resolve');
            $this->redirect('/?notice=success');
            return true;
        }
        if (preg_match('~^/subscriptions/([A-Za-z0-9_.:-]{1,100})/cancel$~D', $path, $m)) {
            $this->requireStepup();
            $this->api->request('POST', '/admin/subscriptions/' . $m[1] . '/cancel', [
                'polar_token' => (string) ($b['polar_token'] ?? ''),
                'confirmation' => (string) ($b['confirmation'] ?? ''),
            ]);
            $this->redirect('/system?notice=success');
            return true;
        }
        return false;
    }

    private function requireStepup(): void
    {
        if (!$this->auth->stepup((string) ($_POST['code'] ?? ''))) {
            throw new \RuntimeException('stepup_required');
        }
    }

    private function post(string $path): void
    {
        $b = $_POST;
        if ($path === '/logout') {
            try {
                $this->api->request('POST', '/admin/audit', ['action' => 'logout']);
            } finally {
                $this->auth->logout();
            }
            $this->redirect('/login');
            return;
        }
        if ($path === '/lookup-key') {
            $r = $this->api->request('POST', '/admin/lookup-key', [
                'license_key' => (string) ($b['license_key'] ?? ''),
            ]);
            $this->redirect('/licences/' . rawurlencode($r['jti']));
            return;
        }
        if ($path === '/issue') {
            $this->requireStepup();
            if (!in_array($b['deliver'] ?? '', ['manual', 'mail'], true)) {
                throw new \InvalidArgumentException();
            }
            $out = $this->api->request(
                'POST',
                '/issue',
                array_intersect_key(
                    $b,
                    array_flip(['email', 'plan', 'customer', 'order_id', 'note', 'deliver']),
                ) + ['is_test' => isset($b['is_test'])],
            );
            $this->redirect('/licences/' . rawurlencode($out['record']['jti']) . '?notice=success');
            return;
        }
        if ($path === '/licences/archive-test') {
            $this->requireStepup();
            if (($b['archive_confirm'] ?? '') !== '1') {
                throw new \InvalidArgumentException();
            }
            $jtis = $b['jtis'] ?? [];
            if (!is_array($jtis)) {
                throw new \InvalidArgumentException();
            }
            $this->api->request('POST', '/admin/licences/archive', ['jtis' => $jtis]);
            $this->redirect('/licences?notice=success');
            return;
        }
        if (preg_match('~^/subscriptions/([A-Za-z0-9_.:-]{1,100})/revoke$~D', $path, $m)) {
            $jti = (string) ($b['jti'] ?? '');
            $detail = $this->api->request('GET', '/admin/licences/' . $jti);
            if (
                ($detail['licence']['subscription_id'] ?? '') !== $m[1] ||
                !hash_equals(substr($jti, -6), (string) ($b['confirmation'] ?? ''))
            ) {
                throw new \InvalidArgumentException();
            }
            $this->api->request('POST', '/revoke', [
                'subscription_id' => $m[1],
                'status' => 'revoked',
            ]);
            $this->redirect('/licences/' . $jti . '?notice=success');
            return;
        }
        if (
            preg_match(
                '~^/licences/([a-f0-9]{24})/(revoke|restore|resend|mark-test|unmark-test|note|reveal|archive)$~D',
                $path,
                $m,
            )
        ) {
            [$all, $jti, $action] = $m;
            if (in_array($action, ['restore', 'reveal', 'archive'], true)) {
                $this->requireStepup();
            }
            if ($action === 'revoke' || $action === 'restore') {
                if (
                    $action === 'revoke' &&
                    !hash_equals(substr($jti, -6), (string) ($b['confirmation'] ?? ''))
                ) {
                    throw new \InvalidArgumentException();
                }
                $status = $action === 'restore' ? 'active' : $b['status'] ?? 'revoked';
                if (
                    !in_array($status, ['active', 'revoked', 'refunded'], true) ||
                    ($action === 'revoke' && $status === 'active')
                ) {
                    throw new \InvalidArgumentException();
                }
                $this->api->request('POST', '/revoke', ['jti' => $jti, 'status' => $status]);
            } elseif ($action === 'archive') {
                $this->api->request('POST', '/admin/licences/archive', ['jtis' => [$jti]]);
            } elseif ($action === 'reveal') {
                $out = $this->api->request('POST', '/admin/licences/' . $jti . '/reveal', [
                    'reason' => (string) ($b['reason'] ?? ''),
                ]);
                if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($out);
                    return;
                }
                $this->view->render('revealed', [
                    'title' => 'reveal',
                    'key' => $out['license_key'],
                ]);
                return;
            } elseif ($action === 'resend') {
                $this->api->request('POST', '/admin/licences/' . $jti . '/resend');
            } else {
                $this->api->request(
                    'POST',
                    '/admin/licences/' . $jti . '/flags',
                    $action === 'note'
                        ? ['note' => (string) ($b['note'] ?? '')]
                        : ['is_test' => $action === 'mark-test'],
                );
            }
            $undo = in_array($action, ['mark-test', 'unmark-test'], true) ? '&undo=' . $action : '';
            $this->redirect('/licences/' . $jti . '?notice=success' . $undo);
            return;
        }
        if (preg_match('~^/events/([0-9]+)/resolve$~D', $path, $m)) {
            $this->api->request('POST', '/admin/events/' . $m[1] . '/resolve');
            $this->redirect('/events/' . $m[1] . '?notice=success');
            return;
        }
        if (in_array($path, ['/system/test-telegram', '/system/test-mail'], true)) {
            $this->api->request('POST', '/admin/' . substr($path, 8));
            $this->redirect('/system?notice=success');
            return;
        }
        $this->error('not_found', 404);
    }

    private function error(string $code, int $status): void
    {
        if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $code]);
            return;
        }
        $this->view->render('error', ['title' => $code, 'error' => $code], $status);
    }
    private function redirect(string $path): void
    {
        header('Location: ' . $path, true, 303);
    }
}
