<?php
namespace AdmidioPlugin\Provisioning\Service;

use Admidio\Hooks\Hooks;
use Admidio\Infrastructure\Database;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use Throwable;

/**
 * Processes due work at the end of a request: what this request queued, a reconciliation that is
 * due after midnight, and items whose retry time has come. This is what makes changes reach the
 * target within seconds, and what keeps an installation without cron working.
 *
 * The work runs after the response has been sent. Core does not offer that yet (proposal C3, an
 * action **request_finished**); until it does, a shutdown function calls fastcgi_finish_request()
 * where PHP-FPM provides it. Whichever of the two comes first does the work, the other one finds
 * it done. Without PHP-FPM the visitor waits for the time budget at most.
 */
final class RequestRunner
{
    public const CORE_HOOK = 'request_finished';

    private static bool $registered = false;
    private static bool $done = false;
    private static bool $queued = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        Hooks::addAction(self::CORE_HOOK, array(self::class, 'run'), 50, null, 'provisioning-request-finished');
        register_shutdown_function(array(self::class, 'run'));
    }

    /**
     * Something was queued in this request.
     */
    public static function markQueued(): void
    {
        self::$queued = true;
    }

    /**
     * Do not process anything at the end of this request, for example because the request
     * processed the queue itself.
     */
    public static function skip(): void
    {
        self::$done = true;
    }

    public static function run(): void
    {
        global $gDb, $gCurrentOrgId, $gLogger;

        if (self::$done) {
            return;
        }
        self::$done = true;

        try {
            if (!isset($gDb) || !$gDb instanceof Database || (int) ($gCurrentOrgId ?? 0) === 0) {
                return;
            }
            // A transaction that is still open here is rolled back when the connection closes,
            // and everything written now would be rolled back with it.
            if ($gDb->isInTransaction()) {
                return;
            }

            $budget = (int) ProvisioningPlugin::setting(ProvisioningPlugin::SETTING_REQUEST_BUDGET);
            if ($budget <= 0) {
                return;
            }

            $scheduler = new Scheduler($gDb);
            if (!self::$queued && !$scheduler->hasDueWork()) {
                return;
            }

            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                ignore_user_abort(true);
                fastcgi_finish_request();
            }

            $scheduler->run(array('budget' => (float) $budget));
        } catch (Throwable $exception) {
            if (isset($gLogger)) {
                $gLogger->warning('Provisioning: processing at the end of the request failed.', array(
                    'message' => $exception->getMessage()
                ));
            }
        }
    }
}
