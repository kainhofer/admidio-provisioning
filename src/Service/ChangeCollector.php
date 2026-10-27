<?php
namespace AdmidioPlugin\Provisioning\Service;

use Admidio\Hooks\ValueObject\EntityChangeSet;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningTables;
use Throwable;

/**
 * Turns committed changes of Admidio records into queue items.
 *
 * The plugin listens only to the post-commit stages (created, updated, deleted), so it never reacts
 * to a change that is rolled back. A callback does nothing but plan and queue; the queue is
 * processed at the end of the request (RequestRunner). Every error is caught and logged: a hook
 * callback that throws after the commit would turn a successful save into an error page, and a lost
 * item is recovered by the next daily reconciliation anyway.
 */
final class ChangeCollector
{
    /** The hooks the plugin listens to. */
    public const HOOKS = array(
        'user_created', 'user_updated', 'user_deleted',
        'user_data_created', 'user_data_updated', 'user_data_deleted',
        'membership_created', 'membership_updated', 'membership_deleted',
        'role_updated', 'role_deleted',
        'role_right_assignment_created', 'role_right_assignment_deleted',
        'saml_client_updated', 'saml_client_deleted',
        'oidc_client_updated', 'oidc_client_deleted'
    );

    /** @var array<int,array<int,Target>> Enabled targets by organization, per request. */
    private static array $targets = array();

    public static function onEntityChange(EntityChangeSet $changeSet, ?Entity $entity = null): void
    {
        global $gLogger;

        try {
            if (self::collect($changeSet)) {
                RequestRunner::markQueued();
            }
        } catch (Throwable $exception) {
            if (isset($gLogger)) {
                $gLogger->warning('Provisioning: a change could not be queued.', array(
                    'hook' => $changeSet->getHookId() . '_' . $changeSet->getOperation(),
                    'message' => $exception->getMessage()
                ));
            }
        }
    }

    /**
     * @return bool Whether something was queued.
     */
    public static function collect(EntityChangeSet $changeSet): bool
    {
        global $gDb;

        $targets = self::targets();
        if ($targets === array()) {
            return false;
        }

        // Records deleted together with a user or a role are part of that deletion, which is
        // handled on its own.
        if ($changeSet->isCascade() && in_array($changeSet->getCauseHookId(), array('user', 'role'), true)) {
            return false;
        }

        switch ($changeSet->getHookId()) {
            case 'user':
                if ($changeSet->isDelete()) {
                    $userUuid = (string) ($changeSet->getUuid() ?? self::column($changeSet, 'usr_uuid'));
                    return self::queueDeletedUser($targets, $userUuid);
                }
                // A user is saved on every login as well; only these columns matter to a target.
                if ($changeSet->isUpdate() && !$changeSet->hasChanged('usr_login_name') && !$changeSet->hasChanged('usr_valid')) {
                    return false;
                }
                return self::planUsers($targets, array((int) $changeSet->getId()));

            case 'user_data':
                return self::planUsers($targets, array((int) self::column($changeSet, 'usd_usr_id')));

            case 'membership':
                return self::planUsers($targets, array((int) self::column($changeSet, 'mem_usr_id')));

            case 'role':
                if ($changeSet->isDelete() || $changeSet->hasChanged('rol_valid') || $changeSet->hasChanged('rol_cat_id')) {
                    // who belongs to the targets may have changed in ways that are cheaper to
                    // recalculate as a whole
                    return self::requestReconciliation($targets);
                }
                if ($changeSet->hasChanged('rol_name')) {
                    $roleId = (int) $changeSet->getId();
                    $queued = false;
                    foreach ($targets as $target) {
                        $queued = (new Queue($gDb))->enqueueAll($target->getId(), (new Planner($gDb, $target))->planRoles(array($roleId))) > 0 || $queued;
                    }
                    return $queued;
                }
                return false;

            case 'role_right_assignment':
                $rightName = (string) $gDb->queryPrepared(
                    'SELECT ror_name_intern FROM ' . TBL_ROLES_RIGHTS . ' WHERE ror_id = ?',
                    array((int) self::column($changeSet, 'rrd_ror_id'))
                )->fetchColumn();
                $type = match ($rightName) {
                    'sso_saml_access' => 'saml',
                    'sso_oidc_access' => 'oidc',
                    default => ''
                };
                if ($type === '') {
                    return false;
                }
                return self::requestReconciliation(self::linkedTargets($targets, $type, (int) self::column($changeSet, 'rrd_object_id')));

            case 'saml_client':
            case 'oidc_client':
                $type = $changeSet->getHookId() === 'saml_client' ? 'saml' : 'oidc';
                $linked = self::linkedTargets($targets, $type, (int) $changeSet->getId());
                if ($changeSet->isDelete()) {
                    self::disableTargets($linked);
                    return false;
                }
                return self::requestReconciliation($linked);
        }

        return false;
    }

