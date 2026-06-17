<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MultiFlexi\Duty;

/**
 * Enforces GDPR data retention policies.
 *
 * Delegates to MultiFlexi\DataRetention\RetentionService when available
 * (installed alongside multiflexi-web). Falls back to a minimal built-in
 * pass that reads data_retention_policies and hard-deletes or soft-deletes
 * expired rows from known tables.
 */
class DataRetentionCleanup extends AbstractDuty
{
    protected string $name = 'DataRetentionCleanup';

    public function perform(): array
    {
        if (class_exists('\MultiFlexi\DataRetention\RetentionService')) {
            return $this->runViaRetentionService();
        }

        return $this->runBuiltinPass();
    }

    private function runViaRetentionService(): array
    {
        $this->log('Using RetentionService for data retention cleanup');
        $errors = [];
        $affected = 0;

        try {
            /** @var \MultiFlexi\DataRetention\RetentionService $svc */
            $svc = new \MultiFlexi\DataRetention\RetentionService();
            $result = $svc->processScheduledCleanup($this->dryRun);
            $affected = \is_array($result) ? (int) ($result['total_deleted'] ?? 0) : 0;
        } catch (\Throwable $e) {
            $errors[] = 'RetentionService: '.$e->getMessage();
            $this->log('RetentionService failed, falling back to built-in pass: '.$e->getMessage(), 'warning');

            return $this->runBuiltinPass();
        }

        return $this->result($affected, $errors);
    }

    /**
     * Minimal built-in retention pass used when RetentionService is not installed.
     * Reads enabled policies from data_retention_policies and applies hard_delete
     * or soft_delete based on deletion_action.
     */
    private function runBuiltinPass(): array
    {
        $db = new \Ease\SQL\Engine();
        $pdo = $db->getPdo();
        $affected = 0;
        $errors = [];

        // Check if data_retention_policies table exists
        try {
            $policies = $pdo->query("SELECT * FROM data_retention_policies WHERE enabled = 1")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $this->log('data_retention_policies table not available: '.$e->getMessage(), 'debug');

            return $this->result(0, [], true);
        }

        foreach ($policies as $policy) {
            $table = $policy['table_name'] ?? '';
            $action = $policy['deletion_action'] ?? '';
            $retentionDays = (int) ($policy['retention_days'] ?? 365);

            if (empty($table)) {
                continue;
            }

            $this->log(sprintf(
                'Policy "%s": table=%s action=%s retention=%d days',
                $policy['policy_name'] ?? '?',
                $table,
                $action,
                $retentionDays,
            ), 'debug');

            try {
                $affected += $this->applyPolicy($pdo, $table, $action, $retentionDays);
            } catch (\Throwable $e) {
                $errors[] = sprintf('Policy for %s: %s', $table, $e->getMessage());
            }
        }

        return $this->result($affected, $errors);
    }

    private function applyPolicy(\PDO $pdo, string $table, string $action, int $retentionDays): int
    {
        $affected = 0;

        switch ($action) {
            case 'hard_delete':
                if ($this->dryRun) {
                    $count = (int) $pdo->query(
                        "SELECT COUNT(*) FROM {$table} WHERE retention_until IS NOT NULL AND retention_until < NOW()",
                    )->fetchColumn();
                    $this->log("Would hard-delete {$count} rows from {$table}");
                    $affected += $count;
                } else {
                    $affected += (int) $pdo->exec(
                        "DELETE FROM {$table} WHERE retention_until IS NOT NULL AND retention_until < NOW()",
                    );
                    $this->log("Hard-deleted {$affected} rows from {$table}");
                }

                break;

            case 'soft_delete':
                if ($this->dryRun) {
                    $count = (int) $pdo->query(
                        "SELECT COUNT(*) FROM {$table} WHERE retention_until IS NOT NULL AND retention_until < NOW() AND (marked_for_deletion IS NULL OR marked_for_deletion = 0)",
                    )->fetchColumn();
                    $this->log("Would soft-delete {$count} rows from {$table}");
                    $affected += $count;
                } else {
                    $affected += (int) $pdo->exec(
                        "UPDATE {$table} SET marked_for_deletion = 1 WHERE retention_until IS NOT NULL AND retention_until < NOW() AND (marked_for_deletion IS NULL OR marked_for_deletion = 0)",
                    );
                    $this->log("Soft-deleted (marked) {$affected} rows in {$table}");
                }

                break;

            default:
                $this->log("Skipping unsupported retention action '{$action}' for table {$table}", 'debug');

                break;
        }

        return $affected;
    }
}
