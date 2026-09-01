<?php

declare(strict_types=1);

namespace FluxFiles\Db;

class MigrationRunner
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    private function ensureTrackingTable(): void
    {
        $pdo = $this->db->pdo();
        $dialect = $this->db->dialect();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS _fluxfiles_migrations (' .
            'filename VARCHAR(255) PRIMARY KEY, ' .
            'applied_at BIGINT NOT NULL' .
            ')'
        );
    }

    private function applied(): array
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query('SELECT filename FROM _fluxfiles_migrations')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['filename']] = true;
        }
        return $out;
    }

    /**
     * Render engine-specific tokens in a migration file's SQL.
     */
    private function render(string $sql): string
    {
        $dialect = $this->db->dialect();
        $replacements = [
            '{{AUTOINCREMENT}}' => $dialect->autoIncrementDdl(),
            '{{JSON}}' => $dialect->jsonType(),
            '{{PATH_IDX}}' => $dialect->pathIndexColumnExpr('path'),
            '{{BINCOLLATE}}' => $dialect->binaryCollationSuffix(),
        ];
        return strtr($sql, $replacements);
    }

    /**
     * Apply every *.sql file in $dir not yet recorded in the tracking table, in
     * filename order. Returns the list of filenames actually applied this run.
     *
     * @return string[]
     */
    public function migrate(string $dir): array
    {
        $this->ensureTrackingTable();
        $already = $this->applied();

        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $pdo = $this->db->pdo();
        $appliedNow = [];

        foreach ($files as $path) {
            $filename = basename($path);
            if (isset($already[$filename])) {
                continue;
            }

            $sql = $this->render((string) file_get_contents($path));
            $statements = array_filter(array_map('trim', explode(';', $sql)), static fn($s) => $s !== '');

            $this->db->beginExclusive();
            try {
                foreach ($statements as $statement) {
                    $pdo->exec($statement);
                }
                $stmt = $pdo->prepare('INSERT INTO _fluxfiles_migrations (filename, applied_at) VALUES (?, ?)');
                $stmt->execute([$filename, time()]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }

            $appliedNow[] = $filename;
        }

        return $appliedNow;
    }
}
