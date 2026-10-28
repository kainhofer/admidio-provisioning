<?php
namespace AdmidioPlugin\Provisioning\Connector\Nextcloud;

use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\Connector;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\GroupState;
use AdmidioPlugin\Provisioning\Connector\HttpClient;

/**
 * Connector for the provisioning API of Nextcloud (OCS, /ocs/v2.php/cloud/...).
 *
 * The account name is the Nextcloud user ID, which never changes after the account was created;
 * an existing user with that ID is taken over. Nextcloud has nothing like a SCIM externalId. New
 * users get no password: Nextcloud sends them a mail to set one, so they need an email address.
 * Phone numbers have to be international (+43 ...) unless Nextcloud has a default_phone_region.
 * Groups get their name as group ID and display name; a renamed group keeps its ID and gets the
 * new display name.
 *
 * The requests are authenticated as a Nextcloud administrator (or a group administrator of the
 * provisioned groups), preferably with an app password.
 */
class NextcloudConnector extends Connector
{
    /** OCS status codes of the provisioning API that are not plain HTTP statuses. */
    private const OCS_OK = array(100, 200);
    private const OCS_GROUP_MISSING = 102;
    private const OCS_USER_MISSING = 103;
    /** A field the backend of the user does not let anybody change. */
    private const OCS_FIELD_NOT_EDITABLE = 113;
    /** Message of the 403 for a change that needs a recent password confirmation. */
    private const PASSWORD_CONFIRMATION_REQUIRED = 'Password confirmation is required';

    /** Attributes that are changed with PUT /users/{id}, by the key of the API. */
    private const EDITABLE_KEYS = array(
        'email', 'displayname', 'phone', 'address', 'website', 'twitter', 'organisation', 'role',
        'headline', 'biography', 'language', 'locale', 'quota'
    );

    protected HttpClient $http;
    private string $baseUrl;
    /** @var array<string,string> Display names of the groups findGroup() found, by group ID. */
    private array $foundDisplayNames = array();

    public function __construct(array $config, array $secrets, bool $allowPrivateNetwork = false, ?callable $transport = null)
    {
        parent::__construct($config, $secrets, $allowPrivateNetwork);
        $this->http = new HttpClient($allowPrivateNetwork, $transport);
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
    }

    public static function getLabel(): string
    {
        return 'PLG_PROVISIONING_CONNECTOR_NEXTCLOUD';
    }

    public static function getConfigFields(): array
    {
        return array(
            'base_url' => array('type' => 'url', 'label' => 'PLG_PROVISIONING_NEXTCLOUD_BASE_URL',
                'description' => 'PLG_PROVISIONING_NEXTCLOUD_BASE_URL_DESC', 'required' => true),
            'username' => array('type' => 'string', 'label' => 'PLG_PROVISIONING_AUTH_USERNAME', 'required' => true),
            'password' => array('type' => 'string', 'secret' => true, 'label' => 'PLG_PROVISIONING_NEXTCLOUD_APP_PASSWORD',
                'description' => 'PLG_PROVISIONING_NEXTCLOUD_APP_PASSWORD_DESC')
        );
    }

    public static function getAttributeTargets(): array
    {
        return array(
            'email' => 'PLG_PROVISIONING_SCIM_EMAIL',
            'displayname' => 'PLG_PROVISIONING_SCIM_DISPLAY_NAME',
            'phone' => 'PLG_PROVISIONING_SCIM_PHONE',
            'address' => 'PLG_PROVISIONING_ATTR_ADDRESS',
            'website' => 'PLG_PROVISIONING_ATTR_WEBSITE',
            'twitter' => 'PLG_PROVISIONING_ATTR_TWITTER',
            'organisation' => 'PLG_PROVISIONING_SCIM_ORGANIZATION',
            'role' => 'PLG_PROVISIONING_ATTR_ROLE',
            'headline' => 'PLG_PROVISIONING_ATTR_HEADLINE',
            'biography' => 'PLG_PROVISIONING_ATTR_BIOGRAPHY',
            'language' => 'PLG_PROVISIONING_SCIM_PREFERRED_LANGUAGE',
            'locale' => 'PLG_PROVISIONING_ATTR_LOCALE',
            'quota' => 'PLG_PROVISIONING_ATTR_QUOTA'
        );
    }

    public static function getDefaultAttributeMapping(): array
    {
        return array('email' => 'EMAIL', 'displayname' => 'fullname');
    }

    public function getCapabilities(): array
    {
        return array(
            'accounts' => true,
            'deactivate' => true,
            'groups' => true,
            'deleteGroups' => true,
            'memberKey' => self::MEMBER_KEY_REMOTE_ID,
            'verify' => true
        );
    }

