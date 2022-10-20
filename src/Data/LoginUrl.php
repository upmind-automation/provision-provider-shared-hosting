<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Carbon\Carbon;
use DateTime;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data set encapsulating a login url.
 *
 * @property-read string $login_url Pre-signed url which can be used to sign in
 * @property-read string|null $for_ip IP address the url is valid for, if any
 * @property-read string|null $expires Date/time the link expires, in UTC format `Y-m-d H:i:s`
 */
class LoginUrl extends ResultData
{
    public static function rules(): Rules
    {
        return new Rules([
            'login_url' => ['required', 'url'],
            'for_ip' => ['nullable', 'ip'],
            'expires' => ['nullable', 'date_format:Y-m-d H:i:s'],
        ]);
    }

    /**
     * @param string $url Pre-signed url which can be used to sign in
     */
    public function setLoginUrl(string $url): self
    {
        $this->setValue('login_url', $url);
        return $this;
    }

    /**
     * @param string|null $ip IP address the url is valid for
     */
    public function setForIp(?string $ip): self
    {
        $this->setValue('for_ip', $ip);
        return $this;
    }

    /**
     * @param Carbon|DateTime $expires Datetime the link expires
     */
    public function setExpires(?DateTime $expires): self
    {
        $this->setValue('expires', $expires ? $expires->format('Y-m-d H:i:s') : null);
        return $this;
    }
}
