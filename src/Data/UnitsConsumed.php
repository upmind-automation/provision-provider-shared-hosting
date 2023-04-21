<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Limit and consumption of an arbitrary unit.
 *
 * @property-read int|float|null $used Units used
 * @property-read int|float|null $limit Units limit, or null for unlimited
 * @property-read string $used_pc Percentage of units used, e.g. "50.5%"
 */
class UnitsConsumed extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'used' => ['nullable', 'numeric'],
            'limit' => ['nullable', 'numeric'],
            'used_pc' => ['nullable', 'string', 'regex:/^\\d+(\\.\\d+)?%$/'],
        ]);
    }

    /**
     * @param int|float|null $used
     */
    public function setUsed($used): self
    {
        $this->setValue('used', $used);
        return $this;
    }

    /**
     * @param int|float|null $limit
     */
    public function setLimit($limit): self
    {
        $this->setValue('limit', $limit);
        return $this;
    }

    /**
     * @param string $usedPc Percentage of units used, e.g. "50.5%"
     */
    public function setUsedPc($usedPc): self
    {
        $this->setValue('used_pc', $usedPc);
        return $this;
    }
}
