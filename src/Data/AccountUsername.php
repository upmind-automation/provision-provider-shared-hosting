<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data for uniquely identifying a hosting account.
 *
 * @property-read string $username Username of the account
 */
class AccountUsername extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string'],
        ]);
    }
}
