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
 * Base class for all HouseKeeper duties.
 *
 * Each duty performs one category of periodic maintenance and reports
 * how many records it affected plus any errors encountered.
 */
abstract class AbstractDuty extends \Ease\Sand
{
    protected bool $dryRun;
    protected string $name = 'Unknown';

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
        parent::__construct();
    }

    /**
     * Execute the duty.
     *
     * @return array{affected: int, errors: string[], skipped: bool}
     */
    abstract public function perform(): array;

    public function getName(): string
    {
        return $this->name;
    }

    protected function log(string $message, string $level = 'info'): void
    {
        $prefix = $this->dryRun ? '[DRY-RUN] ' : '';
        $this->addStatusMessage($prefix.$message, $level);
    }

    /**
     * @return array{affected: int, errors: string[], skipped: bool}
     */
    protected function result(int $affected = 0, array $errors = [], bool $skipped = false): array
    {
        return ['affected' => $affected, 'errors' => $errors, 'skipped' => $skipped];
    }
}
