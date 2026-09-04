<?php

namespace App\Service\Exception;

use RuntimeException;

/** Code/token expired. Maps to HTTP 410. */
class ExpiredChallengeException extends RuntimeException
{
}
