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
 * @property-read string|null $softaculous_install Software to install upon account creation
 * @property-read string|null $sso_destination Which control panel to log into, either cPanel/WHM or Softaculous SSO
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
            'softaculous_install' => [
                'nullable',
                'in:,' . implode(',', array_keys(SoftaculousSdk::SOFTWARE_IDS)),
            ],
            'sso_destination' => ['nullable', 'in:control_panel,softaculous_sso,auto'],
        ]);
    }
}
