<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Demo\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Demo API credentials.
 *
 * @property-read string $hostname Demo server hostname
 * @property-read string $api_token API token
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'api_token' => ['required', 'string'],
        ]);
    }
}
