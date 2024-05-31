<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data representing a customer address.
 *
 * @property-read string $address_1 First line of the address
 * @property-read string|null $address_2 Second line of the address
 * @property-read string $city City of the address
 * @property-read string $state State/region of the address
 * @property-read string $postcode Postal code of the address
 * @property-read string $country_code ISO 3166-1 alpha-2 country code
 */
class CustomerAddressParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'address_1' => ['required', 'string'],
            'address_2' => ['nullable', 'string'],
            'city' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'postcode' => ['required', 'string'],
            'country_code' => ['required', 'country_code'],
        ]);
    }
}
