<?php
namespace AdmidioPlugin\Provisioning\Tests\Unit;

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\HttpClient;

require_once dirname(__DIR__) . '/Support/autoload.php';

/**
 * The errors of requests that got no response.
 */
final class HttpClientTest extends AdmidioTestCase
{
    public function testATargetThatDoesNotAnswerInTimeIsReportedAsSlow(): void
    {
        $error = HttpClient::transportError(CURLE_OPERATION_TIMEDOUT, true, 5, 'Operation timed out after 5001 milliseconds with 0 bytes received');

        $this->assertSame(ConnectorError::RETRY, $error->kind);
        $this->assertSame('The target did not answer within 5 seconds: Operation timed out after 5001 milliseconds with 0 bytes received', $error->getMessage());
    }

    public function testATimeoutBeforeTheConnectionIsReportedAsUnreachable(): void
    {
        $error = HttpClient::transportError(CURLE_OPERATION_TIMEDOUT, false, 5, 'Connection timed out after 3001 milliseconds');

        $this->assertSame(ConnectorError::RETRY, $error->kind);
        $this->assertSame('The target could not be reached: Connection timed out after 3001 milliseconds', $error->getMessage());
    }

    public function testARefusedConnectionIsReportedAsUnreachable(): void
    {
        $error = HttpClient::transportError(CURLE_COULDNT_CONNECT, false, 15, '');

        $this->assertSame('The target could not be reached.', $error->getMessage());
    }
}
