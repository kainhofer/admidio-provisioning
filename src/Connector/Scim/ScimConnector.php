<?php
namespace AdmidioPlugin\Provisioning\Connector\Scim;

use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\Connector;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\GroupState;

/**
 * Connector for SCIM 2.0 services (RFC 7643, RFC 7644).
 *
 * Accounts and groups are looked up by externalId before they are created, so resources that exist
 * already are taken over instead of duplicated. Changes are sent with PATCH; a target that cannot
 * PATCH (ServiceProviderConfig) or that is configured so gets PUT requests instead.
 */
class ScimConnector extends Connector
{
    public const SCHEMA_USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    public const SCHEMA_GROUP = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    public const SCHEMA_PATCH = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    public const SCHEMA_ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

    /** Members per PATCH request. Large groups are changed in portions of this size. */
    public const MEMBER_CHUNK = 100;

    protected ScimClient $client;

    /** @var array{patch: bool, filter: bool}|null */
    private ?array $serviceCapabilities = null;

    public function __construct(array $config, array $secrets, bool $allowPrivateNetwork = false, ?callable $transport = null)
    {
        parent::__construct($config, $secrets, $allowPrivateNetwork);
        $this->client = new ScimClient($config, $secrets, $allowPrivateNetwork, $transport);
    }

    public static function getLabel(): string
    {
        return 'PLG_PROVISIONING_CONNECTOR_SCIM';
    }

    public static function getConfigFields(): array
    {
        return array(
            'base_url' => array('type' => 'url', 'label' => 'PLG_PROVISIONING_SCIM_BASE_URL',
                'description' => 'PLG_PROVISIONING_SCIM_BASE_URL_DESC', 'required' => true),
            'auth_type' => array('type' => 'enum', 'label' => 'PLG_PROVISIONING_AUTH_TYPE', 'default' => ScimClient::AUTH_BEARER,
                'values' => array(
                    ScimClient::AUTH_BEARER => 'PLG_PROVISIONING_AUTH_BEARER',
                    ScimClient::AUTH_OAUTH => 'PLG_PROVISIONING_AUTH_OAUTH',
                    ScimClient::AUTH_BASIC => 'PLG_PROVISIONING_AUTH_BASIC'
                )),
            'token' => array('type' => 'string', 'secret' => true, 'label' => 'PLG_PROVISIONING_AUTH_TOKEN',
                'description' => 'PLG_PROVISIONING_AUTH_TOKEN_DESC'),
            'username' => array('type' => 'string', 'label' => 'PLG_PROVISIONING_AUTH_USERNAME'),
            'password' => array('type' => 'string', 'secret' => true, 'label' => 'PLG_PROVISIONING_AUTH_PASSWORD'),
            'oauth_token_url' => array('type' => 'url', 'label' => 'PLG_PROVISIONING_OAUTH_TOKEN_URL'),
            'oauth_client_id' => array('type' => 'string', 'label' => 'PLG_PROVISIONING_OAUTH_CLIENT_ID'),
            'oauth_client_secret' => array('type' => 'string', 'secret' => true, 'label' => 'PLG_PROVISIONING_OAUTH_CLIENT_SECRET'),
            'oauth_scope' => array('type' => 'string', 'label' => 'PLG_PROVISIONING_OAUTH_SCOPE'),
            'use_put' => array('type' => 'boolean', 'default' => false, 'label' => 'PLG_PROVISIONING_SCIM_USE_PUT',
                'description' => 'PLG_PROVISIONING_SCIM_USE_PUT_DESC')
        );
    }

    public static function getAttributeTargets(): array
    {
        return array(
            'name.givenName' => 'PLG_PROVISIONING_SCIM_GIVEN_NAME',
            'name.familyName' => 'PLG_PROVISIONING_SCIM_FAMILY_NAME',
            'name.middleName' => 'PLG_PROVISIONING_SCIM_MIDDLE_NAME',
            'displayName' => 'PLG_PROVISIONING_SCIM_DISPLAY_NAME',
            'nickName' => 'PLG_PROVISIONING_SCIM_NICK_NAME',
            'title' => 'PLG_PROVISIONING_SCIM_TITLE',
            'emails' => 'PLG_PROVISIONING_SCIM_EMAIL',
            'phoneNumbers' => 'PLG_PROVISIONING_SCIM_PHONE',
            'preferredLanguage' => 'PLG_PROVISIONING_SCIM_PREFERRED_LANGUAGE',
            self::SCHEMA_ENTERPRISE . ':employeeNumber' => 'PLG_PROVISIONING_SCIM_EMPLOYEE_NUMBER',
            self::SCHEMA_ENTERPRISE . ':organization' => 'PLG_PROVISIONING_SCIM_ORGANIZATION',
            self::SCHEMA_ENTERPRISE . ':department' => 'PLG_PROVISIONING_SCIM_DEPARTMENT'
        );
    }

