<?php

namespace App\Data;

final class SystemBlock
{
    public function __construct(
        public readonly string $text,
        public readonly bool $cache = false,
    ) {}
}
