<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Exception;

/** A path from a request failed validation or left its permitted root. */
final class UnsafePathException extends \RuntimeException
{
}