    public static function getDefaultAttributeMapping(): array
    {
        return array(
            'name.givenName' => 'FIRST_NAME',
            'name.familyName' => 'LAST_NAME',
            'displayName' => 'fullname',
            'emails' => 'EMAIL'
        );
    }

    public function getCapabilities(): array
    {
        return array(
            'accounts' => true,
            'deactivate' => true,
            'memberKey' => self::MEMBER_KEY_REMOTE_ID,
            'verify' => true
        );
    }

    public function test(): array
    {
        $response = $this->client->request('GET', 'ServiceProviderConfig');
        if ($response['status'] === 404) {
            // ServiceProviderConfig is mandatory, but some services leave it out. The users endpoint
            // still tells whether the connection and the credentials work.
            ScimClient::expect($this->client->request('GET', 'Users', null, array('count' => '1')), array(200));
            return array('ServiceProviderConfig' => 'not available', 'Users' => 'OK');
        }
        ScimClient::expect($response, array(200));

        $config = $response['body'] ?? array();
        $this->serviceCapabilities = self::readCapabilities($config);

        $schemes = array();
        foreach ((array) ($config['authenticationSchemes'] ?? array()) as $scheme) {
            if (is_array($scheme) && isset($scheme['type'])) {
                $schemes[] = (string) $scheme['type'];
            }
        }

        return array(
            'PATCH' => $this->serviceCapabilities['patch'] ? 'yes' : 'no',
            'filter' => $this->serviceCapabilities['filter'] ? 'yes' : 'no',
            'bulk' => !empty($config['bulk']['supported']) ? 'yes' : 'no',
            'authentication' => implode(', ', $schemes)
        );
    }

    public function findAccount(Account $account): ?string
    {
        $found = $this->search('Users', 'externalId', $account->key);
        if ($found !== null) {
            return $found['id'];
        }

        // Accounts created before Admidio managed them, or by a target that does not store the
        // externalId, are found by their name. One that belongs to somebody else is not taken over.
        $found = $this->search('Users', 'userName', $account->name);
        if ($found === null) {
            return null;
        }
        if ($found['externalId'] !== '' && $found['externalId'] !== $account->key) {
            throw ConnectorError::conflict('The account name "' . $account->name . '" belongs to another account on the target.');
        }
        return $found['id'];
    }

    public function createAccount(Account $account): string
    {
        $response = $this->client->request('POST', 'Users', $this->userResource($account));
        if ($response['status'] === 409) {
            $remoteId = $this->findAccount($account);
            if ($remoteId === null) {
                throw ConnectorError::conflict('The target refused the account "' . $account->name . '" as a duplicate, but no matching account was found.');
            }
            return $remoteId;
        }
        ScimClient::expect($response, array(200, 201));

        return self::remoteId($response['body']);
    }

    public function updateAccount(string $remoteId, Account $account): void
    {
        $resource = $this->userResource($account);
        if ($this->usePut()) {
            $resource['id'] = $remoteId;
            $response = $this->client->request('PUT', 'Users/' . rawurlencode($remoteId), $resource);
        } else {
            unset($resource['schemas']);
            $response = $this->client->request('PATCH', 'Users/' . rawurlencode($remoteId),
                self::patch(array(array('op' => 'replace', 'value' => $resource))));
        }
        ScimClient::expect($response, array(200, 204));
    }

    public function deactivateAccount(string $remoteId, Account $account): void
    {
        $this->updateAccount($remoteId, $account->withActive(false));
    }

    public function deleteAccount(string $remoteId): void
    {
        $response = $this->client->request('DELETE', 'Users/' . rawurlencode($remoteId));
        ScimClient::expect($response, array(200, 204, 404));
    }

