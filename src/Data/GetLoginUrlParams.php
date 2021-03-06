<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data for obtaining a pre-signed login URL.
 *
 * @property-read string|integer|null $customer_id ID of the customer on the hosting platform
 * @property-read string $username Username of the account
 * @property-read string $user_ip IP of the person who wishes to log in
 * @property-read boolean|null $is_reseller Whether or not the account is a reseller
 */
class GetLoginUrlParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'customer_id' => ['nullable'],
            'username' => ['required', 'string'],
            'user_ip' => ['required', 'ip'],
            'is_reseller' => ['boolean'],
        ]);
    }
}
