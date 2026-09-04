<?php

namespace App\Service\Exception;

use RuntimeException;

/** Request body failed validation. Maps to HTTP 400. */
class ValidationException extends RuntimeException
{
    /** @param string[] $errors */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
