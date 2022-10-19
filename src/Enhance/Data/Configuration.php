<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Enhance\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Enhance API credentials.
 *
 * @property-read string $hostname Enhance server hostname
 * @property-read string $org_id Enhance organisation id
 * @property-read string $access_token API access token
 * @property-read bool|null $debug Whether or not to enable debug logging of API requests
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'org_id' => ['required', 'string'],
            'access_token' => ['required', 'string'],
            'debug' => ['boolean'],
        ]);
    }
}
