<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Option data for creating/updating a new reseller account.
 *
 * @property-read string|null $acl_name Name of an ACL to assign the reseller to
 * @property-read integer|null $account_limit Number of accounts the reseller can create (null for unlimited)
 * @property-read integer|null $diskspace_mb_limit Disk space limit in MB (null for unlimited)
 * @property-read bool|null $diskspace_overselling Whether or not to allow disk space overselling
 * @property-read integer|null $bandwidth_mb_limit Bandwidth limit in MB (null for unlimited)
 * @property-read bool|null $bandwidth_overselling Whether or not to allow bandwidth overselling
 */
class ResellerOptionParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'acl_name' => ['nullable', 'string'],
            'account_limit' => ['nullable', 'integer'],
            'diskspace_mb_limit' => ['nullable', 'integer'],
            'diskspace_overselling' => ['nullable', 'boolean'],
            'bandwidth_mb_limit' => ['nullable', 'integer'],
            'bandwidth_overselling' => ['nullable', 'boolean'],
        ]);
    }
}
