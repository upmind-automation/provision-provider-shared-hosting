<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\TwentyI\Api;

use TwentyI\API\Services as APIServices;
use Upmind\ProvisionProviders\SharedHosting\TwentyI\Api\Traits\LogsRequests;

/**
 * TwentyI\API\Services decorator which can logs request and response data.
 */
class Services extends APIServices
{
    use LogsRequests;

    /**
     * @inheritDoc
     */
    public function singleSignOn($token, $domain_name = null)
    {
        $control_panel = new ControlPanel($token);
        $control_panel->setLogger($this->logger);
        $customisations = $this->getWithFields("/reseller/*/customisations");
        return $control_panel->singleSignOn($customisations, $domain_name);
    }
}
