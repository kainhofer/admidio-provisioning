<?php
namespace AdmidioPlugin\Provisioning\Connector\Mailman;

use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\Connector;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\GroupState;
use AdmidioPlugin\Provisioning\Connector\HttpClient;

/**
 * Connector for the REST API of Mailman 3 (for example http://localhost:8001/3.1).
 *
 * Mailman has no accounts of its own: a group of the target is a mailing list, and its members
 * are subscribed by their email address. Users without an email address are therefore not
 * provisioned (the account name is the email address unless the target names another field).
 *
 * A group name is the address of the list, or its local part when the target names the mail
 * domain. Lists are only created or deleted if the target allows it; otherwise the lists have to
 * exist, and a list that is no longer configured only loses the subscriptions Admidio made.
 * Subscriptions are made pre-verified and pre-approved, without confirmation mail.
 */
class MailmanConnector extends Connector
{
    /** Subscriptions read per request when the members of a list are verified. */
    private const ROSTER_PAGE = 200;

    protected HttpClient $http;
    private string $baseUrl;

    public function __construct(array $config, array $secrets, bool $allowPrivateNetwork = false, ?callable $transport = null)
    {
        parent::__construct($config, $secrets, $allowPrivateNetwork);
        $this->http = new HttpClient($allowPrivateNetwork, $transport);
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
    }

    public static function getLabel(): string
    {
        return 'PLG_PROVISIONING_CONNECTOR_MAILMAN';
    }

    public static function getConfigFields(): array
    {
        return array(
            'base_url' => array('type' => 'url', 'label' => 'PLG_PROVISIONING_MAILMAN_BASE_URL',
                'description' => 'PLG_PROVISIONING_MAILMAN_BASE_URL_DESC', 'required' => true),
            'username' => array('type' => 'string', 'label' => 'PLG_PROVISIONING_AUTH_USERNAME', 'required' => true),
            'password' => array('type' => 'string', 'secret' => true, 'label' => 'PLG_PROVISIONING_AUTH_PASSWORD'),
            'mail_host' => array('type' => 'string', 'label' => 'PLG_PROVISIONING_MAILMAN_MAIL_HOST',
                'description' => 'PLG_PROVISIONING_MAILMAN_MAIL_HOST_DESC'),
            'create_lists' => array('type' => 'boolean', 'default' => false, 'label' => 'PLG_PROVISIONING_MAILMAN_CREATE_LISTS',
                'description' => 'PLG_PROVISIONING_MAILMAN_CREATE_LISTS_DESC'),
            'delete_lists' => array('type' => 'boolean', 'default' => false, 'label' => 'PLG_PROVISIONING_MAILMAN_DELETE_LISTS',
                'description' => 'PLG_PROVISIONING_MAILMAN_DELETE_LISTS_DESC'),
            'welcome_message' => array('type' => 'boolean', 'default' => false, 'label' => 'PLG_PROVISIONING_MAILMAN_WELCOME',
                'description' => 'PLG_PROVISIONING_MAILMAN_WELCOME_DESC')
        );
    }

    public static function getDefaultAccountNameSource(): string
    {
        return 'EMAIL';
    }

    public function getCapabilities(): array
    {
        return array(
            'accounts' => false,
            'deactivate' => true,
            'groups' => true,
            'deleteGroups' => !empty($this->config['delete_lists']),
            'memberKey' => self::MEMBER_KEY_EMAIL,
            'verify' => true
        );
    }

    /**
     * Email addresses are compared without case.
     */
    public function getMemberKey(?string $remoteId, Account $account): string
    {
        return mb_strtolower(trim($account->email));
    }

    public function test(): array
    {
        $response = $this->request('GET', 'system/versions');
        $this->expect($response, array(200));
        return array(
            'Mailman' => (string) ($response['body']['mailman_version'] ?? '?'),
            'API' => (string) ($response['body']['api_version'] ?? '?')
        );
    }

    // Mailman has no accounts: the account methods have nothing to do.

    public function findAccount(Account $account): ?string
    {
        return null;
    }

    public function createAccount(Account $account): string
    {
        return '';
    }

    public function updateAccount(string $remoteId, Account $account): void
    {
    }

    public function deactivateAccount(string $remoteId, Account $account): void
    {
    }

    public function deleteAccount(string $remoteId): void
    {
    }

    public function findGroup(GroupState $group): ?string
    {
        $response = $this->request('GET', 'lists/' . rawurlencode($this->listAddress($group->name)));
        if ($response['status'] === 404) {
            return null;
        }
        $this->expect($response, array(200));
        return (string) ($response['body']['list_id'] ?? '');
    }

