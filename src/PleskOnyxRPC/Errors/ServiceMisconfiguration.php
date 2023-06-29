<?php

declare (strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC\Errors;

use Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC\Errors\Interfaces\ProviderError;

class ServiceMisconfiguration extends \RuntimeException implements ProviderError
{
    public static function forNoSharedIps(): ProviderError
    {
        return new static("Plesk server has no shared IPs");
    }
}
