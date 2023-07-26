<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\InterWorx\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * InterWorx API credentials.
 * @property-read string $hostname InterWorx server hostname
 * @property-read string $port InterWorx server port
 * @property-read string $username InterWorx username
 * @property-read string $password InterWorx password
 * @property-read bool|null $debug Whether or not to enable debug logging
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'port' => ['required', 'integer'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'debug' => ['boolean'],
        ]);
    }
}
