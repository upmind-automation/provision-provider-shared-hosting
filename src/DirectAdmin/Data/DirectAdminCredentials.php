<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * DirectAdmin API credentials.
 *
 * @property-read string $hostname  server hostname
 * @property-read string $username  API username
 * @property-read string $password  API secret
 */
class DirectAdminCredentials extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'username' => ['required', 'alpha_num'],
            'password' => ['required', 'string'],
        ]);
    }
}
