<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data used to set a new package/tier/quota on an existing hosting account.
 *
 * @property-read string $username Username of the account
 * @property-read string $package_name Desired new account package/plan name/identifier
 * @property-read bool|null $as_reseller Whether the account should have reseller privileges
 * @property-read boolean|null $owns_itself Account should own itself
 * @property-read ResellerOptionParams|null $reseller_options Additional options for resellers
 */
class ChangePackageParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'username' => ['required', 'string'],
            'package_name' => ['required', 'string'],
            'as_reseller' => ['boolean'],
            'owns_itself' => ['boolean'],
            'reseller_options' => [ResellerOptionParams::class],
        ]);
    }
}
