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

namespace MultiFlexi;

use MultiFlexi\Duty\AbstractDuty;

/**
 * Orchestrates all HouseKeeper duties in the correct order.
 *
 * Each duty is independent; a failure in one does not abort the rest.
 * The MULTIFLEXI_HOUSEKEEPER_SKIP_DUTIES env var accepts a comma-separated
 * list of duty class names (short name, without namespace) to skip.
 */
class Housekeeper extends Engine
{
    /** @var AbstractDuty[] */
    private array $duties = [];

    private array $skipDuties = [];

    public function __construct(bool $dryRun = false)
    {
        $this->myTable = '';
        parent::__construct();

        $skipEnv = (string) \Ease\Shared::cfg('MULTIFLEXI_HOUSEKEEPER_SKIP_DUTIES', '');

        if ($skipEnv !== '') {
            $this->skipDuties = array_map('trim', explode(',', $skipEnv));
        }

        // Duties registered in execution order — see plan for rationale
        $this->duties = [
            new \MultiFlexi\Duty\StaleTaskFinalization($dryRun),
            new \MultiFlexi\Duty\OrphanedJobCleanup($dryRun),
            new \MultiFlexi\Duty\ScheduleIntegrity($dryRun),
            new \MultiFlexi\Duty\DataRetentionCleanup($dryRun),
            new \MultiFlexi\Duty\DiskTempFileCleanup($dryRun),
            new \MultiFlexi\Duty\LogPruning($dryRun),
            new \MultiFlexi\Duty\RuntemplateCounterRecalc($dryRun),
            new \MultiFlexi\Duty\CredentialHealthCheck($dryRun),
        ];
    }

    public function runAllDuties(): void
    {
        $cycleStart = microtime(true);
        $this->addStatusMessage(_('Housekeeper cycle started'), 'info');

        foreach ($this->duties as $duty) {
            $shortName = \Ease\Functions::baseClassName($duty);

            if (\in_array($shortName, $this->skipDuties, true)) {
                $this->addStatusMessage(sprintf(_('Duty [%s] skipped via MULTIFLEXI_HOUSEKEEPER_SKIP_DUTIES'), $shortName), 'debug');

                continue;
            }

            $dutyStart = microtime(true);

            try {
                $result = $duty->perform();
                $elapsed = round(microtime(true) - $dutyStart, 3);

                $level = empty($result['errors']) ? 'success' : 'warning';
                $this->addStatusMessage(sprintf(
                    _('Duty [%s] done in %.3fs — affected: %d, errors: %d%s'),
                    $duty->getName(),
                    $elapsed,
                    $result['affected'],
                    \count($result['errors']),
                    $result['skipped'] ? ' (skipped)' : '',
                ), $level);

                foreach ($result['errors'] as $err) {
                    $this->addStatusMessage('  '.$err, 'error');
                }
            } catch (\Throwable $e) {
                $this->addStatusMessage(sprintf(
                    _('Duty [%s] threw exception: %s'),
                    $duty->getName(),
                    $e->getMessage(),
                ), 'error');
            }
        }

        $total = round(microtime(true) - $cycleStart, 3);
        $this->addStatusMessage(sprintf(_('Housekeeper cycle completed in %.3fs'), $total), 'info');
    }
}
