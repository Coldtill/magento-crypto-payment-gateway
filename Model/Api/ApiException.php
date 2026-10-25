<?php

declare(strict_types=1);

namespace Coldtill\Payment\Model\Api;

/** A failed Coldtill call. The code is the HTTP status, or 0 when Coldtill could not be reached. */
class ApiException extends \RuntimeException
{
}
