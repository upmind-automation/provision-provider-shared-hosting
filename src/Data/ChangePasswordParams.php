<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Data used to set a new hosting account password.
 *
 * @property-read string|integer|null $customer_id ID of the customer on the hosting platform
 * @property-read string|integer|null $subscription_id ID of the subscription on the hosting platform, if any
 * @property-read string $username Username of the account
 * @property-read string $password Desired new password
 */
class ChangePasswordParams extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'customer_id' => ['nullable'],
            'subscription_id' => ['nullable'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
    }

    /**
     * @param string $password
     * @return $this
     */
    public function setPassword(string $password): ChangePasswordParams
    {
        $this->setValue('password', $password);
        return $this;
    }

    /**
     * @param GetLoginUrlParams $params
     * @return $this
     */
    public function setFromLoginParams(GetLoginUrlParams $params): ChangePasswordParams
    {
        $this->setValue('customer_id', $params->current_password);
        $this->setValue('subscription_id', $params->subscription_id);
        $this->setValue('username', $params->username);
        return $this;
    }
}
