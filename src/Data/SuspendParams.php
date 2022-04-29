<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data used to suspend an existing hosting account.
 *
 * @property-read string $username Username of the account
 * @property-read string|null $reason Reason for the suspension
 */
class SuspendParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string'],
            'reason' => ['string'],
        ]);
    }
}
