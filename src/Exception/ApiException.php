<?php

declare(strict_types=1);

namespace BeaconBox\Exception;

/** An error the API returned, carrying its machine-readable code. */
class ApiException extends BeaconBoxException
{
    /**
     * @param array<string, mixed> $body
     * @param int|null             $retryAfterMs Milliseconds the server asked you to wait, from its
     *                                          `Retry-After` header, or null if it sent none. Set
     *                                          on whatever status carried the header — usually a
     *                                          429, sometimes a 503. It survives onto the exception
     *                                          because the SDK may deliberately *not* have waited
     *                                          it out: see
     *                                          {@see \BeaconBox\RetryPolicy::shouldRetry()}.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $errorCode = null,
        public readonly array $body = [],
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfterMs = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The `X-Request-Id` the API answered with, or null if the response carried none.
     *
     * Every response carries one. **Quote it to support**: it is the one value that finds this
     * request in BeaconBox's logs. It is also in the exception message, so a logged exception
     * already has it. The same value as the public `$requestId` property.
     */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }
}
