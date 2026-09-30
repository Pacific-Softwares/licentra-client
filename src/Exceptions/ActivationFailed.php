<?php

namespace Pacific\Licentra\Exceptions;

/**
 * The server refused the request. `$errorCode` is one of the server's v1 codes
 * (invalid_purchase_code, product_mismatch, activation_limit_reached, ...) and the
 * message is already written for the buyer, so it's safe to show directly.
 */
class ActivationFailed extends LicentraException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
