<?php

namespace App\Data;

class PrefilterResult
{
    public function __construct(
        public readonly array $accepted,
        public readonly array $rejected,
    ) {}
}
