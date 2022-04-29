<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data granting reseller privileges to an account.
 *
 * @property-read string $username Username of the account
 * @property-read boolean|null $owns_itself Account should own itself
 */
class GrantResellerParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string'],
            'owns_itself' => ['bool'],
        ]);
    }
}
