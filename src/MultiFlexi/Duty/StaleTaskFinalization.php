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
 * Finalizes tasks whose scheduling window has expired.
 *
 * Marks open tasks with zero attempts as 'missed' and open/running tasks
 * with at least one attempt as 'failed'. Provides a safety net for when
 * the scheduler daemon is down and cannot call Task::finalizeExpired() itself.
 */
class StaleTaskFinalization extends AbstractDuty
{
    protected string $name = 'StaleTaskFinalization';

    public function perform(): array
    {
        $task = new \MultiFlexi\Task();

        $stale = $task->listingQuery()
            ->where('state IN (?, ?)', [\MultiFlexi\Task::STATE_OPEN, \MultiFlexi\Task::STATE_RUNNING])
            ->where('window_end < NOW()')
            ->fetchAll();

        if (empty($stale)) {
            $this->log('No stale tasks found.', 'debug');

            return $this->result();
        }

        $affected = 0;

        foreach ($stale as $row) {
            $state = (int) $row['attempts'] === 0
                ? \MultiFlexi\Task::STATE_MISSED
                : \MultiFlexi\Task::STATE_FAILED;

            $this->log(sprintf(
                'Task #%d (runtemplate #%d) window expired at %s — marking %s',
                $row['id'],
                $row['runtemplate_id'],
                $row['window_end'],
                $state,
            ));

            if (!$this->dryRun) {
                $t = new \MultiFlexi\Task((int) $row['id']);

                if ($state === \MultiFlexi\Task::STATE_MISSED) {
                    $t->markMissed();
                } else {
                    $t->markFailed();
                }
            }

            ++$affected;
        }

        return $this->result($affected);
    }
}
