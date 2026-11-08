<?php

declare(strict_types=1);

namespace FluxFiles\LicenseServer\Client;

/** Counts-only contract shared by the unprivileged worker and the administrative API. */
final class Ingest
{
    public static function day(string $day): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $day && $day >= '2000-01-01' && $day <= gmdate('Y-m-d');
    }

    public static function rows(string $type, string $day, array $rows): array
    {
        if (!self::day($day) || count($rows) > 1000 || !array_is_list($rows)) { throw new \InvalidArgumentException('invalid_input'); }
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new \InvalidArgumentException('invalid_input'); }
            $fields = match ($type) {
                'public_counters' => ['source', 'subject', 'metric', 'value'],
                'downloads_daily' => ['module', 'version', 'n'],
                'demo_daily' => ['sandboxes_created', 'sandbox_limit_hits', 'page_limit_hits', 'sandboxes_live', 'uploads_bytes'],
                default => throw new \InvalidArgumentException('invalid_input'),
            };
            $keys = array_keys($row); sort($keys); sort($fields);
            if ($keys !== $fields) { throw new \InvalidArgumentException('invalid_input'); }
            foreach ($row as $name => $value) {
                if (in_array($name, ['n', 'value', 'sandboxes_created', 'sandbox_limit_hits', 'page_limit_hits', 'sandboxes_live', 'uploads_bytes'], true)) {
                    if (!is_int($value) || $value < 0 || $value > 9007199254740991) { throw new \InvalidArgumentException('invalid_input'); }
                } elseif (!is_string($value) || strlen($value) > 200) { throw new \InvalidArgumentException('invalid_input'); }
            }
            if ($type === 'downloads_daily' && (!preg_match('/^[a-z0-9-]{1,64}$/D', $row['module']) || !preg_match('/^(?:|[0-9]{1,4}(?:\.[0-9]{1,4}){1,3}(?:[.+-][0-9A-Za-z.-]{1,20})?)$/D', $row['version']))) { throw new \InvalidArgumentException('invalid_input'); }
            if ($type === 'public_counters') {
                $metrics = ['wporg' => ['active_installs', 'downloaded'], 'github' => ['stargazers_count', 'forks_count'], 'packagist' => ['downloads.total', 'downloads.monthly'], 'npm' => ['downloads']];
                if (!in_array($row['metric'], $metrics[$row['source']] ?? [], true) || !preg_match('~^@?[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)?$~D', $row['subject'])) { throw new \InvalidArgumentException('invalid_input'); }
            }
        }
        if ($type === 'demo_daily' && count($rows) !== 1) { throw new \InvalidArgumentException('invalid_input'); }
        return $rows;
    }
}
