<?php

declare(strict_types=1);

namespace Meetingax\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Kör numrerade SQL-filer i database/migrations en gång var.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $applied = [];
        $done = $this->appliedVersions();
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $version = basename($file);
            if (isset($done[$version])) {
                continue;
            }
            foreach ($this->statements((string) file_get_contents($file)) as $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (PDOException $e) {
                    throw new RuntimeException(
                        'Migration ' . $version . ' misslyckades: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }
            }
            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
            $stmt->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** @return array<string, true> */
    private function appliedVersions(): array
    {
        try {
            $rows = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll();
        } catch (PDOException) {
            return [];
        }
        $done = [];
        foreach ($rows as $row) {
            $done[(string) $row['version']] = true;
        }
        return $done;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
        $statements = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $statements[] = $part;
            }
        }
        return $statements;
    }
}
