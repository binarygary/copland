<?php

namespace App\Exceptions;

use RuntimeException;

class PolicyViolationException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
