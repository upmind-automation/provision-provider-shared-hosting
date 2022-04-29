<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Empty result data.
 */
class EmptyResult extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules();
    }
}
