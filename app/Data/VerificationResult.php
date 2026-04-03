<?php

namespace App\Data;

class VerificationResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly array $failures,
    ) {}
}
