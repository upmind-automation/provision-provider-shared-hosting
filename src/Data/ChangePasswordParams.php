<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data used to set a new hosting account password.
 *
 * @property-read string $username Username of the account
 * @property-read string $password Desired new password
 */
class ChangePasswordParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
    }
}
