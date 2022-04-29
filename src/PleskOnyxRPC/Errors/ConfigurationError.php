<?php

declare (strict_types = 1);

namespace Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC\Errors;

use Upmind\ProvisionBase\Provider\Helper\Exception\ConfigurationError as BaseConfigurationError;
use Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC\Errors\Interfaces\ProviderError;

class ConfigurationError extends BaseConfigurationError implements ProviderError
{
    
}