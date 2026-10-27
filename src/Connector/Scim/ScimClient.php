<?php
namespace AdmidioPlugin\Provisioning\Connector\Scim;

use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\HttpClient;

/**
 * HTTP access to a SCIM service: URL building, authentication and the classification of failed
 * responses. The requests themselves go through HttpClient, which validates every URL; for tests
 * its transport can be replaced by a callable.
 */
class ScimClient
{
    public const AUTH_BEARER = 'bearer';
    public const AUTH_OAUTH = 'oauth';
    public const AUTH_BASIC = 'basic';

    /** OAuth client authentication: client ID and secret in the form body (RFC 6749 2.3.1). */
    public const OAUTH_CLIENT_SECRET_POST = 'client_secret_post';
    /** OAuth client authentication: client ID and secret as HTTP Basic credentials. */
    public const OAUTH_CLIENT_SECRET_BASIC = 'client_secret_basic';

    private string $baseUrl;

    private HttpClient $http;

    private ?string $accessToken = null;

    /**
     * @param array<string,mixed> $config base_url, auth_type, username, oauth_token_url, oauth_client_id, oauth_scope
     * @param array<string,string> $secrets token, password, oauth_client_secret
     */
    public function __construct(
        private readonly array $config,
        private readonly array $secrets,
        private readonly bool $allowPrivateNetwork = false,
        ?callable $transport = null
    ) {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->http = new HttpClient($allowPrivateNetwork, $transport);
    }

    /**
     * Send a SCIM request and return status and decoded body. Statuses that are a failure in every
     * context are turned into a ConnectorError here; the caller decides about 404 and 409.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,string> $query
     * @return array{status: int, body: array<string,mixed>|null}
     */
    public function request(string $method, string $path, ?array $body = null, array $query = array()): array
    {
        if ($this->baseUrl === '') {
            throw ConnectorError::target('No SCIM base URL is configured.');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== array()) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $this->send($method, $url, $payload, $this->authorizationHeader());

        // An OAuth token may expire during a long run. Ask for a new one once and repeat.
        if ($response[0] === 401 && ($this->config['auth_type'] ?? '') === self::AUTH_OAUTH) {
            $this->accessToken = null;
            $response = $this->send($method, $url, $payload, $this->authorizationHeader());
        }

        [$status, $headers, $responseBody] = $response;
        $decoded = json_decode($responseBody, true);
        $decoded = is_array($decoded) ? $decoded : null;

        if ($status === 429) {
            throw ConnectorError::retry('HTTP 429: the target asks to slow down.', HttpClient::retryAfter($headers));
        }
        if ($status >= 500) {
            throw ConnectorError::retry('HTTP ' . $status . self::detail($decoded));
        }
        if ($status === 401 || $status === 403) {
            throw ConnectorError::target('HTTP ' . $status . ': the target refused the credentials.' . self::detail($decoded));
        }
        if ($status >= 300 && $status < 400) {
            throw ConnectorError::target('HTTP ' . $status . ': redirects are not followed. Check the base URL.');
        }

        return array('status' => $status, 'body' => $decoded);
    }

    /**
     * Turn a response that is not one of the expected statuses into the matching error.
     *
     * @param array{status: int, body: array<string,mixed>|null} $response
     * @param array<int,int> $expected
     */
    public static function expect(array $response, array $expected): void
    {
        $status = $response['status'];
        if (in_array($status, $expected, true)) {
            return;
        }

        $message = 'HTTP ' . $status . self::detail($response['body']);
        throw match ($status) {
            404 => ConnectorError::missing($message),
            409 => ConnectorError::conflict($message),
            default => ConnectorError::invalid($message)
        };
    }

    /**
     * The scimType and detail of a SCIM error response, shortened, as message suffix.
     */
    private static function detail(?array $body): string
    {
        if ($body === null) {
            return '';
        }
        $parts = array_filter(array((string) ($body['scimType'] ?? ''), (string) ($body['detail'] ?? '')));
        if ($parts === array()) {
            return '';
        }
        return ': ' . mb_substr(implode(' - ', $parts), 0, 300);
    }

    private function authorizationHeader(): string
    {
        return match ((string) ($this->config['auth_type'] ?? self::AUTH_BEARER)) {
            self::AUTH_BASIC => 'Basic ' . base64_encode(
                (string) ($this->config['username'] ?? '') . ':' . (string) ($this->secrets['password'] ?? '')
            ),
            self::AUTH_OAUTH => 'Bearer ' . $this->oauthToken(),
            default => 'Bearer ' . (string) ($this->secrets['token'] ?? '')
        };
    }

    /**
     * An access token from the OAuth 2.0 token endpoint (client credentials grant). It is kept in
     * memory for this run only and never stored.
     */
    private function oauthToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $tokenUrl = (string) ($this->config['oauth_token_url'] ?? '');
        if ($tokenUrl === '') {
            throw ConnectorError::target('No OAuth token URL is configured.');
        }

        $clientId = (string) ($this->config['oauth_client_id'] ?? '');
        $clientSecret = (string) ($this->secrets['oauth_client_secret'] ?? '');
        $form = array('grant_type' => 'client_credentials');
        $authorization = '';
        if (($this->config['oauth_auth_method'] ?? '') === self::OAUTH_CLIENT_SECRET_BASIC) {
            // RFC 6749 2.3.1: both parts are form-encoded before they are joined
            $authorization = 'Basic ' . base64_encode(urlencode($clientId) . ':' . urlencode($clientSecret));
        } else {
            $form['client_id'] = $clientId;
            $form['client_secret'] = $clientSecret;
        }
        if ((string) ($this->config['oauth_scope'] ?? '') !== '') {
            $form['scope'] = (string) $this->config['oauth_scope'];
        }

        [$status, , $body] = $this->send('POST', $tokenUrl, http_build_query($form), $authorization, 'application/x-www-form-urlencoded');
        $decoded = json_decode($body, true);

        if ($status >= 500 || $status === 429) {
            throw ConnectorError::retry('The OAuth token endpoint answered HTTP ' . $status . '.');
        }
        if ($status !== 200 || !is_array($decoded) || !is_string($decoded['access_token'] ?? null)) {
            throw ConnectorError::target('The OAuth token endpoint answered HTTP ' . $status . ' without an access token.');
        }

        $this->accessToken = $decoded['access_token'];
        return $this->accessToken;
    }

    /**
     * @return array{0:int, 1:array<string,string>, 2:string}
     */
    private function send(string $method, string $url, ?string $body, string $authorization, string $contentType = 'application/scim+json'): array
    {
        $headers = array('Accept: application/scim+json, application/json');
        if ($body !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        if ($authorization !== '') {
            $headers[] = 'Authorization: ' . $authorization;
        }

        return $this->http->send($method, $url, $headers, $body);
    }
}
