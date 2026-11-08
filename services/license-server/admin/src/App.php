<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

use FluxFiles\LicenseServer\Client\ApiClient;
use FluxFiles\LicenseServer\Client\Config;

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/../../client/ApiClient.php';

final class App
{
    private Auth $auth;
    private View $view;
    private ApiClient $api;

    public static function headers(): void
    {
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; require-trusted-types-for 'script'");
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
        if (!Csrf::host((string) ($_SERVER['HTTP_HOST'] ?? ''), Config::get('FLUXFILES_ADMIN_HOSTS', 'localhost,127.0.0.1')) || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            $this->error('bad_host', 400);
            return;
        }
        try {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)?: '/';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (in_array($path, ['/assets/admin.css', '/assets/admin.js'], true) && $method === 'GET') {
                header('Content-Type: ' . (str_ends_with($path, '.css')?'text/css': 'text/javascript') . '; charset=utf-8');
                readfile(__DIR__ . '/../public' . $path);
                return;
            }
            $this->auth = new Auth();
            $this->auth->load();
            if (!$this->auth->session) {
                $this->auth->create();
            }
            $this->view = new View(new I18n($this->auth->session['lang']), $this->auth->session);
            $this->api = new ApiClient(Config::secret('FLUXFILES_ADMIN_UI_TOKEN'), 'ui:' . ($this->auth->session['name'] ?? 'unknown'));
            if (!in_array($method, ['GET', 'POST'], true)) {
                $this->error('method_not_allowed', 405);
                return;
            }
            if ($method === 'POST') {
                if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
                    $this->error('invalid_input', 400);
                    return;
                }
                if (!Csrf::check($_SERVER, $this->auth->session['csrf'], (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
                    $this->error('csrf', 403);
                    return;
                }
            }
            if ($path === '/login') {
                if ($method === 'POST') {
                    $ok = $this->auth->login(substr((string) ($_POST['username'] ?? ''), 0, 64), substr((string) ($_POST['password'] ?? ''), 0, 1024), (string) ($_POST['code'] ?? ''));
                    $this->api = new ApiClient(Config::secret('FLUXFILES_ADMIN_UI_TOKEN'), 'ui:' . ($ok?$this->auth->session['name']: 'unknown'));
                    try {
                        $this->api->request('POST', '/admin/audit', ['action' => $ok?'login.ok': 'login.fail']);
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
                    if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') { $this->error('login_error', 401); } else { $this->view->render('login', ['title' => 'login', 'error' => 'login_error'], 401); }
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
                $this->redirect($this->auth->session['user_id']?'/': '/login');
                return;
            }
            if (!$this->auth->session['user_id']) {
                $this->redirect('/login');
                return;
            }
            if ($method === 'POST') {
                $this->post($path);
                return;
            }
            $this->get($path);
        } catch (\Throwable $e) {
            error_log('admin UI: ' . get_class($e) . ' ' . basename($e->getFile()) . ':' . $e->getLine());
            $code = $e instanceof \InvalidArgumentException?'invalid_input': $e->getMessage();
            if (!array_key_exists($code, require __DIR__ . '/../lang/en.php')) {
                $code = 'internal';
            }
            $this->error($code, ['api_unreachable' => 503, 'not_found' => 404, 'internal' => 500, 'stepup_required' => 403, 'forbidden_scope' => 403, 'unauthorized' => 401][$code] ?? 400);
        }
    }

    private function get(string $path): void
    {
        if ($path === '/') {
            $data = $this->api->request('GET', '/admin/stats');
            $this->view->render('dashboard', ['title' => 'dashboard', 'data' => $data]);
            return;
        }
        if ($path === '/licences') {
            $data = $this->api->request('GET', '/admin/licences', $_GET);
            $meta = $this->api->request('GET', '/admin/meta');
            $this->view->render('licences', ['title' => 'licences', 'data' => $data, 'meta' => $meta]);
            return;
        }
        if ($path === '/issue') {
            $this->view->render('issue', ['title' => 'issue', 'meta' => $this->api->request('GET', '/admin/meta')]);
            return;
        }
        if (preg_match('~^/licences/([a-f0-9]{24})$~D', $path, $m)) {
            $data = $this->api->request('GET', '/admin/licences/' . $m[1]);
            $action = (string) ($_GET['confirm'] ?? '');
            if ($action !== '') {
                if (!in_array($action, ['revoke', 'refund', 'restore', 'subscription-revoke', 'archive', 'reveal'], true)) {
                    $this->error('not_found', 404);
                    return;
                }
                $preview = $this->api->request('GET', '/admin/licences/' . $m[1] . '/cascade-preview', ['status' => $action === 'restore'?'active': ($action === 'refund'?'refunded': 'revoked')]);
                if ($action === 'subscription-revoke') {
                    $preview = ['affected' => $data['chain']];
                }
                $this->view->render('confirm', ['title' => $action === 'subscription-revoke'?'subscription_revoke': $action, 'data' => $data, 'preview' => $preview, 'action' => $action]);
                return;
            }
            $this->view->render('detail', ['title' => 'licences', 'data' => $data]);
            return;
        }
        if ($path === '/events' || $path === '/audit') {
            $data = $this->api->request('GET', $path === '/events'?'/admin/events': '/admin/audit', $_GET);
            $this->view->render('records', ['title' => $path === '/events'?'events': 'audit', 'data' => $data]);
            return;
        }
        if (preg_match('~^/events/([0-9]+)$~D', $path, $m)) {
            $this->view->render('event', ['title' => 'events', 'data' => $this->api->request('GET', '/admin/events/' . $m[1])]);
            return;
        }
        if (preg_match('~^/stats/(revenue|installs|mail)$~D', $path, $m)) {
            $this->view->render('stats', ['title' => $m[1], 'data' => $this->api->request('GET', '/admin/stats/' . $m[1], $_GET)]);
            return;
        }
        if ($path === '/system') {
            $this->view->render('system', ['title' => 'system', 'data' => $this->api->request('GET', '/admin/system')]);
            return;
        }
        if (preg_match('~/(reveal|revoke|restore|resend|mark-test|unmark-test|note|resolve|archive-test)$~D', $path) || in_array($path, ['/logout', '/lookup-key'], true)) {
            $this->error('method_not_allowed', 405);
            return;
        }
        $this->error('not_found', 404);
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
            $r = $this->api->request('POST', '/admin/lookup-key', ['license_key' => (string) ($b['license_key'] ?? '')]);
            $this->redirect('/licences/' . rawurlencode($r['jti']));
            return;
        }
        if ($path === '/issue') {
            $this->requireStepup();
            if (!in_array($b['deliver'] ?? '', ['manual', 'mail'], true)) {
                throw new \InvalidArgumentException();
            }
            $out = $this->api->request('POST', '/issue', array_intersect_key($b, array_flip(['email', 'plan', 'customer', 'order_id', 'note', 'deliver'])) + ['is_test' => isset($b['is_test'])]);
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
            if (($detail['licence']['subscription_id'] ?? '') !== $m[1] || !hash_equals(substr($jti, - 6), (string) ($b['confirmation'] ?? ''))) {
                throw new \InvalidArgumentException();
            }
            $this->api->request('POST', '/revoke', ['subscription_id' => $m[1], 'status' => 'revoked']);
            $this->redirect('/licences/' . $jti . '?notice=success');
            return;
        }
        if (preg_match('~^/licences/([a-f0-9]{24})/(revoke|restore|resend|mark-test|unmark-test|note|reveal|archive)$~D', $path, $m)) {
            [$all, $jti, $action] = $m;
            if (in_array($action, ['restore', 'reveal', 'archive'], true)) {
                $this->requireStepup();
            }
            if ($action === 'revoke' || $action === 'restore') {
                if ($action === 'revoke' && !hash_equals(substr($jti, - 6), (string) ($b['confirmation'] ?? ''))) {
                    throw new \InvalidArgumentException();
                }
                $status = $action === 'restore'?'active': ($b['status'] ?? 'revoked');
                if (!in_array($status, ['active', 'revoked', 'refunded'], true) || ($action === 'revoke' && $status === 'active')) {
                    throw new \InvalidArgumentException();
                }
                $this->api->request('POST', '/revoke', ['jti' => $jti, 'status' => $status]);
            } elseif ($action === 'archive') {
                $this->api->request('POST', '/admin/licences/archive', ['jtis' => [$jti]]);
            } elseif ($action === 'reveal') {
                $out = $this->api->request('POST', '/admin/licences/' . $jti . '/reveal', ['reason' => (string) ($b['reason'] ?? '')]);
                if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($out);
                    return;
                }
                $this->view->render('revealed', ['title' => 'reveal', 'key' => $out['license_key']]);
                return;
            } elseif ($action === 'resend') {
                $this->api->request('POST', '/admin/licences/' . $jti . '/resend');
            } else {
                $this->api->request('POST', '/admin/licences/' . $jti . '/flags', $action === 'note'?['note' => (string) ($b['note'] ?? '')]: ['is_test' => $action === 'mark-test']);
            }
            $undo = in_array($action, ['mark-test', 'unmark-test'], true)?'&undo=' . $action: '';
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
