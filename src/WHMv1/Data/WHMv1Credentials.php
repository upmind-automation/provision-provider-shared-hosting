<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\WHMv1\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;
use Upmind\ProvisionProviders\SharedHosting\WHMv1\Softaculous\SoftaculousSdk;

/**
 * WHMv1 API credentials.
 *
 * @property-read string $hostname WHM server hostname
 * @property-read string $whm_username WHM API username
 * @property-read string $api_key WHM API secret
 * @property-read bool|null $debug Whether to enable logging of API requests + responses
 * @property string|null $install_software Software to install upon account creation
 */
class WHMv1Credentials extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'hostname' => ['required', 'domain_name'],
            'whm_username' => ['required', 'alpha_num'],
            'api_key' => ['required', 'string'],
            'debug' => ['bool'],
            'install_software' => [
                'nullable',
                'in:,' . implode(',', array_keys(SoftaculousSdk::SOFTWARE_IDS)),
            ],
        ]);
    }
}
