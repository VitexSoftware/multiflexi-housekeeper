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
 * Checks the health of credentials assigned to active RunTemplates.
 *
 * Iterates all active, scheduled RunTemplates and calls checkAvailability()
 * on the prototype of each assigned credential that implements
 * checkableCredentialInterface. Reports Misconfigured credentials as warnings
 * so operators can act before a job is blocked at runtime.
 *
 * This duty is read-only — dry-run mode behaves identically to normal mode.
 */
class CredentialHealthCheck extends AbstractDuty
{
    protected string $name = 'CredentialHealthCheck';

    public function perform(): array
    {
        if (!(bool) \Ease\Shared::cfg('MULTIFLEXI_HOUSEKEEPER_CREDENTIAL_CHECK', true)) {
            $this->log('Credential health check disabled via MULTIFLEXI_HOUSEKEEPER_CREDENTIAL_CHECK', 'debug');

            return $this->result(0, [], true);
        }

        $rtQuery = new \MultiFlexi\RunTemplate();
        $errors = [];
        $affected = 0;

        try {
            $activeRts = $rtQuery->listingQuery()
                ->where(['active' => true])
                ->where('interv != ?', ['n'])
                ->fetchAll();
        } catch (\Throwable $e) {
            return $this->result(0, ['Could not query runtemplates: '.$e->getMessage()]);
        }

        foreach ($activeRts as $rtData) {
            try {
                $rt = new \MultiFlexi\RunTemplate((int) $rtData['id']);
                $credentials = $rt->getCredentialsAssigned();

                foreach ($credentials as $credData) {
                    if (empty($credData['credentials_id'])) {
                        continue;
                    }

                    try {
                        $this->checkCredential((int) $credData['credentials_id'], (int) $rtData['id'], $errors, $affected);
                    } catch (\Throwable $e) {
                        $this->log(sprintf(
                            'Exception checking credential #%d for RT#%d: %s',
                            $credData['credentials_id'],
                            $rtData['id'],
                            $e->getMessage(),
                        ), 'debug');
                    }
                }
            } catch (\Throwable $e) {
                $this->log('Error processing RT#'.$rtData['id'].': '.$e->getMessage(), 'debug');
            }
        }

        return $this->result($affected, $errors);
    }

    private function checkCredential(int $credentialId, int $runtemplateId, array &$errors, int &$affected): void
    {
        $credential = new \MultiFlexi\Credential($credentialId);
        $credType = $credential->getCredentialType();

        if (!$credType) {
            return;
        }

        $proto = $credType->getPrototype();

        if (!($proto instanceof \MultiFlexi\checkableCredentialInterface)) {
            return;
        }

        $result = $proto->checkAvailability();

        // Unknown = no check implemented, skip silently
        if ($result->state === \MultiFlexi\CredentialState::Unknown) {
            return;
        }

        ++$affected;

        $credName = $credential->getDataValue('name') ?? "#{$credentialId}";

        switch ($result->state) {
            case \MultiFlexi\CredentialState::Misconfigured:
                $msg = sprintf(
                    'ALERT: Credential "%s" (#%d) for RunTemplate #%d is MISCONFIGURED: %s',
                    $credName,
                    $credentialId,
                    $runtemplateId,
                    $result->message,
                );
                $this->log($msg, 'warning');
                $errors[] = $msg;

                break;

            case \MultiFlexi\CredentialState::Unavailable:
                $this->log(sprintf(
                    'WARN: Credential "%s" (#%d) for RunTemplate #%d is temporarily UNAVAILABLE: %s',
                    $credName,
                    $credentialId,
                    $runtemplateId,
                    $result->message,
                ), 'warning');

                break;

            case \MultiFlexi\CredentialState::Degraded:
                $this->log(sprintf(
                    'INFO: Credential "%s" (#%d) for RunTemplate #%d is DEGRADED: %s',
                    $credName,
                    $credentialId,
                    $runtemplateId,
                    $result->message,
                ), 'info');

                break;

            case \MultiFlexi\CredentialState::Available:
                $this->log(sprintf(
                    'OK: Credential "%s" (#%d) for RunTemplate #%d is available',
                    $credName,
                    $credentialId,
                    $runtemplateId,
                ), 'debug');

                break;
        }
    }
}