    public function findGroup(GroupState $group): ?string
    {
        $found = $this->search('Groups', 'externalId', $group->key);
        if ($found !== null) {
            return $found['id'];
        }

        $found = $this->search('Groups', 'displayName', $group->name);
        if ($found === null) {
            return null;
        }
        if ($found['externalId'] !== '' && $found['externalId'] !== $group->key) {
            throw ConnectorError::conflict('The group name "' . $group->name . '" belongs to another group on the target.');
        }
        return $found['id'];
    }

    public function createGroup(GroupState $group): string
    {
        $response = $this->client->request('POST', 'Groups', array(
            'schemas' => array(self::SCHEMA_GROUP),
            'externalId' => $group->key,
            'displayName' => $group->name,
            'members' => array()
        ));
        if ($response['status'] === 409) {
            $remoteId = $this->findGroup($group);
            if ($remoteId === null) {
                throw ConnectorError::conflict('The target refused the group "' . $group->name . '" as a duplicate, but no matching group was found.');
            }
            return $remoteId;
        }
        ScimClient::expect($response, array(200, 201));

        return self::remoteId($response['body']);
    }

    public function updateGroup(string $remoteId, GroupState $group): void
    {
        if ($this->usePut()) {
            $response = $this->client->request('PUT', 'Groups/' . rawurlencode($remoteId), $this->groupResource($remoteId, $group));
        } else {
            $response = $this->client->request('PATCH', 'Groups/' . rawurlencode($remoteId), self::patch(array(
                array('op' => 'replace', 'value' => array('displayName' => $group->name, 'externalId' => $group->key))
            )));
        }
        ScimClient::expect($response, array(200, 204));
    }

    public function deleteGroup(string $remoteId): void
    {
        $response = $this->client->request('DELETE', 'Groups/' . rawurlencode($remoteId));
        ScimClient::expect($response, array(200, 204, 404));
    }

    public function changeMembers(string $remoteId, GroupState $group, array $add, array $remove): void
    {
        if ($add === array() && $remove === array()) {
            return;
        }

        if ($this->usePut()) {
            $response = $this->client->request('PUT', 'Groups/' . rawurlencode($remoteId), $this->groupResource($remoteId, $group));
            ScimClient::expect($response, array(200, 204));
            return;
        }

        foreach (array_chunk(array_values($add), self::MEMBER_CHUNK) as $chunk) {
            $members = array_map(static fn(string $key): array => array('value' => $key), $chunk);
            $response = $this->client->request('PATCH', 'Groups/' . rawurlencode($remoteId),
                self::patch(array(array('op' => 'add', 'path' => 'members', 'value' => $members))));
            ScimClient::expect($response, array(200, 204));
        }

        foreach (array_chunk(array_values($remove), self::MEMBER_CHUNK) as $chunk) {
            $operations = array_map(
                static fn(string $key): array => array('op' => 'remove', 'path' => 'members[value eq ' . self::quote($key) . ']'),
                $chunk
            );
            $response = $this->client->request('PATCH', 'Groups/' . rawurlencode($remoteId), self::patch($operations));
            ScimClient::expect($response, array(200, 204));
        }
    }

    public function verifyAccount(string $remoteId, Account $account): bool
    {
        $response = $this->client->request('GET', 'Users/' . rawurlencode($remoteId), null, array('attributes' => 'active,externalId'));
        if ($response['status'] === 404) {
            return false;
        }
        ScimClient::expect($response, array(200));

        return (bool) ($response['body']['active'] ?? true) === $account->active;
    }

    public function readGroupMembers(string $remoteId): ?array
    {
        $response = $this->client->request('GET', 'Groups/' . rawurlencode($remoteId), null, array('attributes' => 'members'));
        ScimClient::expect($response, array(200));

        if (!array_key_exists('members', $response['body'] ?? array())) {
            // Some services do not return the members of large groups at all.
            return null;
        }

        $keys = array();
        foreach ((array) $response['body']['members'] as $member) {
            if (is_array($member) && isset($member['value'])) {
                $keys[] = (string) $member['value'];
            }
        }
        return $keys;
    }

