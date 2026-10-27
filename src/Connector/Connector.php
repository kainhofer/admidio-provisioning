<?php
namespace AdmidioPlugin\Provisioning\Connector;

/**
 * Base class of all connectors. A connector translates "this account or group should look like
 * this" into the requests of one kind of external system. Everything else - who belongs to a
 * target, when to synchronize, retries, mappings, the administration pages - is done by the plugin
 * and is the same for every connector.
 *
 * Methods that talk to the target throw a ConnectorError when they fail. Remote IDs are the
 * identifiers the target assigned; keys (Account::$key, GroupState::$key) are Admidio's stable
 * identities.
 *
 * Connectors are registered through the filter **provisioning_connectors**, which maps a connector
 * type to the class name:
 * ```
 * Hooks::addFilter('provisioning_connectors', static function (array $connectors): array {
 *     $connectors['mailman'] = MailmanConnector::class;
 *     return $connectors;
 * });
 * ```
 */
abstract class Connector
{
    /** Group members are identified by the remote ID of their account. */
    public const MEMBER_KEY_REMOTE_ID = 'remoteId';
    /** Group members are identified by their email address (for example mailing lists). */
    public const MEMBER_KEY_EMAIL = 'email';

    /**
     * @param array<string,mixed> $config The configuration fields without secrets.
     * @param array<string,string> $secrets The secret configuration fields.
     * @param bool $allowPrivateNetwork Whether the target may be in a private network.
     */
    public function __construct(
        protected readonly array $config,
        protected readonly array $secrets,
        protected readonly bool $allowPrivateNetwork = false
    ) {
    }

    /**
     * Language key of the name of the connector type, for example "SCIM 2.0".
     */
    abstract public static function getLabel(): string;

    /**
     * The fields of the connection section of the edit form, in the format of the plugin.json
     * settings: **type** (string, url, integer, boolean, enum), **label**, **description**,
     * **default**, **values** for enum, plus **required** and **secret**. A secret field is stored
     * separately and never shown again.
     *
     * @return array<string,array<string,mixed>>
     */
    abstract public static function getConfigFields(): array;

    /**
     * The attributes an administrator can map to Admidio fields, as path => language key.
     *
     * @return array<string,string>
     */
    public static function getAttributeTargets(): array
    {
        return array();
    }

    /**
     * The Admidio field that gives the account name when neither the target nor a linked SSO client
     * names one: the login name, or for example the email address for a mailing list.
     */
    public static function getDefaultAccountNameSource(): string
    {
        return 'usr_login_name';
    }

    /**
     * The mapping a new target starts with, as path => Admidio field (internal name of a profile
     * field, or usr_login_name, usr_uuid, fullname).
     *
     * @return array<string,string>
     */
    public static function getDefaultAttributeMapping(): array
    {
        return array();
    }

    /**
     * What the target supports. **accounts**: it has accounts of its own (a mailing list has only
     * members). **deactivate**: accounts can be deactivated; otherwise an account that no longer
     * belongs to the target is deleted. **groups**: it has groups. **deleteGroups**: a group that
     * is no longer configured is deleted; otherwise only the members Admidio added are removed
     * from it (for example from a mailing list with an archive). **memberKey**: how group members
     * are identified. **verify**: verifyAccount() and readGroupMembers() can be asked.
     *
     * @return array{accounts: bool, deactivate: bool, groups: bool, deleteGroups: bool, memberKey: string, verify: bool}
     */
    public function getCapabilities(): array
    {
        return array(
            'accounts' => true,
            'deactivate' => true,
            'groups' => true,
            'deleteGroups' => true,
            'memberKey' => self::MEMBER_KEY_REMOTE_ID,
            'verify' => false
        );
    }

    /**
     * The key by which a provisioned account is a member of a group.
     */
    public function getMemberKey(?string $remoteId, Account $account): string
    {
        return $this->getCapabilities()['memberKey'] === self::MEMBER_KEY_EMAIL
            ? $account->email
            : (string) $remoteId;
    }

    /**
     * Test the connection.
     *
     * @return array<string,string> Details for the administrator, for example the supported features.
     */
    abstract public function test(): array;

    /**
     * Find an existing account of this user, normally by the key. Returns null if there is none.
     * Throws a conflict if the answer is ambiguous.
     */
    abstract public function findAccount(Account $account): ?string;

    /**
     * Create the account and return its remote ID.
     */
    abstract public function createAccount(Account $account): string;

    abstract public function updateAccount(string $remoteId, Account $account): void;

    abstract public function deactivateAccount(string $remoteId, Account $account): void;

    abstract public function deleteAccount(string $remoteId): void;

    abstract public function findGroup(GroupState $group): ?string;

    /**
     * Create the group without members and return its remote ID.
     */
    abstract public function createGroup(GroupState $group): string;

    abstract public function updateGroup(string $remoteId, GroupState $group): void;

    abstract public function deleteGroup(string $remoteId): void;

    /**
     * Add and remove members. $group->memberKeys holds the complete member list after the change,
     * for targets that can only replace the whole list.
     *
     * @param array<int,string> $add Member keys to add.
     * @param array<int,string> $remove Member keys to remove.
     */
    abstract public function changeMembers(string $remoteId, GroupState $group, array $add, array $remove): void;

    /**
     * For verification: whether the remote account still exists and matches the active state.
     * Only called if the capability **verify** is set.
     */
    public function verifyAccount(string $remoteId, Account $account): bool
    {
        return true;
    }

    /**
     * For verification: the member keys the remote group has, or null if the target cannot tell.
     *
     * @return array<int,string>|null
     */
    public function readGroupMembers(string $remoteId): ?array
    {
        return null;
    }
}
