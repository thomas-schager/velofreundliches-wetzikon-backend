<?php

namespace App\Service\Exception;

use RuntimeException;

/** No report with that id. Maps to HTTP 404. */
class ReportNotFoundException extends RuntimeException
{
}
