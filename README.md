<div align="center">
  <img src="eu.multiflexi.multiflexi_housekeeper.svg" alt="MultiFlexi HouseKeeper" width="160"/>
  <h1>multiflexi-housekeeper</h1>
  <p>Periodic maintenance daemon for the <a href="https://multiflexi.eu/">MultiFlexi</a> platform</p>

  [![GitHub release](https://img.shields.io/github/v/release/VitexSoftware/multiflexi-housekeeper?label=release)](https://github.com/VitexSoftware/multiflexi-housekeeper/releases)
  [![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
  [![PHP](https://img.shields.io/badge/php-%3E%3D8.0-8892BF.svg)](https://www.php.net/)
</div>

---

## Overview

**MultiFlexi HouseKeeper** keeps the MultiFlexi platform running flawlessly
by performing eight maintenance duties every hour via a systemd timer.
It is a companion to the three continuously-running daemons
(`multiflexi-scheduler`, `multiflexi-executor`, `multiflexi-eventor`),
handling the periodic work they cannot do themselves:

- finalizing stale Tasks that the scheduler missed while it was down
- removing orphaned jobs and broken queue records
- resetting stuck RunTemplate schedules
- enforcing GDPR data retention policies
- cleaning up orphaned disk temp files
- pruning the log table to a configurable row count
- correcting drifted RunTemplate job counters after retention deletes
- reporting Misconfigured credentials before they block a job at runtime

All duties run independently — a failure in one does not abort the rest.

---

## Architecture

```
multiflexi-housekeeper.timer  (OnCalendar=hourly, RandomizedDelaySec=300)
        │
        ▼
multiflexi-housekeeper.service  (Type=oneshot, User=multiflexi)
        │
        ▼
  Housekeeper::runAllDuties()
        │
        ├─ 1. StaleTaskFinalization
        ├─ 2. OrphanedJobCleanup
        ├─ 3. ScheduleIntegrity
        ├─ 4. DataRetentionCleanup
        ├─ 5. DiskTempFileCleanup
        ├─ 6. LogPruning
        ├─ 7. RuntemplateCounterRecalc
        └─ 8. CredentialHealthCheck
```

`Persistent=true` on the timer means a run missed while the system was
down is executed immediately on next boot. `RandomizedDelaySec=300` spreads
simultaneous runs across multi-server deployments by up to five minutes.

---

## Duties

| # | Duty class | Priority | What it does |
|---|---|---|---|
| 1 | `StaleTaskFinalization` | Critical | Marks expired `open`/`running` Tasks as `missed` (0 attempts) or `failed` (≥1 attempt), keeping the obligation metric accurate |
| 2 | `OrphanedJobCleanup` | High | Removes jobs with broken company/RunTemplate references, purges broken queue records, and deletes unscheduled/unstarted ghost jobs older than `MULTIFLEXI_HOUSEKEEPER_STALE_JOB_AGE` minutes |
| 3 | `ScheduleIntegrity` | High | Calls `Scheduler::initializeScheduling()` and resets `next_schedule` for RunTemplates stuck more than 2 hours in the past so the scheduler picks them up again |
| 4 | `DataRetentionCleanup` | High | Enforces GDPR retention policies: delegates to `RetentionService` when `multiflexi-web` is installed; falls back to a built-in reader of the `data_retention_policies` table |
| 5 | `DiskTempFileCleanup` | Medium | Deletes orphaned files from `MULTIFLEXI_TMP` older than `MULTIFLEXI_HOUSEKEEPER_TMP_AGE_HOURS` hours, skipping files still referenced by running jobs |
| 6 | `LogPruning` | Medium | Keeps the `log` table below `MULTIFLEXI_HOUSEKEEPER_LOG_KEEP` rows (default 100 000) by deleting the oldest excess rows |
| 7 | `RuntemplateCounterRecalc` | Low | Recalculates `successfull_jobs_count` / `failed_jobs_count` on RunTemplates from surviving job rows after retention deletes |
| 8 | `CredentialHealthCheck` | Medium | Calls `checkAvailability()` on each credential assigned to an active RunTemplate; logs `Misconfigured` as error, `Unavailable` as warning, `Degraded` as info |

---

## Installation

### Debian / Ubuntu (recommended)

```bash
sudo apt install multiflexi-housekeeper
```

The package enables and starts `multiflexi-housekeeper.timer` automatically.
Verify the timer is active:

```bash
systemctl list-timers multiflexi-housekeeper
```

### From source

```bash
git clone https://github.com/VitexSoftware/multiflexi-housekeeper.git
cd multiflexi-housekeeper
composer install
```

Copy `/etc/multiflexi/multiflexi.env` (or a `.env` file at the project root)
with at minimum the `DB_*` connection variables, then run:

```bash
php -q -f src/housekeeper.php
```

---

## Requirements

| Requirement | Details |
|---|---|
| PHP | ≥ 8.0 |
| `vitexsoftware/multiflexi-core` | Runtime dependency |
| Database package | One of `multiflexi-sqlite`, `multiflexi-mysql`, `multiflexi-pgsql` |
| `multiflexi-web` | Optional — activates full `RetentionService` in duty 4 |
| `multiflexi-cli` | Optional — useful for manual verification |

---

## Configuration

All options are environment variables, read from `/etc/multiflexi/multiflexi.env`
(or `.env` in the project root for source installs).

| Variable | Default | Description |
|---|---|---|
| `MULTIFLEXI_DAEMONIZE` | `false` | Set to `true` to run as a long-running daemon instead of exiting after one cycle |
| `MULTIFLEXI_HOUSEKEEPER_DRY_RUN` | `false` | Simulate all duties; no DB writes or file deletions |
| `MULTIFLEXI_HOUSEKEEPER_CYCLE_PAUSE` | `3600` | Seconds between cycles in daemon mode |
| `MULTIFLEXI_HOUSEKEEPER_LOG_KEEP` | `100000` | Maximum number of rows to keep in the `log` table |
| `MULTIFLEXI_HOUSEKEEPER_TMP_AGE_HOURS` | `24` | Minimum age (hours) of an orphaned temp file before deletion |
| `MULTIFLEXI_HOUSEKEEPER_STALE_JOB_AGE` | `60` | Minutes before an unstarted, unscheduled job is considered a ghost |
| `MULTIFLEXI_HOUSEKEEPER_SKIP_DUTIES` | _(empty)_ | Comma-separated short class names to skip, e.g. `CredentialHealthCheck,LogPruning` |
| `APP_DEBUG` | `false` | Set to `true` to add console output alongside syslog/SQL logging |
| `ZABBIX_SERVER` / `ZABBIX_HOST` | _(empty)_ | When both set and `MultiFlexi\LogToZabbix` is available, duty results are forwarded to Zabbix |

---

## Usage

### Preview what would change (dry-run)

```bash
sudo -u multiflexi multiflexi-housekeeper --dry-run
```

All duties execute their read queries and report counts, but no rows are
deleted and no files are unlinked. Output is prefixed with `[DRY-RUN]`.

### Trigger an immediate maintenance run

```bash
sudo systemctl start multiflexi-housekeeper.service
```

### Check the last run result

```bash
systemctl status multiflexi-housekeeper.service
```

### Skip a specific duty

```bash
# In /etc/multiflexi/multiflexi.env:
MULTIFLEXI_HOUSEKEEPER_SKIP_DUTIES=CredentialHealthCheck
```

### Daemon mode (legacy deployments without systemd)

```bash
MULTIFLEXI_DAEMONIZE=true MULTIFLEXI_HOUSEKEEPER_CYCLE_PAUSE=3600 \
  multiflexi-housekeeper
```

---

## Viewing Logs

```bash
# Live output from the last / current run
sudo journalctl -u multiflexi-housekeeper -f

# Last 50 lines
sudo journalctl -u multiflexi-housekeeper -n 50

# All runs since yesterday
sudo journalctl -u multiflexi-housekeeper --since yesterday
```

---

## Systemd Units

### `multiflexi-housekeeper.timer`

```ini
[Timer]
OnCalendar=hourly
RandomizedDelaySec=300
Persistent=true
```

### `multiflexi-housekeeper.service`

```ini
[Service]
Type=oneshot
User=multiflexi
EnvironmentFile=/etc/multiflexi/multiflexi.env
ExecStart=/usr/bin/php /usr/lib/multiflexi-housekeeper/housekeeper.php
TimeoutStartSec=600
```

The `EnvironmentFile` is re-read on every timer trigger — no service restart
is needed after changing configuration variables.

---

## Project Structure

```
multiflexi-housekeeper/
├── bin/
│   └── multiflexi-housekeeper          # shell wrapper (--dry-run → env var)
├── src/
│   ├── housekeeper.php                 # entry point: bootstrap, PID lock, duty loop
│   └── MultiFlexi/
│       ├── Housekeeper.php             # orchestrator: duty registry, runAllDuties()
│       └── Duty/
│           ├── AbstractDuty.php
│           ├── StaleTaskFinalization.php
│           ├── OrphanedJobCleanup.php
│           ├── ScheduleIntegrity.php
│           ├── DataRetentionCleanup.php
│           ├── DiskTempFileCleanup.php
│           ├── LogPruning.php
│           ├── RuntemplateCounterRecalc.php
│           └── CredentialHealthCheck.php
├── debian/                             # Debian packaging
├── eu.multiflexi.multiflexi_housekeeper.svg        # animated AppStream stock icon
├── eu.multiflexi.multiflexi_housekeeper.metainfo.xml
└── composer.json
```

---

## Troubleshooting

**`CredentialHealthCheck` reports false positives**
Some credential prototypes require field values loaded during job setup.
If you see spurious `Misconfigured` warnings, skip the duty:
```
MULTIFLEXI_HOUSEKEEPER_SKIP_DUTIES=CredentialHealthCheck
```

**`DataRetentionCleanup` shows `skipped`**
The `data_retention_policies` table does not exist — either MultiFlexi core
is older than the retention feature or no policies have been defined yet.
Install `multiflexi-web` to get the full policy management UI.

**Housekeeper runs but the timer shows it last ran days ago**
The system was suspended or the timer unit was not enabled:
```bash
sudo systemctl enable --now multiflexi-housekeeper.timer
```

**Ghost jobs accumulate faster than cleanup removes them**
Lower `MULTIFLEXI_HOUSEKEEPER_STALE_JOB_AGE` (default 60 minutes) or
investigate why the executor is not starting the queued jobs.

---

## Development

```bash
composer install
# Static analysis
vendor/bin/phpstan analyse src
# Code style
vendor/bin/php-cs-fixer fix --dry-run src
```

To add a new duty:

1. Create `src/MultiFlexi/Duty/YourDuty.php` extending `AbstractDuty`.
2. Implement `perform(): array` returning `['affected' => int, 'errors' => string[], 'skipped' => bool]`.
3. Register it in `Housekeeper::__construct()` at the appropriate position in the duty list.

---

## Related Components

| Package | Role |
|---|---|
| [multiflexi-scheduler](https://github.com/VitexSoftware/multiflexi-scheduler) | Enqueues jobs from RunTemplate schedules |
| [multiflexi-executor](https://github.com/VitexSoftware/multiflexi-executor) | Executes queued jobs |
| [multiflexi-eventor](https://github.com/VitexSoftware/multiflexi-eventor) | Triggers jobs from external events |
| [MultiFlexi](https://github.com/VitexSoftware/MultiFlexi) | Web UI and REST API |
| [multiflexi-cli](https://github.com/VitexSoftware/multiflexi-cli) | CLI management tool |
| [php-vitexsoftware-multiflexi-core](https://github.com/VitexSoftware/php-vitexsoftware-multiflexi-core) | Shared core library |

---

## License

[MIT](LICENSE) © [Vitex Software](https://vitexsoftware.com/)
