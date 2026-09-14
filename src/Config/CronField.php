<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Config;

use Symfony\Component\Validator\Constraint;

/**
 * A single cron field: "*", "30", "0,30", "8-17" or any of those with "/step".
 *
 * The value is written straight into /etc/cron.d, so anything unexpected here
 * would either break every cron job in the file or inject a line of its own.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class CronField extends Constraint
{
    public string $message = '{{ label }} is not a valid cron field.';

    public function __construct(
        public int $min = 0,
        public int $max = 59,
        public string $label = 'Value',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct([], $groups, $payload);
    }

    public function validatedBy(): string
    {
        return CronFieldValidator::class;
    }
}
