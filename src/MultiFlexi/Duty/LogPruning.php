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
 * Keeps the log table lean by removing the oldest rows beyond a threshold.
 *
 * Uses the same DELETE … NOT IN (SELECT … LIMIT N) strategy as the CLI
 * prune command but without the Symfony Console overhead. Runs after all
 * other duties so their log messages survive the prune.
 */
class LogPruning extends AbstractDuty
{
    protected string $name = 'LogPruning';

    public function perform(): array
    {
        $keep = (int) \Ease\Shared::cfg('MULTIFLEXI_HOUSEKEEPER_LOG_KEEP', 100000);
        $db = new \Ease\SQL\Engine();
        $pdo = $db->getPdo();
        $errors = [];
        $affected = 0;

        try {
            $total = (int) $pdo->query('SELECT COUNT(*) FROM log')->fetchColumn();

            if ($total <= $keep) {
                $this->log("Log table has {$total} rows — below threshold {$keep}, nothing to prune", 'debug');

                return $this->result();
            }

            $excess = $total - $keep;
            $this->log("Log table has {$total} rows; pruning {$excess} oldest (keeping {$keep})");

            if (!$this->dryRun) {
                $sql = 'DELETE FROM log WHERE id NOT IN (SELECT id FROM (SELECT id FROM log ORDER BY id DESC LIMIT ?) AS t)';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$keep]);
                $affected = $stmt->rowCount();
                $this->log("Pruned {$affected} rows from log table");
            } else {
                $affected = $excess;
            }
        } catch (\Throwable $e) {
            $errors[] = 'Log pruning: '.$e->getMessage();
        }

        return $this->result($affected, $errors);
    }
}
