<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\TwentyI\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * 20i Reseller API credentials.
 *
 * @property-read string $general_api_key 20i reseller general api key
 * @property-read bool|null $auto_detect_stack_user Whether or not to auto-detect stack user by customer email address
 * @property-read string|null $oauth_client_key 20i reseller oauth client secret key
 */
class TwentyICredentials extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'general_api_key' => ['required', 'string'],
            // 'auto_detect_stack_user' => ['boolean'],
            // 'oauth_client_key' => ['string'],
        ]);
    }
}