    public function createGroup(GroupState $group): string
    {
        $address = $this->listAddress($group->name);
        if (empty($this->config['create_lists'])) {
            throw ConnectorError::invalid('The mailing list ' . $address . ' does not exist, and the target may not create lists.');
        }

        $response = $this->request('POST', 'lists', array('fqdn_listname' => $address));
        if ($response['status'] !== 400 || !str_contains((string) ($response['body']['description'] ?? ''), 'exists')) {
            $this->expect($response, array(200, 201));
        }
        return str_replace('@', '.', $address);
    }

    /**
     * The name of a group is the address of its list, which cannot be changed.
     */
    public function updateGroup(string $remoteId, GroupState $group): void
    {
    }

    public function deleteGroup(string $remoteId): void
    {
        $response = $this->request('DELETE', 'lists/' . rawurlencode($remoteId));
        $this->expect($response, array(200, 204, 404));
    }

    public function changeMembers(string $remoteId, GroupState $group, array $add, array $remove): void
    {
        foreach ($add as $address) {
            $response = $this->request('POST', 'members', array(
                'list_id' => $remoteId,
                'subscriber' => $address,
                'role' => 'member',
                'pre_verified' => 'true',
                'pre_confirmed' => 'true',
                'pre_approved' => 'true',
                'send_welcome_message' => empty($this->config['welcome_message']) ? 'false' : 'true'
            ));
            // an address that is subscribed already is what we want
            if ($response['status'] === 409) {
                continue;
            }
            $this->expectList($response, $remoteId, array(200, 201));
        }

        foreach ($remove as $address) {
            $response = $this->request('DELETE', 'lists/' . rawurlencode($remoteId) . '/member/' . rawurlencode($address));
            $this->expect($response, array(200, 204, 404));
        }
    }

    public function readGroupMembers(string $remoteId): ?array
    {
        $addresses = array();
        for ($page = 1; ; ++$page) {
            $response = $this->request('GET', 'lists/' . rawurlencode($remoteId) . '/roster/member', null,
                array('count' => (string) self::ROSTER_PAGE, 'page' => (string) $page));
            $this->expectList($response, $remoteId, array(200));

            $entries = (array) ($response['body']['entries'] ?? array());
            foreach ($entries as $entry) {
                if (isset($entry['email'])) {
                    $addresses[] = mb_strtolower((string) $entry['email']);
                }
            }
            if (count($entries) < self::ROSTER_PAGE || count($addresses) >= (int) ($response['body']['total_size'] ?? 0)) {
                return $addresses;
            }
        }
    }

    /**
     * The address of the list of a group: the name itself, or the name at the mail domain of the
     * target.
     */
    private function listAddress(string $name): string
    {
        if (str_contains($name, '@')) {
            return mb_strtolower($name);
        }
        $mailHost = trim((string) ($this->config['mail_host'] ?? ''));
        if ($mailHost === '') {
            throw ConnectorError::invalid('The group "' . $name . '" is no list address, and the target names no mail domain.');
        }
        return mb_strtolower($name . '@' . $mailHost);
    }

    /**
     * @param array<string,string>|null $parameters form parameters of the body
     * @param array<string,string> $query
     * @return array{status: int, body: array<string,mixed>|null}
     */
    private function request(string $method, string $path, ?array $parameters = null, array $query = array()): array
    {
        if ($this->baseUrl === '') {
            throw ConnectorError::target('No Mailman REST URL is configured.');
        }

        $url = $this->baseUrl . '/' . $path;
        if ($query !== array()) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = array(
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode((string) ($this->config['username'] ?? '') . ':' . (string) ($this->secrets['password'] ?? ''))
        );
        $body = null;
        if ($parameters !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $body = http_build_query($parameters, '', '&');
        }

        [$status, $responseHeaders, $responseBody] = $this->http->send($method, $url, $headers, $body);
        $decoded = json_decode($responseBody, true);
        $decoded = is_array($decoded) ? $decoded : null;

        HttpClient::raiseForStatus($status, $responseHeaders, (string) ($decoded['description'] ?? ''));
        return array('status' => $status, 'body' => $decoded);
    }

    /**
     * @param array{status: int, body: array<string,mixed>|null} $response
     * @param array<int,int> $expected
     */
    private function expect(array $response, array $expected): void
    {
        if (in_array($response['status'], $expected, true)) {
            return;
        }
        $message = 'Mailman answered HTTP ' . $response['status']
            . (isset($response['body']['description']) ? ': ' . mb_substr((string) $response['body']['description'], 0, 300) : '');
        throw $response['status'] === 404 ? ConnectorError::missing($message) : ConnectorError::invalid($message);
    }

    /**
     * Like expect(), but a 404 means that the list is gone.
     *
     * @param array{status: int, body: array<string,mixed>|null} $response
     * @param array<int,int> $expected
     */
    private function expectList(array $response, string $listId, array $expected): void
    {
        if ($response['status'] === 404) {
            throw ConnectorError::missing('The mailing list ' . $listId . ' does not exist.');
        }
        $this->expect($response, $expected);
    }
}
