<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
require_once __DIR__ . '/LicenseStore.php';
require_once __DIR__ . '/PolarWebhook.php';
require_once __DIR__ . '/client/Config.php';

use FluxFiles\LicenseServer\LicenseStore;
use FluxFiles\LicenseServer\PolarWebhook;
use FluxFiles\LicenseServer\Client\Config;

try {
    $options = getopt('', ['apply', 'all', 'sandbox', 'token-file:', 'api-base:', 'limit:', 'delay-ms:', 'backoff-ms:']);
    $apply = isset($options['apply']);
    $sandbox = isset($options['sandbox']) || Config::sandbox();
    $base = rtrim((string) ($options['api-base'] ?? ($sandbox?'https://sandbox-api.polar.sh/v1': 'https://api.polar.sh/v1')), '/');
    if (!in_array($base, ['https://api.polar.sh/v1', 'https://sandbox-api.polar.sh/v1'], true)) {
        $base = Config::loopback($base);
    }
    if (isset($options['token-file'])) {
        $token = trim((string) @file_get_contents((string) $options['token-file']));
    } else {
        $token = Config::get($sandbox?'POLAR_TOKEN_SANDBOX': 'POLAR_TOKEN');
    }
    if ($token === '') {
        throw new RuntimeException('Provide a one-off Polar token through env or --token-file.');
    }
    $path = Config::get('FLUXFILES_LICENSE_DB', __DIR__ . '/data/licenses.sqlite');
    // Dry-run opens an in-memory copy: not even migrations touch the source DB.
    if ($apply) {
        $store = new LicenseStore($path);
        $db = $store->pdo();
    } else {
        if (!is_file($path)) {
            throw new RuntimeException('Database not found');
        }
        $source = new PDO('sqlite:file:' . $path . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $file = sys_get_temp_dir() . '/ff-orders-' . bin2hex(random_bytes(8)) . '.sqlite';
        $source->prepare('VACUUM INTO ?')->execute([$file]);
        $source = null;
        register_shutdown_function(static function() use($file): void {
            foreach ([$file, $file . '-wal', $file . '-shm'] as $p) {
                @unlink($p);
            }
        });
        $store = new LicenseStore($file);
        $db = $store->pdo();
    }
    $store->setActor('cli');
    $limit = max(1, min(100000, (int) ($options['limit'] ?? 5000)));
    $delay = max(0, min(10000, (int) ($options['delay-ms'] ?? 150)));
    $fetch = static function(string $path) use($base, $token, $options): array {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $ch = curl_init($base . $path);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json']]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 429 && $attempt < 4) {
                usleep(min(30000, (int) ($options['backoff-ms'] ?? 1000) * (2 ** $attempt)) * 1000);
                continue;
            }
            $data = is_string($raw)?json_decode($raw, true): null;
            if ($status !== 200 || !is_array($data)) {
                throw new RuntimeException('Polar request failed (HTTP ' . $status . ').');
            }
            return $data;
        }
        throw new RuntimeException('Polar retry budget exhausted.');
    };
    $map = json_decode(Config::get('FLUXFILES_POLAR_PLAN_MAP', '{}'), true)?: [];
    $processed = 0;
    $record = static function(array $order) use($store, $db, $map, $apply, &$processed): void {
        $type = ($order['status'] ?? '') === 'refunded' || (int) ($order['refunded_amount'] ?? 0) > 0?'order.refunded': 'order.paid';
        $facts = PolarWebhook::orderFacts(['type' => $type, 'data' => $order]);
        if ($facts === null) {
            return;
        }
        if ($type === 'order.refunded') {
            $facts['paid_at'] = isset($order['created_at'])?strtotime((string) $order['created_at']): null;
        }
        $r = $store->findByOrder('polar', $facts['order_id']);
        $facts['jti'] = $r['jti'] ?? null;
        $facts['plan'] = $r['plan'] ?? $map[$facts['product_id'] ?? ''] ?? null;
        if ($apply) {
            $store->upsertOrder($facts);
        }
        $processed++;
    };
    if (isset($options['all'])) {
        for ($page = 1; $processed < $limit; $page++) {
            $data = $fetch('/orders/?page=' . $page . '&limit=100');
            $items = $data['items'] ?? [];
            foreach ($items as $order) {
                if ($processed >= $limit) {
                    break;
                }
                if (in_array($order['status'] ?? '', ['paid', 'partially_refunded', 'refunded'], true)) {
                    $record($order);
                }
            }
            if ($items === [] || $page >= (int) ($data['pagination']['max_page'] ?? $page)) {
                break;
            }
            if ($delay) {
                usleep($delay * 1000);
            }
        }
    } else {
        $q = $db->prepare("SELECT DISTINCT l.order_id FROM licenses l LEFT JOIN orders o ON o.order_id=l.order_id WHERE l.gateway='polar' AND l.order_id!='' AND o.order_id IS NULL LIMIT ?");
        $q->bindValue(1, $limit, PDO::PARAM_INT);
        $q->execute();
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $record($fetch('/orders/' . rawurlencode((string) $id)));
            if ($delay) {
                usleep($delay * 1000);
            }
        }
    }
    echo ($apply?'Applied': 'Dry run') . ': ' . $processed . " order(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Order backfill failed; check input and Polar availability.\n");
    exit(1);
}
