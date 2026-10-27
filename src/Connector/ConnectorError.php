<?php
namespace AdmidioPlugin\Provisioning\Connector;

use RuntimeException;
use Throwable;

/**
 * An error of a connector, classified so that the worker can treat all connectors the same way.
 *
 * The message is shown to administrators and stored in the queue. It must never contain
 * credentials or the full payload of a request.
 */
final class ConnectorError extends RuntimeException
{
    /** Temporary: timeout, connection error, server error, rate limit. Retried with backoff. */
    public const RETRY = 'retry';
    /** The target as a whole does not work: authentication, configuration. The target is paused. */
    public const TARGET = 'target';
    /** The identity is ambiguous or taken by another resource. Needs an administrator. */
    public const CONFLICT = 'conflict';
    /** The target rejected the data. Needs an administrator or a change of the data. */
    public const INVALID = 'invalid';
    /** The remote resource does not exist (any more). The worker looks it up again. */
    public const MISSING = 'missing';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly int $retryAfter = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function retry(string $message, int $retryAfter = 0): self
    {
        return new self(self::RETRY, $message, $retryAfter);
    }

    public static function target(string $message): self
    {
        return new self(self::TARGET, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(self::CONFLICT, $message);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message);
    }

    public static function missing(string $message): self
    {
        return new self(self::MISSING, $message);
    }
}
