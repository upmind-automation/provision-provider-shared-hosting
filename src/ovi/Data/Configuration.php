<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\ovi\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Example API credentials.
 *
 * @property-read string $hostname Plesk ovi hostname
 * @property-read string $api_token API token
 * @property-read bool $debug Whether or not to log API requests and responses
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'ip' => ['required', 'ip'],
            'adminusername' => ['required', 'string'],
            'adminpassword' => ['required', 'string'],
            'api_key' => ['required', 'string'],
           // 'api_token' => ['required', 'string'],
            'debug' => ['boolean'],
        ]);
    }
}
