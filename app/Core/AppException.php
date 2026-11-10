<?php
// SPDX-License-Identifier: AGPL-3.0-only
declare(strict_types=1);
namespace Panel\Core;

class AppException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $errorCode = 'validation_error', public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
