<?php

namespace App\Services\SSH;

class SshResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }

    public function getOutput(): string
    {
        return trim($this->output);
    }

    public function getErrorOutput(): string
    {
        return trim($this->errorOutput);
    }
}

