<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Software installation identifier params.
 *
 * @property-read string|integer|null $software_id ID or name of the software
 * @property-read string|integer|null $install_id ID of the software installation
 */
class SoftwareInstallationIdentifier extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'software_id' => ['nullable'],
            'install_id' => ['nullable'],
        ]);
    }
}
