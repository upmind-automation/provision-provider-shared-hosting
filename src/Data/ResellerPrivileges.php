<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Carbon\Carbon;
use DateTime;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data set encapsulating reseller privileges.
 *
 * @property-read bool $reseller Whether reseller privileges are enabled
 */
class ResellerPrivileges extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'reseller' => ['required', 'boolean'],
        ]);
    }

    /**
     * @param bool $enabled Whether or not reseller privileges are enabled
     */
    public function setReseller(bool $enabled): self
    {
        $this->setValue('reseller', $enabled);
        return $this;
    }
}
