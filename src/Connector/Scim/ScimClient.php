<?php
namespace AdmidioPlugin\Provisioning\Connector\Scim;

use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use Throwable;

/**
 * HTTP access to a SCIM service: URL building, authentication, the cURL transport and the
 * classification of failed responses.
 *
 * Every request goes through SecurityUtils::getOutboundRequestCurlOptions(), which validates the URL
 * and pins cURL to the checked address. Redirects are never followed, so credentials cannot be sent
 * to another host.
 *
 * For tests the transport can be replaced by a callable
 * `fn(string $method, string $url, array $headers, ?string $body): array{0:int, 1:array, 2:string}`
 * that returns status, response headers (lower-case names) and body.
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

    private const MAX_RESPONSE_BYTES = 1048576;

    private string $baseUrl;

    /** @var callable|null */
    private $transport;

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
        $this->transport = $transport;
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
            throw ConnectorError::retry('HTTP 429: the target asks to slow down.', self::retryAfter($headers));
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

    /**
     * @param array<string,string> $headers
     */
    private static function retryAfter(array $headers): int
    {
        $value = trim((string) ($headers['retry-after'] ?? ''));
        if ($value === '') {
            return 0;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $time = strtotime($value);
        return $time === false ? 0 : max(0, $time - time());
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

        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }
        return $this->curl($method, $url, $headers, $body);
    }

    /**
     * @param array<int,string> $headers
     * @return array{0:int, 1:array<string,string>, 2:string}
     */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        try {
            $pinning = SecurityUtils::getOutboundRequestCurlOptions($url, $this->allowPrivateNetwork);
        } catch (Throwable) {
            throw ConnectorError::target('The URL ' . $url . ' is not allowed. HTTPS is required, and private networks only if the plugin settings allow them.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw ConnectorError::retry('The HTTP request could not be initialized.');
        }

        $responseBody = '';
        $tooLarge = false;
        $responseHeaders = array();
        $isCli = PHP_SAPI === 'cli';

        curl_setopt_array($curl, $pinning + array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $isCli ? 15 : 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            }
        ));
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($tooLarge) {
            throw ConnectorError::invalid('The response of the target is larger than 1 MB.');
        }
        if ($result === false || $status === 0) {
            throw ConnectorError::retry('The target could not be reached' . ($error === '' ? '.' : ': ' . $error));
        }

        return array($status, $responseHeaders, $responseBody);
    }
}
