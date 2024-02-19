<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data for creating a new hosting account.
 *
 * @property-read string|integer|null $customer_id ID of the customer on the hosting platform
 * @property-read string|null $username Username for the new account
 * @property-read string|null $owner_username Username of the reseller "owner" of the new account
 * @property-read boolean|null $owns_itself Account should own itself (overrides $owner_username)
 * @property-read string $email Email address of the new account holder
 * @property-read string|null $customer_name Name of the customer
 * @property-read string|null $password Password for the new account
 * @property-read string|null $domain Main domain name of the new account
 * @property-read string $package_name Name/identifier of the package/tier/quota for the new account
 * @property-read boolean|null $as_reseller Whether or not the new account should have reseller privileges
 * @property-read ResellerOptionParams|null $reseller_options Additional options for resellers
 * @property-read string|null $custom_ip Custom IP address for the new account
 * @property-read string|null $location Location of the account
 */
class CreateParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'customer_id' => ['nullable'],
            'username' => ['nullable', 'string'],
            'owner_username' => ['string'],
            'owns_itself' => ['bool'],
            'email' => ['required', 'email'],
            'customer_name' => ['nullable', 'string'],
            'password' => ['string'],
            'domain' => ['nullable', 'domain_name'],
            'package_name' => ['required', 'string'],
            'as_reseller' => ['boolean'],
            'reseller_options' => [ResellerOptionParams::class],
            'custom_ip' => ['ip'],
            'location' => ['nullable', 'string']
        ]);
    }
}
