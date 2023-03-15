<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Example\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Example API credentials.
 *
 * @property-read string $hostname Example server hostname
 * @property-read string $api_token API token
 * @property-read bool $debug Whether or not to log API requests and responses
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'api_token' => ['required', 'string'],
            'debug' => ['boolean'],
        ]);
    }
}
