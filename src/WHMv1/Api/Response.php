<?php

declare (strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\WHMv1\Api;

use Upmind\ProvisionBase\Provider\Helper\Api\Response as ApiResponse;

class Response extends ApiResponse
{
    /**
     * Sets the Response message based on the returned JSON.
     */
    protected function setMessage()
    {
        $reason = $this->getBodyAssoc('metadata.reason');

        if ($reason) {
            $reason = preg_replace('#^(API failure: )?\(XID \w+\) #', '', trim((string)$reason));
        }

        $this->message = $reason
            ?? $this->getDefaultMessage();
    }

    /**
     * Determine whether HTTP code is in the 200 range, and response body
     * indicates success.
     */
    public function isSuccess(): bool
    {
        if (!$this->httpCodeIsInRange(200)) {
            return false;
        }

        return boolval(
            $this->getBodyAssoc('metadata.result') //1 for success, 0 for error
        );
    }
}
