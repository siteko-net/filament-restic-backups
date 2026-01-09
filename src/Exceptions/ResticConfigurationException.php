<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\Exceptions;

class ResticConfigurationException extends ResticException
{
    /**
     * @var array<int, string>
     */
    private array $missing;

    /**
     * @param  array<int, string>  $missing
     */
    public function __construct(array $missing = [], string $message = 'Restic configuration is incomplete.')
    {
        parent::__construct($message);

        $this->missing = $missing;
    }

    /**
     * @return array<int, string>
     */
    public function getMissing(): array
    {
        return $this->missing;
    }
}
