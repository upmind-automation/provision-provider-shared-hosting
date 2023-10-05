<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\SolidCP\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * SolidCP API credentials.
 *
 * @property-read string $hostname SolidCP server hostname
 * @property-read string $port SolidCP API port
 * @property-read string $username SolidCP API username
 * @property-read string $password SolidCP API password
 * @property-read string $portal_url SolidCP portal URL
 * @property-read int $parent_space_id SpaceID that all accounts are created under
 * @property-read bool $debug Whether or not to log API requests and responses
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'port' => ['required', 'string'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'portal_url' => ['required', 'string'],
            'parent_space_id' => ['integer'],
            'socket_timeout' => ['integer', 'min:0', 'max:120'],
            'debug' => ['boolean']
        ]);
    }
}
