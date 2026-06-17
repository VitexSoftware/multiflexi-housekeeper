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
 * Removes orphaned temporary result files from disk.
 *
 * MultiFlexi jobs write result files to MULTIFLEXI_TMP during execution.
 * After artifacts are stored in the database the files are supposed to be
 * deleted (Job::cleanUp() TODO), but they are currently left behind. This
 * duty removes files older than MULTIFLEXI_HOUSEKEEPER_TMP_AGE_HOURS hours
 * that are not referenced by any currently-running job.
 */
class DiskTempFileCleanup extends AbstractDuty
{
    protected string $name = 'DiskTempFileCleanup';

    public function perform(): array
    {
        new \MultiFlexi\Defaults();
        $tmpDir = \MultiFlexi\Defaults::$MULTIFLEXI_TMP;

        if (!is_dir($tmpDir)) {
            $this->log("Temp directory {$tmpDir} does not exist — skipping", 'debug');

            return $this->result(0, [], true);
        }

        $maxAgeHours = (int) \Ease\Shared::cfg('MULTIFLEXI_HOUSEKEEPER_TMP_AGE_HOURS', 24);
        $cutoff = time() - ($maxAgeHours * 3600);
        $affected = 0;
        $errors = [];

        // Collect RESULT_FILE paths referenced by running jobs (exitcode IS NULL, begin IS NOT NULL)
        $activeResultFiles = $this->getActiveResultFiles();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if ($file->getMTime() >= $cutoff) {
                continue;
            }

            $path = $file->getRealPath();

            if (\in_array($path, $activeResultFiles, true)) {
                $this->log("Skipping {$path} — referenced by an active job", 'debug');

                continue;
            }

            $this->log("Orphaned temp file: {$path} (age: ".round((time() - $file->getMTime()) / 3600, 1).' h)');

            if (!$this->dryRun) {
                if (@unlink($path)) {
                    ++$affected;
                } else {
                    $errors[] = "Failed to delete: {$path}";
                }
            } else {
                ++$affected;
            }
        }

        return $this->result($affected, $errors);
    }

    /**
     * @return string[]
     */
    private function getActiveResultFiles(): array
    {
        $paths = [];

        try {
            $jobber = new \MultiFlexi\Job();
            $running = $jobber->listingQuery()
                ->where('begin IS NOT NULL')
                ->where('exitcode IS NULL')
                ->select(['env'])
                ->fetchAll();

            foreach ($running as $row) {
                if (empty($row['env'])) {
                    continue;
                }

                $env = json_decode((string) $row['env'], true);

                if (\is_array($env) && isset($env['RESULT_FILE'])) {
                    $resolved = realpath((string) $env['RESULT_FILE']);

                    if ($resolved !== false) {
                        $paths[] = $resolved;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log('Could not query running jobs: '.$e->getMessage(), 'warning');
        }

        return $paths;
    }
}