    public function test(): array
    {
        $response = $this->request('GET', 'users', array(), array('limit' => '1'));
        $this->expect($response);

        $capabilities = $this->request('GET', 'capabilities');
        $version = (string) ($capabilities['data']['version']['string'] ?? '');
        return array('Nextcloud' => $version === '' ? '?' : $version, 'provisioning API' => 'OK');
    }

    public function findAccount(Account $account): ?string
    {
        if ($account->name === '') {
            return null;
        }
        $response = $this->request('GET', 'users/' . rawurlencode($account->name));
        if ($response['status'] === 404 || $response['code'] === 404 || $response['code'] === 998) {
            return null;
        }
        $this->expect($response);
        return (string) ($response['data']['id'] ?? $account->name);
    }

    public function createAccount(Account $account): string
    {
        $parameters = array('userid' => $account->name);
        if (isset($account->attributes['displayname'])) {
            $parameters['displayName'] = $account->attributes['displayname'];
        }
        if (isset($account->attributes['email'])) {
            $parameters['email'] = $account->attributes['email'];
        }

        $response = $this->request('POST', 'users', $parameters);
        if ($response['code'] === 102) {
            // the user exists already (created in the meantime or by somebody else): take it over
            $this->updateAccount($account->name, $account);
            return $account->name;
        }
        $this->expect($response);

        // the remaining attributes cannot be given when the user is created
        $this->updateAccount($account->name, $account);
        return $account->name;
    }

    /**
     * Change only what differs from the current state of the user.
     *
     * A field the user's backend does not let anybody change (OCS 113 - for example the display name
     * of a user that a SAML login created, which comes from the SAML attributes) is left to that
     * backend. A value Nextcloud rejects does not stop the other fields and the enabled state; the
     * rejected fields are reported together at the end.
     */
    public function updateAccount(string $remoteId, Account $account): void
    {
        $response = $this->request('GET', 'users/' . rawurlencode($remoteId));
        $this->expect($response);
        $current = (array) $response['data'];
        $rejected = array();

        foreach ($account->attributes as $key => $value) {
            if (!in_array($key, self::EDITABLE_KEYS, true)) {
                continue;
            }
            $currentValue = $key === 'quota' ? (string) ($current['quota']['quota'] ?? '') : (string) ($current[$key] ?? '');
            if ($currentValue === $value) {
                continue;
            }

            $response = $this->request('PUT', 'users/' . rawurlencode($remoteId), array('key' => $key, 'value' => $value));
            if ($response['code'] === self::OCS_FIELD_NOT_EDITABLE) {
                continue;
            }
            try {
                $this->expect($response);
            } catch (ConnectorError $error) {
                if ($error->kind !== ConnectorError::INVALID) {
                    throw $error;
                }
                $rejected[] = $key . ' (' . ($response['message'] !== '' ? $response['message'] : $response['code']) . ')';
            }
        }

        $enabled = (bool) ($current['enabled'] ?? true);
        if ($enabled !== $account->active) {
            $this->expect($this->request('PUT', 'users/' . rawurlencode($remoteId) . '/' . ($account->active ? 'enable' : 'disable')));
        }

        if ($rejected !== array()) {
            throw ConnectorError::invalid('Nextcloud rejected ' . implode(', ', $rejected) . '.');
        }
    }

    public function deactivateAccount(string $remoteId, Account $account): void
    {
        $this->expect($this->request('PUT', 'users/' . rawurlencode($remoteId) . '/disable'));
    }

    public function deleteAccount(string $remoteId): void
    {
        $response = $this->request('DELETE', 'users/' . rawurlencode($remoteId));
        if ($response['status'] === 404 || $response['code'] === 404 || $response['code'] === 998) {
            return;
        }
        $this->expect($response);
    }

    public function findGroup(GroupState $group): ?string
    {
        $response = $this->request('GET', 'groups/details', array(), array('search' => $group->name));
        $this->expect($response);

        foreach ((array) ($response['data']['groups'] ?? array()) as $candidate) {
            $id = (string) ($candidate['id'] ?? '');
            if (strcasecmp($id, $group->name) === 0 || strcasecmp((string) ($candidate['displayname'] ?? ''), $group->name) === 0) {
                $this->foundDisplayNames[$id] = (string) ($candidate['displayname'] ?? '');
                return $id;
            }
        }
        return null;
    }

    public function createGroup(GroupState $group): string
    {
        $response = $this->request('POST', 'groups', array('groupid' => $group->name, 'displayname' => $group->name));
        if ($response['code'] === 102) {
            // exists already
            return $group->name;
        }
        $this->expect($response);
        return $group->name;
    }