    /**
     * Forget the targets read in this request, for example after a target was saved.
     */
    public static function reset(): void
    {
        self::$targets = array();
    }

    /**
     * @return array<int,Target>
     */
    private static function targets(): array
    {
        global $gDb, $gCurrentOrgId;

        $organizationId = (int) ($gCurrentOrgId ?? 0);
        if ($organizationId === 0 || !isset($gDb)) {
            return array();
        }
        if (!isset(self::$targets[$organizationId])) {
            self::$targets[$organizationId] = (new Scheduler($gDb))->enabledTargets();
        }
        return self::$targets[$organizationId];
    }

    /**
     * @param array<int,Target> $targets
     * @param array<int,int> $userIds
     */
    private static function planUsers(array $targets, array $userIds): bool
    {
        global $gDb;

        $userIds = array_filter($userIds);
        if ($userIds === array()) {
            return false;
        }

        $queued = false;
        foreach ($targets as $target) {
            $items = (new Planner($gDb, $target))->planUsers($userIds);
            $queued = (new Queue($gDb))->enqueueAll($target->getId(), $items) > 0 || $queued;
        }
        return $queued;
    }

    /**
     * A deleted user: the planner can no longer read the memberships, but the mappings know which
     * targets have an account.
     *
     * @param array<int,Target> $targets
     */
    private static function queueDeletedUser(array $targets, string $userUuid): bool
    {
        global $gDb;

        if ($userUuid === '') {
            return false;
        }
        $queued = false;
        foreach ($targets as $target) {
            $hasAccount = $gDb->queryPrepared(
                'SELECT 1 FROM ' . ProvisioningTables::accounts() . ' WHERE pva_pvt_id = ? AND pva_usr_uuid = ?',
                array($target->getId(), $userUuid)
            )->fetchColumn();
            if ($hasAccount !== false) {
                (new Queue($gDb))->enqueue($target->getId(), Queue::TYPE_ACCOUNT, $userUuid);
                $queued = true;
            }
        }
        return $queued;
    }

    /**
     * @param array<int,Target> $targets
     */
    private static function requestReconciliation(array $targets): bool
    {
        global $gDb;

        $status = new TargetStatus($gDb);
        foreach ($targets as $target) {
            $status->requestReconciliation($target->getId());
        }
        return $targets !== array();
    }

    /**
     * @param array<int,Target> $targets
     * @return array<int,Target>
     */
    private static function linkedTargets(array $targets, string $type, int $clientId): array
    {
        return array_values(array_filter(
            $targets,
            static fn(Target $target): bool => $target->getSsoLink() === array($type, $clientId)
        ));
    }

    /**
     * The linked SSO client was deleted. Planning would otherwise deactivate every account, so the
     * targets are switched off until an administrator decides.
     *
     * @param array<int,Target> $targets
     */
    private static function disableTargets(array $targets): void
    {
        global $gDb;

        $status = new TargetStatus($gDb);
        foreach ($targets as $target) {
            $gDb->queryPrepared(
                'UPDATE ' . ProvisioningTables::targets() . ' SET pvt_enabled = false WHERE pvt_id = ?',
                array($target->getId())
            );
            $status->setMessage($target->getId(), 'PLG_PROVISIONING_SSO_CLIENT_DELETED');
        }
        self::reset();
    }

    /**
     * The value of a column of the record: the new value of a create or an update, otherwise the
     * value before the operation.
     */
    private static function column(EntityChangeSet $changeSet, string $column): mixed
    {
        if ($changeSet->hasChanged($column)) {
            $value = $changeSet->getNewValue($column);
            if ($value !== null && $value !== '') {
                return $value;
            }
            return $changeSet->getOldValue($column);
        }
        return $changeSet->getSnapshot()[$column] ?? null;
    }
}
