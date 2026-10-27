<?php
namespace AdmidioPlugin\Provisioning\Connector;

use Admidio\Infrastructure\Utils\SecurityUtils;
use Throwable;

/**
 * The outbound HTTP requests of the connectors.
 *
 * Every request goes through SecurityUtils::getOutboundRequestCurlOptions(), which validates the URL
 * (HTTPS, and a public address unless private networks are allowed) and pins cURL to the checked
 * address. Redirects are never followed, so credentials cannot be sent to another host, TLS
 * certificates are always verified, and a response is read up to 1 MB.
 *
 * For tests the transport can be replaced by a callable
 * `fn(string $method, string $url, array $headers, ?string $body): array{0:int, 1:array, 2:string}`
 * that returns status, response headers (lower-case names) and body.
 */
class HttpClient
{
    private const MAX_RESPONSE_BYTES = 1048576;

    /** @var callable|null */
    private $transport;

    public function __construct(private readonly bool $allowPrivateNetwork = false, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * Send a request. A target that cannot be reached is a temporary error, a URL that is not
     * allowed an error of the target; every HTTP status is returned to the caller.
     *
     * @param array<int,string> $headers complete header lines
     * @return array{0:int, 1:array<string,string>, 2:string} status, headers, body
     */
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }
        return $this->curl($method, $url, $headers, $body);
    }

    /**
     * Turn the responses that are failures for every connector into errors: rate limits and server
     * errors are temporary, refused credentials and redirects an error of the target. Everything else
     * is left to the connector.
     *
     * @param array<string,string> $headers
     * @param string $detail Shortened explanation of the target, appended to the message.
     */
    public static function raiseForStatus(int $status, array $headers, string $detail = ''): void
    {
        $suffix = $detail === '' ? '' : ': ' . mb_substr($detail, 0, 300);

        if ($status === 429) {
            throw ConnectorError::retry('HTTP 429: the target asks to slow down.', self::retryAfter($headers));
        }
        if ($status >= 500) {
            throw ConnectorError::retry('HTTP ' . $status . $suffix);
        }
        if ($status === 401 || $status === 403) {
            throw ConnectorError::target('HTTP ' . $status . ': the target refused the credentials' . $suffix);
        }
        if ($status >= 300 && $status < 400) {
            throw ConnectorError::target('HTTP ' . $status . ': redirects are not followed. Check the base URL.');
        }
    }

    /**
     * The waiting time a Retry-After header asks for, in seconds.
     *
     * @param array<string,string> $headers
     */
    public static function retryAfter(array $headers): int
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

        if ($tooLarge) {
            throw ConnectorError::invalid('The response of the target is larger than 1 MB.');
        }
        if ($result === false || $status === 0) {
            throw ConnectorError::retry('The target could not be reached' . ($error === '' ? '.' : ': ' . $error));
        }

        return array($status, $responseHeaders, $responseBody);
    }
}
