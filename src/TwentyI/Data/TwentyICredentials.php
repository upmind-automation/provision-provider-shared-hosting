<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\TwentyI\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * 20i Reseller API credentials.
 *
 * @property-read string $general_api_key 20i reseller general api key
 * @property-read string|null $control_panel_hostname 20i reseller control panel hostname
 */
class TwentyICredentials extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'general_api_key' => ['required', 'string'],
            'control_panel_hostname' => ['nullable', 'domain_name'],
        ]);
    }
}
