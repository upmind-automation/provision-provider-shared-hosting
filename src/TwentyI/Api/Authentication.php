<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\TwentyI\Api;

use TwentyI\API\Authentication as APIAuthentication;
use Upmind\ProvisionProviders\SharedHosting\TwentyI\Api\Traits\LogsRequests;

/**
 * TwentyI\API\Authentication decorator which can logs request and response data.
 */
class Authentication extends APIAuthentication
{
    use LogsRequests;
}
