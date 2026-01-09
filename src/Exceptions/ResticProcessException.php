<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Exceptions;

use Siteko\FilamentResticBackups\DTO\ProcessResult;

class ResticProcessException extends ResticException
{
    public function __construct(private ProcessResult $result, string $message = '')
    {
        if ($message === '') {
            $message = "Restic process failed with exit code {$result->exitCode}.";
        }

        parent::__construct($message);
    }

    public function getResult(): ProcessResult
    {
        return $this->result;
    }
}
