<?php

namespace App\Support;

class ProgressReporter
{
    private int $currentStep = 0;

    public function __construct(
        private readonly int $totalSteps,
    ) {}

    public function step(string $label): string
    {
        $this->currentStep++;

        return sprintf('[%d/%d] %s', $this->currentStep, $this->totalSteps, $label);
    }

    public function detail(string $label): string
    {
        return "      {$label}";
    }
}
