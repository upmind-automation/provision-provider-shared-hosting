<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Account/reseller usage data.
 *
 * @property-read UsageData|null $usage_data Account usage data
 * @property-read ResellerUsageData|null $reseller_usage_data Reseller usage data
 */
class AccountUsage extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'usage_data' => ['nullable', UsageData::class],
            'reseller_usage_data' => ['nullable', ResellerUsageData::class],
        ]);
    }

    /**
     * @param UsageData|array|null $usage
     */
    public function setUsageData($usage): self
    {
        $this->setValue('usage_data', $usage);
        return $this;
    }

    /**
     * @param ResellerUsageData|array|null $usage
     */
    public function setResellerUsageData($usage): self
    {
        $this->setValue('reseller_usage_data', $usage);
        return $this;
    }
}