    /**
     * A group that findGroup() has just found with the right display name is left alone: changing a
     * group is one of the slower requests of Nextcloud.
     */
    public function updateGroup(string $remoteId, GroupState $group): void
    {
        if (($this->foundDisplayNames[$remoteId] ?? null) === $group->name) {
            return;
        }
        $this->expect($this->request('PUT', 'groups/' . rawurlencode($remoteId), array('key' => 'displayname', 'value' => $group->name)));
    }

    public function deleteGroup(string $remoteId): void
    {
        $response = $this->request('DELETE', 'groups/' . rawurlencode($remoteId));
        if ($response['status'] === 404 || $response['code'] === 101 || $response['code'] === 998) {
            return;
        }
        $this->expect($response);
    }

    public function changeMembers(string $remoteId, GroupState $group, array $add, array $remove): void
    {
        foreach ($add as $userId) {
            $response = $this->request('POST', 'users/' . rawurlencode($userId) . '/groups', array('groupid' => $remoteId));
            $this->expectMembership($response, $remoteId);
        }
        foreach ($remove as $userId) {
            // DELETE with the group in the query: not every web server passes a body on to PHP
            $response = $this->request('DELETE', 'users/' . rawurlencode($userId) . '/groups', array(), array('groupid' => $remoteId));
            if ($response['code'] === self::OCS_USER_MISSING) {
                continue;
            }
            $this->expectMembership($response, $remoteId);
        }
    }

    public function verifyAccount(string $remoteId, Account $account): bool
    {
        $response = $this->request('GET', 'users/' . rawurlencode($remoteId));
        if ($response['status'] === 404 || $response['code'] === 404 || $response['code'] === 998) {
            return false;
        }
        $this->expect($response);
        return (bool) ($response['data']['enabled'] ?? true) === $account->active;
    }

    public function readGroupMembers(string $remoteId): ?array
    {
        $response = $this->request('GET', 'groups/' . rawurlencode($remoteId) . '/users');
        if ($response['status'] === 404 || $response['code'] === 404 || $response['code'] === 998) {
            throw ConnectorError::missing('The group ' . $remoteId . ' does not exist on Nextcloud.');
        }
        $this->expect($response);
        return array_map('strval', (array) ($response['data']['users'] ?? array()));
    }

    /**
     * Send an OCS request.
     *
     * @param array<string,string> $parameters form parameters of the body
     * @param array<string,string> $query
     * @return array{status: int, code: int, message: string, data: mixed}
     */
    private function request(string $method, string $path, array $parameters = array(), array $query = array()): array
    {
        if ($this->baseUrl === '') {
            throw ConnectorError::target('No Nextcloud URL is configured.');
        }

        $url = $this->baseUrl . '/ocs/v2.php/cloud/' . $path;
        if ($query !== array()) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = array(
            'OCS-APIRequest: true',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode((string) ($this->config['username'] ?? '') . ':' . (string) ($this->secrets['password'] ?? ''))
        );
        $body = null;
        if ($parameters !== array()) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($parameters, '', '&');
        }

        [$status, $responseHeaders, $responseBody] = $this->http->send($method, $url, $headers, $body);
        $decoded = json_decode($responseBody, true);
        $meta = is_array($decoded) ? (array) ($decoded['ocs']['meta'] ?? array()) : array();
        $message = (string) ($meta['message'] ?? '');

        if ($status === 403 && $message === self::PASSWORD_CONFIRMATION_REQUIRED) {
            // stored as translation ID, translated where the error is shown
            throw ConnectorError::target('PLG_PROVISIONING_NEXTCLOUD_PASSWORD_CONFIRMATION');
        }
        HttpClient::raiseForStatus($status, $responseHeaders, $message);

        return array(
            'status' => $status,
            'code' => (int) ($meta['statuscode'] ?? $status),
            'message' => $message,
            'data' => is_array($decoded) ? ($decoded['ocs']['data'] ?? null) : null
        );
    }

    /**
     * @param array{status: int, code: int, message: string, data: mixed} $response
     */
    private function expect(array $response): void
    {
        if ($response['status'] >= 200 && $response['status'] < 300 && in_array($response['code'], self::OCS_OK, true)) {
            return;
        }

        $message = 'Nextcloud answered ' . $response['status'] . '/' . $response['code']
            . ($response['message'] === '' ? '' : ': ' . mb_substr($response['message'], 0, 300));
        throw match (true) {
            $response['status'] === 404 || $response['code'] === 404 || $response['code'] === 998 => ConnectorError::missing($message),
            $response['code'] === 997 => ConnectorError::target($message),
            default => ConnectorError::invalid($message)
        };
    }

    /**
     * @param array{status: int, code: int, message: string, data: mixed} $response
     */
    private function expectMembership(array $response, string $groupId): void
    {
        if ($response['code'] === self::OCS_GROUP_MISSING) {
            throw ConnectorError::missing('The group ' . $groupId . ' does not exist on Nextcloud.');
        }
        $this->expect($response);
    }
}
