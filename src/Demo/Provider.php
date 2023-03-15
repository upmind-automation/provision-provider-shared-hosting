<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Demo;

use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\SharedHosting\Category;
use Upmind\ProvisionProviders\SharedHosting\Data\CreateParams;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountInfo;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountUsername;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePackageParams;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePasswordParams;
use Upmind\ProvisionProviders\SharedHosting\Data\EmptyResult;
use Upmind\ProvisionProviders\SharedHosting\Data\GetLoginUrlParams;
use Upmind\ProvisionProviders\SharedHosting\Data\GrantResellerParams;
use Upmind\ProvisionProviders\SharedHosting\Data\LoginUrl;
use Upmind\ProvisionProviders\SharedHosting\Data\ResellerPrivileges;
use Upmind\ProvisionProviders\SharedHosting\Data\SuspendParams;
use Upmind\ProvisionProviders\SharedHosting\Demo\Data\Configuration;

/**
 * Stateless demo provider which doesn't actually provision anything, but will pretend to.
 */
class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Demo Provider')
            ->setDescription('Demo provider which doesn\'t actually provision anything, but will pretend to');
    }

    /**
     * @inheritDoc
     */
    public function create(CreateParams $params): AccountInfo
    {
        $customerId = $params->customer_id ?? uniqid();
        $subscriptionId = uniqid();
        $username = $params->username ?? $params->email;

        $info = $this->getAccountInfo(
            $username,
            $customerId,
            $subscriptionId,
            $params->domain
        );

        return $info->setMessage('Demo account created')
            ->setPackageName($params->package_name)
            ->setReseller($params->as_reseller);
    }

    /**
     * @inheritDoc
     */
    public function getInfo(AccountUsername $params): AccountInfo
    {
        return $this->getAccountInfo(
            $params->username,
            $params->customer_id,
            $params->subscription_id,
            $params->domain
        )->setMessage('Demo account info retrieved');
    }

    /**
     * @inheritDoc
     */
    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        return LoginUrl::create()
            ->setMessage('Demo login URL generated')
            ->setLoginUrl(sprintf('https://%s.com/auth/login/example', $this->configuration->hostname));
    }

    /**
     * @inheritDoc
     */
    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        return $this->emptyResult('Demo password changed');
    }

    /**
     * @inheritDoc
     */
    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        $info = $this->getAccountInfo(
            $params->username,
            $params->customer_id,
            $params->subscription_id,
            $params->domain
        );
        return $info->setMessage('Demo package changed')
            ->setPackageName($params->package_name)
            ->setReseller($params->as_reseller);
    }

    /**
     * @inheritDoc
     */
    public function suspend(SuspendParams $params): AccountInfo
    {
        $info = $this->getAccountInfo(
            $params->username,
            $params->customer_id,
            $params->subscription_id,
            $params->domain
        );
        return $info->setMessage('Demo account suspended')
            ->setSuspended(true)
            ->setSuspendReason($params->reason);
    }

    /**
     * @inheritDoc
     */
    public function unSuspend(AccountUsername $params): AccountInfo
    {
        $info = $this->getAccountInfo(
            $params->username,
            $params->customer_id,
            $params->subscription_id,
            $params->domain
        );
        return $info->setMessage('Demo account unsuspended')
            ->setSuspended(false);
    }

    /**
     * @inheritDoc
     */
    public function terminate(AccountUsername $params): EmptyResult
    {
        return $this->emptyResult('Demo account terminated');
    }

    /**
     * @inheritDoc
     */
    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        return ResellerPrivileges::create()
            ->setMessage('Demo reseller privileges granted')
            ->setReseller(true);
    }

    /**
     * @inheritDoc
     */
    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        return ResellerPrivileges::create()
            ->setMessage('Demo reseller privileges revoked')
            ->setReseller(true);
    }

    /**
     * @param string $username
     * @param string|int|null $customerId
     * @param string|int|null $subscriptionId
     * @param string|null $domain
     */
    protected function getAccountInfo(
        string $username,
        $customerId,
        $subscriptionId,
        ?string $domain
    ): AccountInfo {
        return AccountInfo::create()
            ->setMessage('Demo account info obtained')
            ->setCustomerId($customerId)
            ->setSubscriptionId($subscriptionId)
            ->setDomain($domain)
            ->setUsername($username)
            ->setServerHostname($this->configuration->hostname)
            ->setPackageName('Demo Hosting Package')
            ->setReseller(false)
            ->setSuspended(false);
    }
}
