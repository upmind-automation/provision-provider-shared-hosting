<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Reseller usage data.
 *
 * @property-read UnitsConsumed|null $disk_mb Disk space used in MB
 * @property-read UnitsConsumed|null $bandwidth_mb Bandwidth used in MB
 * @property-read UnitsConsumed|null $inodes Number of inodes used
 * @property-read UnitsConsumed|null $sub_accounts Number of sub-accounts used
 */
class ResellerUsageData extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'disk_mb' => ['nullable', UnitsConsumed::class],
            'bandwidth_mb' => ['nullable', UnitsConsumed::class],
            'inodes' => ['nullable', UnitsConsumed::class],
            'sub_accounts' => ['nullable', UnitsConsumed::class],
        ]);
    }

    /**
     * @param UnitsConsumed|array|null $disk
     */
    public function setDiskMb($disk): self
    {
        $this->setValue('disk_mb', $disk);
        return $this;
    }

    /**
     * @param UnitsConsumed|array|null $bandwidth
     */
    public function setBandwidthMb($bandwidth): self
    {
        $this->setValue('bandwidth_mb', $bandwidth);
        return $this;
    }

    /**
     * @param UnitsConsumed|array|null $inodes
     */
    public function setInodes($inodes): self
    {
        $this->setValue('inodes', $inodes);
        return $this;
    }

    /**
     * @param UnitsConsumed|array|null $subAccounts
     */
    public function setSubAccounts($subAccounts): self
    {
        $this->setValue('sub_accounts', $subAccounts);
        return $this;
    }
}
