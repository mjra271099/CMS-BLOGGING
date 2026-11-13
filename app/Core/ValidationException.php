<?php
declare(strict_types=1);

namespace App\Core;

final class ValidationException extends \InvalidArgumentException
{
    public function __construct(string $message, private array $errors = [])
    {
        parent::__construct($message);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
