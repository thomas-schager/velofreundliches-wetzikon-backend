<?php

namespace App\Service\Exception;

use RuntimeException;

/** Wrong email/password, or account inactive. Maps to HTTP 401. */
class InvalidCredentialsException extends RuntimeException
{
}
