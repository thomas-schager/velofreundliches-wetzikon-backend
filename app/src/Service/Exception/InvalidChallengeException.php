<?php

namespace App\Service\Exception;

use RuntimeException;

/** Unknown challenge token, wrong code, or too many attempts. Maps to HTTP 401. */
class InvalidChallengeException extends RuntimeException
{
}
