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

require_once '../vendor/autoload.php';

\define('APP_NAME', 'MultiFlexi HouseKeeper');
\Ease\Shared::init(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'], '../.env');

$daemonize = (bool) \Ease\Shared::cfg('MULTIFLEXI_DAEMONIZE', false);
$dryRun = strtolower((string) \Ease\Shared::cfg('MULTIFLEXI_HOUSEKEEPER_DRY_RUN', 'false')) === 'true';
$cyclePause = (int) \Ease\Shared::cfg('MULTIFLEXI_HOUSEKEEPER_CYCLE_PAUSE', 3600);

$loggers = ['syslog', '\MultiFlexi\LogToSQL'];

if (\Ease\Shared::cfg('ZABBIX_SERVER') && \Ease\Shared::cfg('ZABBIX_HOST') && class_exists('\MultiFlexi\LogToZabbix')) {
    $loggers[] = '\MultiFlexi\LogToZabbix';
}

if (strtolower(\Ease\Shared::cfg('APP_DEBUG', 'false')) === 'true') {
    $loggers[] = 'console';
}

\define('EASE_LOGGER', implode('|', $loggers));
new \MultiFlexi\Defaults();
\Ease\Shared::user(new \MultiFlexi\UnixUser());

function waitForDatabase(): void
{
    $try = 0;

    while (true) {
        try {
            $testScheduler = new Scheduler();
            $testScheduler->getCurrentJobs();
            unset($testScheduler);

            break;
        } catch (\Throwable $e) {
            if ($try++ < 6) {
                error_log('Database unavailable: '.$e->getMessage());
                sleep(10 * $try);
            } else {
                throw new \RuntimeException('Database unavailable: '.$e->getMessage());
            }
        }
    }
}

$pidFile = sys_get_temp_dir().'/multiflexi-housekeeper.pid';
$lockFp = fopen($pidFile, 'c');

if ($lockFp === false || !flock($lockFp, \LOCK_EX | \LOCK_NB)) {
    error_log('Another multiflexi-housekeeper instance is already running. Exiting.');

    exit(1);
}

ftruncate($lockFp, 0);
fwrite($lockFp, (string) getmypid());
fflush($lockFp);

waitForDatabase();

$housekeeper = new Housekeeper($dryRun);
$housekeeper->logBanner(sprintf(
    _('MultiFlexi HouseKeeper %s started%s. Core Libs: %s'),
    \Ease\Shared::appVersion(),
    $dryRun ? ' [DRY-RUN]' : '',
    \Composer\InstalledVersions::getPrettyVersion('vitexsoftware/multiflexi-core'),
));

do {
    try {
        $housekeeper->runAllDuties();
    } catch (\Throwable $e) {
        error_log('HouseKeeper fatal error: '.$e->getMessage());
    }

    $housekeeper->cleanSatatusMessages();

    if ($daemonize) {
        sleep($cyclePause);
    }
} while ($daemonize);

$housekeeper->logBanner('MultiFlexi HouseKeeper ended');
