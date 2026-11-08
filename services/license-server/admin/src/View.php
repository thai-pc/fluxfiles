<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Admin;

use FluxFiles\LicenseServer\Client\Config;
use FluxFiles\LicenseServer\Client\Mask;

require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/Svg.php';
require_once __DIR__ . '/../../client/Mask.php';

final class View
{
    public function __construct(public I18n $i18n, public ?array $session)
    {
    }
    public static function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    }
    public function t(string $key): string
    {
        return $this->i18n->t($key);
    }
    public function e($s): string
    {
        return self::h(is_string($s)?preg_replace(Mask::KEY, '[redacted]', $s): $s);
    }
    public function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e($this->session['csrf'] ?? '') . '">';
    }
    public function time($at): string
    {
        if (!$at) {
            return $this->e($this->t('none'));
        }
        $d = (new \DateTimeImmutable('@' . (int) $at))->setTimezone(new \DateTimeZone(Config::get('FLUXFILES_ADMIN_TZ', 'Asia/Ho_Chi_Minh')));
        $delta = Auth::now() - (int) $at;
        $age = abs($delta);
        $relative = $age < 3600?intdiv($age, 60) . 'm': ($age < 86400?intdiv($age, 3600) . 'h': intdiv($age, 86400) . 'd');
        if ($delta < 0) { $relative = '+' . $relative; }
        return '<time datetime="' . gmdate('c', (int) $at) . '" title="' . gmdate('c', (int) $at) . '">' . $d->format('Y-m-d H:i') . ' <small>' . $relative . '</small></time>';
    }
    public static function money(int $amount, string $currency): string
    {
        $exp = in_array(strtolower($currency), ['vnd', 'jpy', 'krw'], true)?0: 2;
        return number_format($amount / (10 ** $exp), $exp, '.', ',') . ' ' . strtoupper($currency);
    }
    public function render(string $template, array $vars = [], int $status = 200): void
    {
        http_response_code($status);
        $v = $this;
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require __DIR__ . '/../templates/' . $template . '.php';
            $content = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        require __DIR__ . '/../templates/layout.php';
    }
    public function table(array $rows, array $cols = [], ?string $link = null): string
    {
        if ($rows === []) {
            return '<p class="empty">' . $this->e($this->t('empty')) . '</p>';
        }
        if ($cols === []) {
            $cols = array_keys($rows[0]);
        }
        $html = '<div class="table-wrap"><table><thead><tr>';
        foreach ($cols as $col) {
            $html.='<th scope="col">' . $this->e($this->t($col)) . '</th>';
        }
        $html.='</tr></thead><tbody>';
        foreach ($rows as $r) {
            $url = $link !== null?$link . rawurlencode((string) ($r[$link === '/licences/'?'jti': 'id'] ?? '')): null;
            $html.='<tr' . ($url?' data-row-link="' . $this->e($url) . '" tabindex="0"': '') . '>';
            foreach ($cols as $i => $col) {
                $val = $r[$col] ?? null;
                $html.='<td data-label="' . $this->e($this->t($col)) . '">';
                $text = is_array($val)?json_encode($val, JSON_UNESCAPED_UNICODE): ($val === null?$this->t('none'): (string) $val);
                if ($col === 'select') {
                    $allowed = !empty($r['is_test']) && $r['status'] !== 'active';
                    $cell = '<input type="checkbox" name="jtis[]" form="archive-selected" value="' . $this->e($r['jti']) . '" aria-label="' . $this->e($this->t('selected')) . ' ' . $this->e($r['jti']) . '"' . ($allowed?'': ' disabled') . '>';
                } elseif ($col === 'is_test') {
                    $cell = $val ? '<span class="pill test">'.$this->e($this->t('test')).'</span>' : $this->e($this->t('none'));
                } elseif (in_array($col, ['created_at', 'issued', 'expires', 'mailed_at', 'archived_at', 'status_at', 'body_expires_at', 'refunded_at', 'last_seen_at', 'first_seen_at', 'at', 'received_at', 'last_at', 'resolved_at', 'last_sent'], true)) {
                    $cell = $this->time($val);
                } elseif ($col === 'status' && in_array($val, ['active', 'superseded', 'revoked', 'refunded'], true)) {
                    $cell = '<span class="pill ' . $this->e($val) . '" title="' . $this->e($val) . '">' . $this->e($this->t($val)) . '</span>';
                } else {
                    $cell = $this->e($text);
                }
                if ($url && $i === ($cols[0] === 'select'?1: 0)) {
                    $cell = '<a href="' . $this->e($url) . '">' . $cell . '</a>';
                }
                $html.=$cell . '</td>';
            }
            $html.='</tr>';
        }
        return $html . '</tbody></table></div>';
    }
    public function polar(string $kind, string $id): ?string
    {
        if ($id === '') {
            return null;
        }
        $org = Config::get('FLUXFILES_ADMIN_POLAR_ORG');
        if ($org === '') {
            return null;
        }
        $paths = ['ORDER' => 'sales/{id}', 'SUBSCRIPTION' => 'sales/subscriptions/{id}', 'CUSTOMER' => 'customers/{id}'];
        $url = strtr(Config::get('FLUXFILES_ADMIN_POLAR_' . $kind . '_URL', 'https://polar.sh/dashboard/{org}/' . $paths[$kind]), ['{org}' => rawurlencode($org), '{id}' => rawurlencode($id)]);
        return parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'polar.sh' && !parse_url($url, PHP_URL_USER)?$url: null;
    }
}
