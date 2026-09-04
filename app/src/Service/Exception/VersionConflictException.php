<?php

namespace App\Service\Exception;

use RuntimeException;

/** If-Match header didn't match the stored `version` -- another admin changed the row first. Maps to HTTP 409. */
class VersionConflictException extends RuntimeException
{
}
