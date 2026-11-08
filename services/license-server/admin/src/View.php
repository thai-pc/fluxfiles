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
    private string $context='records';
    public function __construct(public I18n $i18n, public ?array $session)
    {
    }
    public static function h($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    }
    public function canWrite(): bool { return ($this->session['role'] ?? null) === 'admin'; }
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
        $this->context=(string)($vars['title']??'records');
        Svg::labels($this->i18n);
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
    public function table(array $rows, array $cols = [], ?string $link = null, ?string $empty=null): string
    {
        if ($rows === []) {
            return '<p class="empty">' . $this->e($this->t($empty??('empty_'.$this->context))) . '</p>';
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
            $url = $link !== null?$link . rawurlencode((string) ($r[$link === '/licences/'?'jti': ($link === '/customers/'?'chash':'id')] ?? '')): null;
            $html.='<tr' . (!empty($r['archived_at'])?' class="archived-row"':'') . ($url?' data-row-link="' . $this->e($url) . '" tabindex="0"': '') . '>';
            foreach ($cols as $i => $col) {
                $val = $r[$col] ?? null;
                $html.='<td data-label="' . $this->e($this->t($col)) . '">';
                $text = is_array($val)?json_encode($val, JSON_UNESCAPED_UNICODE): ($val === null?$this->t('none'): (string) $val);
                if ($col === 'select') {
                    $allowed = $this->canWrite() && !empty($r['is_test']) && $r['status'] !== 'active';
                    $cell = '<input type="checkbox" name="jtis[]" form="archive-selected" value="' . $this->e($r['jti']) . '" aria-label="' . $this->e($this->t('selected')) . ' ' . $this->e($r['jti']) . '"' . ($allowed?'': ' disabled') . '>';
                } elseif ($col === 'is_test') {
                    $cell = $val ? '<span class="pill test">'.$this->e($this->t('test')).'</span>' : $this->e($this->t('none'));
                } elseif (in_array($col, ['created_at', 'issued', 'expires', 'mailed_at', 'archived_at', 'status_at', 'body_expires_at', 'refunded_at', 'first_purchase', 'last_purchase', 'paid_at', 'current_period_end', 'last_seen_at', 'first_seen_at', 'at', 'received_at', 'last_at', 'resolved_at', 'last_sent'], true)) {
                    $cell = $this->time($val);
                } elseif ($col === 'status' && in_array($val, ['active','superseded','revoked','refunded','accepted','delivered','bounced','complained','failed','delayed','sending','done','past_due','canceled'], true)) {
                    $cell = '<span class="pill ' . $this->e($val) . '" title="' . $this->e($val) . '">' . $this->e($this->t($val)) . '</span>';
                } elseif (in_array($col,['jti','order_id','subscription_id','checkout_id','customer_id','product_id','ref_jti','ref_order','webhook_id'],true) && is_string($val) && $val !== '') {
                    $cell = '<code title="'.$this->e($val).'">'.$this->e(Mask::id($val)).'</code> <button type="button" class="copy-id" data-copy="'.$this->e($val).'" aria-label="'.$this->e($this->t('copy')).'">⧉</button>';
                } elseif (is_numeric($val) && isset($r['currency']) && in_array($col,self::moneyFields(),true)) {
                    $cell=$this->e(self::money((int)$val,(string)$r['currency']));
                } elseif (is_array($val)) {
                    $cell=$col==='net'?$this->currencyTotals($val):$this->facts($val);
                } elseif (is_bool($val)) {
                    $cell=$this->e($this->t($val?'yes':'no'));
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
    public static function moneyFields(): array
    {
        return ['net','net_amount','net_month','net_previous','refund_net','refunded_amount','refunded_tax_amount','amount','gross_month','tax_month','total_amount','subtotal_amount','discount_amount','mrr','arr','refunds_30d','one_time_month'];
    }

    private function currencyTotals(array $totals): string
    {
        $out=[];foreach ($totals as $currency=>$amount) { $out[]=$this->e(self::money((int)$amount,(string)$currency)); }
        return implode('<br>',$out);
    }

    public function facts(array $facts): string
    {
        if ($facts===[]) { return '<p class="empty">'.$this->e($this->t('empty_system')).'</p>'; }
        if (array_is_list($facts)) {
            if (is_array($facts[0])) { return $this->table($facts); }
            return $this->e(implode(', ',array_map(fn($v):string=>$this->t((string)$v),$facts)));
        }
        $out='<dl>';
        foreach ($facts as $key=>$value) {
            $out.='<dt>'.$this->e($this->t((string)$key)).'</dt><dd>';
            if (is_array($value)) { $out.=$this->facts($value); }
            elseif (is_bool($value)) { $out.=$this->e($this->t($value?'yes':'no')); }
            elseif (in_array($key,['at','last_ok_at','oldest_at','last_sent','last_at'],true)) { $out.=$this->time($value); }
            else { $out.=$this->e($value??$this->t('none')); }
            $out.='</dd>';
        }
        return $out.'</dl>';
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
