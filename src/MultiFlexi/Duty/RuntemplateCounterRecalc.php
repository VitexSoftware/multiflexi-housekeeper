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
 * Recalculates RunTemplate success/failure job counters from actual job records.
 *
 * The successfull_jobs_count and failed_jobs_count fields can drift over time
 * when jobs are deleted (e.g. via data retention) without decrementing the
 * counters, or when orphaned jobs are removed. This duty corrects any drift.
 * Runs after DataRetentionCleanup so the counters reflect the surviving rows.
 */
class RuntemplateCounterRecalc extends AbstractDuty
{
    protected string $name = 'RuntemplateCounterRecalc';

    public function perform(): array
    {
        $db = new \Ease\SQL\Engine();
        $pdo = $db->getPdo();
        $errors = [];
        $affected = 0;

        try {
            if ($this->dryRun) {
                // Report drift without updating
                $drifted = $pdo->query(
                    'SELECT rt.id,
                            rt.successfull_jobs_count AS stored_success,
                            rt.failed_jobs_count      AS stored_failed,
                            (SELECT COUNT(*) FROM job WHERE runtemplate_id = rt.id AND exitcode = 0)                              AS actual_success,
                            (SELECT COUNT(*) FROM job WHERE runtemplate_id = rt.id AND exitcode IS NOT NULL AND exitcode != 0)    AS actual_failed
                     FROM runtemplate rt
                     HAVING stored_success != actual_success OR stored_failed != actual_failed',
                )->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($drifted as $row) {
                    $this->log(sprintf(
                        'RunTemplate #%d counters drifted: success %d→%d failed %d→%d',
                        $row['id'],
                        $row['stored_success'],
                        $row['actual_success'],
                        $row['stored_failed'],
                        $row['actual_failed'],
                    ), 'warning');
                    ++$affected;
                }
            } else {
                $sql = 'UPDATE runtemplate rt
                        SET successfull_jobs_count = (
                                SELECT COUNT(*) FROM job
                                WHERE runtemplate_id = rt.id AND exitcode = 0
                            ),
                            failed_jobs_count = (
                                SELECT COUNT(*) FROM job
                                WHERE runtemplate_id = rt.id AND exitcode IS NOT NULL AND exitcode != 0
                            )';
                $affected = (int) $pdo->exec($sql);
                $this->log("Recalculated job counters for {$affected} runtemplates");
            }
        } catch (\Throwable $e) {
            $errors[] = 'Counter recalculation: '.$e->getMessage();
        }

        return $this->result($affected, $errors);
    }
}
