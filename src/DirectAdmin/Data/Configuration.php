<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * DirectAdmin API credentials.
 * @property-read string $hostname DirectAdmin server hostname
 * @property-read int|null $port DirectAdmin serves port
 * @property-read string $username DirectAdmin username
 * @property-read string $password DirectAdmin password
 * @property-read string|null $ip_status type of ip status
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'port' => ['nullable', 'integer'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'ip_status' => ['string', 'in:auto,server,shared,free'],
        ]);
    }
}
