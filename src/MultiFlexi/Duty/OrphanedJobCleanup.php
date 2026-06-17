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
 * Removes orphaned jobs and broken schedule queue entries.
 *
 * Delegates to Scheduler::cleanupOrphanedJobs() and purgeBrokenQueueRecords()
 * for structural cleanup, then adds a housekeeper-specific pass that removes
 * jobs that were never started and have no queue entry for longer than the
 * configured stale-job age threshold.
 */
class OrphanedJobCleanup extends AbstractDuty
{
    protected string $name = 'OrphanedJobCleanup';

    public function perform(): array
    {
        $scheduler = new \MultiFlexi\Scheduler();
        $affected = 0;
        $errors = [];

        if (!$this->dryRun) {
            try {
                $scheduler->cleanupOrphanedJobs();
            } catch (\Throwable $e) {
                $errors[] = 'cleanupOrphanedJobs: '.$e->getMessage();
            }

            try {
                $scheduler->purgeBrokenQueueRecords();
            } catch (\Throwable $e) {
                $errors[] = 'purgeBrokenQueueRecords: '.$e->getMessage();
            }
        } else {
            $this->log('Would call Scheduler::cleanupOrphanedJobs() and purgeBrokenQueueRecords()');
        }

        // Additional pass: jobs with begin=NULL, exitcode=NULL, no schedule entry,
        // older than MULTIFLEXI_HOUSEKEEPER_STALE_JOB_AGE minutes.
        $staleMinutes = (int) \Ease\Shared::cfg('MULTIFLEXI_HOUSEKEEPER_STALE_JOB_AGE', 60);
        $jobber = new \MultiFlexi\Job();

        $ghostJobs = $jobber->listingQuery()
            ->where('begin IS NULL')
            ->where('exitcode IS NULL')
            ->where('id NOT IN (SELECT job FROM schedule WHERE job IS NOT NULL)')
            ->where('schedule < NOW() - INTERVAL ? MINUTE', [$staleMinutes])
            ->fetchAll();

        foreach ($ghostJobs as $row) {
            $this->log(sprintf(
                'Ghost job #%d (runtemplate #%d) unstarted for %d min with no queue entry — removing',
                $row['id'],
                $row['runtemplate_id'],
                $staleMinutes,
            ));

            if (!$this->dryRun) {
                try {
                    $jobber->deleteFromSQL(['id' => $row['id']]);
                } catch (\Throwable $e) {
                    $errors[] = 'Delete ghost job #'.$row['id'].': '.$e->getMessage();
                }
            }

            ++$affected;
        }

        return $this->result($affected, $errors);
    }
}
