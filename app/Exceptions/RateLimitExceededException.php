<?php

namespace App\Exceptions;

use Exception;

class RateLimitExceededException extends Exception
{
    // Exception khusus untuk batas tanya AI harian.
}
