<?php
namespace AdmidioPlugin\Provisioning\Entity;

/**
 * Restricts every read of a record to the current organization.
 *
 * Entity::readData() is where readDataById(), readDataByUuid(), readDataByColumns() and the
 * constructor meet, so this is the one place that also covers reads which do not go through a
 * service, for example the change history. A record of another organization reads as "not found".
 */
trait OrganizationScope
{
    /**
     * The SQL condition that ties the record to an organization, with exactly one placeholder for
     * the organization id.
     */
    abstract protected static function organizationScopeCondition(): string;

    /**
     * @throws \Admidio\Infrastructure\Exception
     */
    protected function readData(string $sqlWhereCondition, array $queryParams = array()): bool
    {
        $organizationId = (int) ($GLOBALS['gCurrentOrgId'] ?? 0);

        if ($sqlWhereCondition !== '' && $organizationId > 0) {
            $sqlWhereCondition .= ' AND ' . static::organizationScopeCondition() . ' ';
            $queryParams[] = $organizationId;
        }

        return parent::readData($sqlWhereCondition, $queryParams);
    }
}