    /**
     * The SCIM user resource of an account.
     *
     * @return array<string,mixed>
     */
    public function userResource(Account $account): array
    {
        $resource = array(
            'schemas' => array(self::SCHEMA_USER),
            'externalId' => $account->key,
            'userName' => $account->name,
            'active' => $account->active
        );

        foreach ($account->attributes as $path => $value) {
            if ($path === 'emails') {
                $resource['emails'] = array(array('value' => $value, 'type' => 'work', 'primary' => true));
            } elseif ($path === 'phoneNumbers') {
                $resource['phoneNumbers'] = array(array('value' => $value, 'type' => 'work'));
            } elseif (str_starts_with($path, 'urn:')) {
                // urn:...:User:department - the schema is everything before the last colon
                $separator = strrpos($path, ':');
                $schema = substr($path, 0, $separator);
                self::setPath($resource[$schema], substr($path, $separator + 1), $value);
                if (!in_array($schema, $resource['schemas'], true)) {
                    $resource['schemas'][] = $schema;
                }
            } else {
                self::setPath($resource, $path, $value);
            }
        }

        return $resource;
    }

    /**
     * @return array<string,mixed>
     */
    private function groupResource(string $remoteId, GroupState $group): array
    {
        return array(
            'schemas' => array(self::SCHEMA_GROUP),
            'id' => $remoteId,
            'externalId' => $group->key,
            'displayName' => $group->name,
            'members' => array_map(static fn(string $key): array => array('value' => $key), array_values($group->memberKeys))
        );
    }

    /**
     * Search a resource by an attribute.
     *
     * @return array{id: string, externalId: string}|null The single match, null if there is none.
     */
    private function search(string $endpoint, string $attribute, string $value): ?array
    {
        if ($value === '' || !$this->capabilities()['filter']) {
            return null;
        }

        $query = array('filter' => $attribute . ' eq ' . self::quote($value));
        if ($endpoint === 'Groups') {
            $query['excludedAttributes'] = 'members';
        }

        $response = $this->client->request('GET', $endpoint, null, $query);
        if ($response['status'] === 400) {
            // the target does not support filtering by this attribute
            return null;
        }
        ScimClient::expect($response, array(200));

        $resources = (array) ($response['body']['Resources'] ?? array());
        if (count($resources) === 0) {
            return null;
        }
        if (count($resources) > 1) {
            throw ConnectorError::conflict('The target has ' . count($resources) . ' resources with ' . $attribute . ' "' . $value . '".');
        }

        return array(
            'id' => self::remoteId($resources[0]),
            'externalId' => (string) ($resources[0]['externalId'] ?? '')
        );
    }

    /**
     * @return array{patch: bool, filter: bool}
     */
    private function capabilities(): array
    {
        if ($this->serviceCapabilities === null) {
            $this->serviceCapabilities = array('patch' => true, 'filter' => true);
            try {
                $response = $this->client->request('GET', 'ServiceProviderConfig');
                if ($response['status'] === 200 && is_array($response['body'])) {
                    $this->serviceCapabilities = self::readCapabilities($response['body']);
                }
            } catch (ConnectorError $error) {
                if ($error->kind !== ConnectorError::INVALID && $error->kind !== ConnectorError::MISSING) {
                    throw $error;
                }
            }
        }
        return $this->serviceCapabilities;
    }

    /**
     * @return array{patch: bool, filter: bool}
     */
    private static function readCapabilities(array $config): array
    {
        return array(
            'patch' => (bool) ($config['patch']['supported'] ?? true),
            'filter' => (bool) ($config['filter']['supported'] ?? true)
        );
    }

    private function usePut(): bool
    {
        return !empty($this->config['use_put']) || !$this->capabilities()['patch'];
    }

    /**
     * @param array<int,array<string,mixed>> $operations
     * @return array<string,mixed>
     */
    private static function patch(array $operations): array
    {
        return array('schemas' => array(self::SCHEMA_PATCH), 'Operations' => $operations);
    }

    private static function remoteId(?array $resource): string
    {
        $id = (string) ($resource['id'] ?? '');
        if ($id === '') {
            throw ConnectorError::invalid('The target did not return the id of the resource.');
        }
        return $id;
    }

    /**
     * A string literal for a SCIM filter (RFC 7644 3.4.2.2): JSON string syntax.
     */
    private static function quote(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Set a dotted path like "name.givenName" in a nested array.
     */
    private static function setPath(mixed &$target, string $path, string $value): void
    {
        if (!is_array($target)) {
            $target = array();
        }
        $node = &$target;
        $parts = explode('.', $path);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = array();
            }
            $node = &$node[$part];
        }
        $node[$last] = $value;
    }
}
