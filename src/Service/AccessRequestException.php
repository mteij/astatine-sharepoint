<?php
declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * The access request could not be delivered to the board channel.
 */
final class AccessRequestException extends RuntimeException
{
}
