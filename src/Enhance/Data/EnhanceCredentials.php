<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Enhance\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Enhance API credentials.
 *
 * @property-read string $hostname Enhance server hostname
 * @property-read string $email Enhance API email
 * @property-read string $password Enhance API password
 */
class EnhanceCredentials extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
    }
}
