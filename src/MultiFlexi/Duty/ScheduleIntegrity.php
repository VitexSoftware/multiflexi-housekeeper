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
 * Validates and repairs RunTemplate scheduling state.
 *
 * Calls Scheduler::initializeScheduling() to reset next_schedule for
 * runtemplates whose expected queue entry is missing. Also detects runtemplates
 * whose next_schedule is in the past by more than one interval period without
 * a queue entry, and resets them so the scheduler re-picks them up.
 */
class ScheduleIntegrity extends AbstractDuty
{
    protected string $name = 'ScheduleIntegrity';

    public function perform(): array
    {
        $scheduler = new \MultiFlexi\Scheduler();
        $errors = [];

        if (!$this->dryRun) {
            try {
                $scheduler->initializeScheduling();
            } catch (\Throwable $e) {
                $errors[] = 'initializeScheduling: '.$e->getMessage();
            }
        } else {
            $this->log('Would call Scheduler::initializeScheduling()');
        }

        // Additional pass: find runtemplates with next_schedule significantly in the past
        // and no schedule queue entry — they are stuck and need a reset.
        $rtQuery = new \MultiFlexi\RunTemplate();
        $stuckRts = $rtQuery->listingQuery()
            ->where(['active' => true])
            ->where('interv != ?', ['n'])
            ->where('next_schedule IS NOT NULL')
            ->where('next_schedule < NOW() - INTERVAL 2 HOUR')
            ->where('id NOT IN (SELECT runtemplate_id FROM job WHERE exitcode IS NULL AND schedule IS NOT NULL)')
            ->fetchAll();

        $affected = 0;

        foreach ($stuckRts as $row) {
            $this->log(sprintf(
                'RunTemplate #%d next_schedule=%s is stale with no pending job — resetting next_schedule',
                $row['id'],
                $row['next_schedule'],
            ), 'warning');

            if (!$this->dryRun) {
                try {
                    $rtQuery->updateToSQL(['next_schedule' => null], ['id' => $row['id']]);
                } catch (\Throwable $e) {
                    $errors[] = 'Reset next_schedule for RT#'.$row['id'].': '.$e->getMessage();
                }
            }

            ++$affected;
        }

        return $this->result($affected, $errors);
    }
}
