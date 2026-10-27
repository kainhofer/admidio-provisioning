<?php
namespace AdmidioPlugin\Provisioning\Tests\Support;

use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\Connector;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\GroupState;

/**
 * A connector that keeps accounts and groups in memory. The tests use it as target system; it is
 * also the smallest complete example of a connector.
 *
 * The state is static, so that the instance the plugin creates through the connector registry and
 * the test see the same target system.
 */
class MemoryConnector extends Connector
{
    /** @var array<string,array<string,mixed>> Accounts by remote ID. */
    public static array $accounts = array();
    /** @var array<string,array{key: string, name: string, members: array<string,bool>}> Groups by remote ID. */
    public static array $groups = array();
    /** @var array<int,string> Every call, as "method:argument". */
    public static array $calls = array();
    /** @var ConnectorError|null Thrown by the next call. */
    public static ?ConnectorError $failNext = null;
    /** @var array<string,bool> Capabilities that differ from the defaults. */
    public static array $capabilities = array();

    private static int $sequence = 0;

    public static function reset(): void
    {
        self::$accounts = array();
        self::$groups = array();
        self::$calls = array();
        self::$failNext = null;
        self::$capabilities = array();
    }

    public static function getLabel(): string
    {
        return 'Memory';
    }

    public static function getConfigFields(): array
    {
        return array();
    }

    public static function getAttributeTargets(): array
    {
        return array('displayName' => 'Display name');
    }

    public function getCapabilities(): array
    {
        return self::$capabilities + array(
            'accounts' => true, 'deactivate' => true, 'groups' => true, 'memberKey' => self::MEMBER_KEY_REMOTE_ID, 'verify' => true
        );
    }

    public function test(): array
    {
        $this->call('test');
        return array('accounts' => (string) count(self::$accounts));
    }

    public function findAccount(Account $account): ?string
    {
        $this->call('findAccount:' . $account->key);
        foreach (self::$accounts as $remoteId => $stored) {
            if ($stored['key'] === $account->key) {
                return $remoteId;
            }
        }
        return null;
    }

    public function createAccount(Account $account): string
    {
        $this->call('createAccount:' . $account->key);
        $remoteId = 'u' . ++self::$sequence;
        self::$accounts[$remoteId] = self::store($account);
        return $remoteId;
    }

    public function updateAccount(string $remoteId, Account $account): void
    {
        $this->call('updateAccount:' . $remoteId);
        $this->requireAccount($remoteId);
        self::$accounts[$remoteId] = self::store($account);
    }

    public function deactivateAccount(string $remoteId, Account $account): void
    {
        $this->call('deactivateAccount:' . $remoteId);
        $this->requireAccount($remoteId);
        self::$accounts[$remoteId]['active'] = false;
    }

    public function deleteAccount(string $remoteId): void
    {
        $this->call('deleteAccount:' . $remoteId);
        unset(self::$accounts[$remoteId]);
        foreach (self::$groups as &$group) {
            unset($group['members'][$remoteId]);
        }
    }

    public function findGroup(GroupState $group): ?string
    {
        $this->call('findGroup:' . $group->key);
        foreach (self::$groups as $remoteId => $stored) {
            if ($stored['key'] === $group->key) {
                return $remoteId;
            }
        }
        return null;
    }

    public function createGroup(GroupState $group): string
    {
        $this->call('createGroup:' . $group->key);
        $remoteId = 'g' . ++self::$sequence;
        self::$groups[$remoteId] = array('key' => $group->key, 'name' => $group->name, 'members' => array());
        return $remoteId;
    }

    public function updateGroup(string $remoteId, GroupState $group): void
    {
        $this->call('updateGroup:' . $remoteId);
        $this->requireGroup($remoteId);
        self::$groups[$remoteId]['name'] = $group->name;
    }

    public function deleteGroup(string $remoteId): void
    {
        $this->call('deleteGroup:' . $remoteId);
        unset(self::$groups[$remoteId]);
    }

    public function changeMembers(string $remoteId, GroupState $group, array $add, array $remove): void
    {
        $this->call('changeMembers:' . $remoteId . ':+' . count($add) . ':-' . count($remove));
        $this->requireGroup($remoteId);
        foreach ($add as $key) {
            self::$groups[$remoteId]['members'][$key] = true;
        }
        foreach ($remove as $key) {
            unset(self::$groups[$remoteId]['members'][$key]);
        }
    }

    public function verifyAccount(string $remoteId, Account $account): bool
    {
        return isset(self::$accounts[$remoteId]) && self::$accounts[$remoteId]['active'] === $account->active;
    }

    public function readGroupMembers(string $remoteId): ?array
    {
        $this->requireGroup($remoteId);
        return array_keys(self::$groups[$remoteId]['members']);
    }

    /**
     * The account stored under an Admidio user UUID, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function accountByKey(string $key): ?array
    {
        foreach (self::$accounts as $remoteId => $account) {
            if ($account['key'] === $key) {
                return $account + array('remoteId' => $remoteId);
            }
        }
        return null;
    }

    /**
     * @return array{key: string, name: string, members: array<string,bool>}|null
     */
    public static function groupByName(string $name): ?array
    {
        foreach (self::$groups as $group) {
            if ($group['name'] === $name) {
                return $group;
            }
        }
        return null;
    }

    private function call(string $call): void
    {
        self::$calls[] = $call;
        if (self::$failNext !== null) {
            $error = self::$failNext;
            self::$failNext = null;
            throw $error;
        }
    }

    private function requireAccount(string $remoteId): void
    {
        if (!isset(self::$accounts[$remoteId])) {
            throw ConnectorError::missing('no account ' . $remoteId);
        }
    }

    private function requireGroup(string $remoteId): void
    {
        if (!isset(self::$groups[$remoteId])) {
            throw ConnectorError::missing('no group ' . $remoteId);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function store(Account $account): array
    {
        return array(
            'key' => $account->key,
            'name' => $account->name,
            'active' => $account->active,
            'attributes' => $account->attributes
        );
    }
}
