<?php
namespace AdmidioPlugin\Provisioning\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Provisioning\ProvisioningTables;

/**
 * One external system of an organization that Admidio provisions: its connector, connection,
 * user selection, attribute mapping and schedule.
 *
 * The JSON columns are read in the database format, so that the HTML encoding of getValue() does
 * not touch them, and written without the text sanitizing of setValue(), so that a password with
 * a "<" survives. JSON_HEX_TAG and JSON_HEX_AMP keep the stored text free of markup anyway.
 */
class Target extends Entity
{
    use OrganizationScope;

    /** Verification schedule: only when an administrator starts it. */
    public const VERIFY_MANUAL = 0;
    /** Verification schedule: every hour. Positive values are a number of days. */
    public const VERIFY_HOURLY = -1;

    public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, ProvisioningTables::targets(), 'pvt', $id);
    }

    protected static function organizationScopeCondition(): string
    {
        return 'pvt_org_id = ?';
    }

    public function getHookId(): ?string
    {
        return 'provisioning_target';
    }

    public function getIgnoredLogColumns(): array
    {
        return array_merge(parent::getIgnoredLogColumns(), array('pvt_secrets'));
    }

    public function getSensitiveHookColumns(): array
    {
        return array('pvt_secrets');
    }

    public function readableName(): string
    {
        return $this->filterReadableName((string) ($this->dbColumns['pvt_name'] ?? ''));
    }

    public function getFieldTitle(string $field): string
    {
        return match ($field) {
            'pvt_name' => 'SYS_NAME',
            'pvt_enabled' => 'SYS_ENABLED',
            'pvt_connector' => 'PLG_PROVISIONING_CONNECTOR',
            'pvt_config' => 'PLG_PROVISIONING_CONNECTION',
            'pvt_sso_type', 'pvt_sso_client_id' => 'PLG_PROVISIONING_SSO_LINK',
            'pvt_user_roles' => 'PLG_PROVISIONING_USER_ROLES',
            'pvt_account_name_source' => 'PLG_PROVISIONING_ACCOUNT_NAME',
            'pvt_attribute_mapping' => 'PLG_PROVISIONING_ATTRIBUTES',
            'pvt_group_catchall' => 'PLG_PROVISIONING_GROUP_CATCHALL',
            'pvt_verify_interval' => 'PLG_PROVISIONING_VERIFY_INTERVAL',
            default => parent::getFieldTitle($field)
        };
    }

    public function getId(): int
    {
        return (int) $this->getValue('pvt_id');
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getValue('pvt_enabled');
    }

    /**
     * @return array<string,mixed> The connector configuration without secrets.
     */
    public function getConfig(): array
    {
        return $this->readJson('pvt_config');
    }

    /**
     * @return array<string,string> The secret values of the connector configuration.
     */
    public function getSecrets(): array
    {
        return $this->readJson('pvt_secrets');
    }

    public function setConfig(array $config): void
    {
        $this->writeJson('pvt_config', $config);
    }

    public function setSecrets(array $secrets): void
    {
        $this->writeJson('pvt_secrets', $secrets);
    }

    /**
     * @return array<int,int> IDs of the roles that select the users, empty for the whole organization.
     */
    public function getUserRoleIds(): array
    {
        return array_values(array_map('intval', $this->readJson('pvt_user_roles')));
    }

    public function setUserRoleIds(array $roleIds): void
    {
        $this->writeJson('pvt_user_roles', array_values(array_unique(array_map('intval', $roleIds))));
    }

    /**
     * @return array<int,array{path:string,source:string}> The attribute mapping in its configured order.
     */
    public function getAttributeMapping(): array
    {
        $mapping = array();
        foreach ($this->readJson('pvt_attribute_mapping') as $entry) {
            if (is_array($entry) && ($entry['path'] ?? '') !== '' && ($entry['source'] ?? '') !== '') {
                $mapping[] = array('path' => (string) $entry['path'], 'source' => (string) $entry['source']);
            }
        }
        return $mapping;
    }

    public function setAttributeMapping(array $mapping): void
    {
        $this->writeJson('pvt_attribute_mapping', array_values($mapping));
    }

    /**
     * The linked SSO client as array(type, client id), or null if the target is not linked.
     * @return array{0:string,1:int}|null
     */
    public function getSsoLink(): ?array
    {
        $type = (string) $this->getValue('pvt_sso_type', 'database');
        $clientId = (int) $this->getValue('pvt_sso_client_id');

        if (!in_array($type, array('saml', 'oidc'), true) || $clientId <= 0) {
            return null;
        }
        return array($type, $clientId);
    }

    public function getVerifyInterval(): int
    {
        return (int) $this->getValue('pvt_verify_interval');
    }

    private function readJson(string $column): array
    {
        $value = json_decode((string) $this->getValue($column, 'database'), true);
        return is_array($value) ? $value : array();
    }

    private function writeJson(string $column, array $value): void
    {
        $this->setValue($column, json_encode($value, self::JSON_FLAGS), false);
    }
}
