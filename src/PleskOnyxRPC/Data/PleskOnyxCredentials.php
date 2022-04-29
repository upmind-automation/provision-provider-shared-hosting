<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Plesk Onyx RPC API credentials.
 *
 * @property-read string|null $protocol API protocol (http or https)
 * @property-read string $hostname Plesk server hostname
 * @property-read integer|null $port API port
 * @property-read string|null $admin_username Plesk admin username
 * @property-read string|null $admin_password Plesk admin password
 * @property-read string|null $secret_key Plesk API secret key (alternative to username/password)
 * @property-read string|null $operating_system Plesk server operating system (linux or windows)
 */
class PleskOnyxCredentials extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'protocol' => ['alpha'],
            'hostname' => ['required', 'domain_name'],
            'port' => ['integer'],
            'admin_username' => ['required_without:secret_key', 'alpha_num'],
            'admin_password' => ['required_without:secret_key', 'string'],
            'secret_key' => ['required_without_all:admin_username,admin_password', 'string'],
            'operating_system' => ['in:linux,windows'],
        ]);
    }
}
