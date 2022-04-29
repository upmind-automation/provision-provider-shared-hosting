<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\WHMv1\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * WHMv1 API credentials.
 *
 * @property-read string|null $protocol API protocol (http or https)
 * @property-read string $hostname WHM server hostname
 * @property-read integer|null $port API port
 * @property-read string $whm_username WHM API username
 * @property-read string $api_key WHM API secret
 */
class WHMv1Credentials extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'protocol' => ['alpha', 'in:http,https'],
            'hostname' => ['required', 'domain_name'],
            'port' => ['integer'],
            'whm_username' => ['required', 'alpha_num'],
            'api_key' => ['required', 'string'],
        ]);
    }
}
