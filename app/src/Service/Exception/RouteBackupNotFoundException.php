<?php

namespace App\Service\Exception;

use RuntimeException;

/** No route backup with that id, or its snapshot file is missing on disk. Maps to HTTP 404. */
class RouteBackupNotFoundException extends RuntimeException
{
}
