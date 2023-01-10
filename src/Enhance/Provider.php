<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Enhance;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use Illuminate\Support\Arr;
use Throwable;
use Upmind\EnhanceSdk\ApiException;
use Upmind\EnhanceSdk\Model\DomainIp;
use Upmind\EnhanceSdk\Model\LoginInfo;
use Upmind\EnhanceSdk\Model\Member;
use Upmind\EnhanceSdk\Model\NewCustomer;
use Upmind\EnhanceSdk\Model\NewMember;
use Upmind\EnhanceSdk\Model\NewSubscription;
use Upmind\EnhanceSdk\Model\NewWebsite;
use Upmind\EnhanceSdk\Model\PhpVersion;
use Upmind\EnhanceSdk\Model\Plan;
use Upmind\EnhanceSdk\Model\Role;
use Upmind\EnhanceSdk\Model\ServerIp;
use Upmind\EnhanceSdk\Model\Status;
use Upmind\EnhanceSdk\Model\UpdateSubscription;
use Upmind\EnhanceSdk\Model\UpdateWebsite;
use Upmind\EnhanceSdk\Model\Website;
use Upmind\EnhanceSdk\Model\WebsiteAppKind;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
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
use Upmind\ProvisionProviders\SharedHosting\Enhance\Data\Configuration;

class Provider extends Category implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var mixed[]|null
     */
    protected $meta;

    /**
     * @var Api
     */
    protected $api;

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Enhance')
            ->setDescription('Create and manage Enhance accounts and resellers using the Enhance API')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/enhance-logo@2x.png');
    }

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function create(CreateParams $params): AccountInfo
    {
        try {
            $plan = $this->findPlan($params->package_name);

            if ($customerId = $params->customer_id) {
                $email = $this->findOwnerMember($customerId, $params->email)->getEmail();
            } else {
                $customerId = $this->createCustomer(
                    $params->customer_name ?? $params->email,
                    $params->email,
                    $params->password ?: $this->generateRandomPassword()
                );
                $email = $params->email;
            }

            $subscriptionId = $this->createSubscription($customerId, $plan->getId());

            if ($params->domain) {
                $this->createWebsite($customerId, $subscriptionId, $params->domain);
            }

            return $this->getSubscriptionInfo($customerId, $subscriptionId, $params->domain, $email)
                ->setMessage('Website Created');
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function suspend(SuspendParams $params): AccountInfo
    {
        try {
            if (!$params->subscription_id) {
                throw $this->errorResult('Subscription ID is required');
            }

            $customerId = $params->customer_id ?: $this->findCustomerIdByEmail($params->username);

            $updateSubscription = (new UpdateSubscription())
                ->setIsSuspended(true);

            $this->api()->subscriptions()->updateSubscription(
                $customerId,
                $params->subscription_id,
                $updateSubscription
            );

            $info = $this->getSubscriptionInfo(
                $customerId,
                intval($params->subscription_id),
                $params->domain,
                $params->username
            );

            return $info->setMessage('Subscription suspended')
                ->setSuspendReason($params->reason);
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function unSuspend(AccountUsername $params): AccountInfo
    {
        try {
            if (!$params->subscription_id) {
                throw $this->errorResult('Subscription ID is required');
            }

            $customerId = $params->customer_id ?: $this->findCustomerIdByEmail($params->username);

            $updateSubscription = (new UpdateSubscription())
                ->setIsSuspended(false);

            $this->api()->subscriptions()->updateSubscription(
                $customerId,
                $params->subscription_id,
                $updateSubscription
            );

            $info = $this->getSubscriptionInfo(
                $customerId,
                intval($params->subscription_id),
                $params->domain,
                $params->username
            );

            return $info->setMessage('Subscription unsuspended')
                ->setSuspendReason(null);
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function terminate(AccountUsername $params): EmptyResult
    {
        try {
            if (!$params->subscription_id) {
                throw $this->errorResult('Subscription ID is required');
            }

            $customerId = $params->customer_id ?: $this->findCustomerIdByEmail($params->username);

            $this->api()->subscriptions()
                ->deleteSubscription($customerId, $params->subscription_id, 'false');

            return $this->emptyResult('Subscription deleted');
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function getInfo(AccountUsername $params): AccountInfo
    {
        try {
            $customerId = $params->customer_id ?: $this->findCustomerIdByEmail($params->username);
            $subscriptionId = intval($params->subscription_id) ?: null;

            return $this->getSubscriptionInfo(
                $customerId,
                $subscriptionId,
                $params->domain,
                $params->username
            );
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        try {
            $customerId = $params->customer_id ?: $this->findCustomerIdByEmail($params->username);
            $subscriptionId = intval($params->subscription_id) ?: null;

            $loginUrl = $this->getSsoUrl($customerId, $subscriptionId, $params->domain);

            return LoginUrl::create()
                ->setLoginUrl($loginUrl);
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        try {
            if (!$params->customer_id) {
                throw $this->errorResult('Customer ID is required');
            }

            $owner = $this->findOwnerMember($params->customer_id, $params->username);

            $this->api()->logins()->startPasswordRecovery(
                ['email' => $owner->getEmail()],
                $params->customer_id
            );

            return $this->emptyResult('Password reset initiated - please check your email');
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        try {
            if (!$params->subscription_id) {
                throw $this->errorResult('Subscription ID is required');
            }

            $customerId = $params->customer_id ?: $this->findCustomerIdByEmail($params->username);

            $plan = $this->findPlan($params->package_name);

            $updateSubscription = (new UpdateSubscription())
                ->setPlanId($plan->getId());

            $this->api()->subscriptions()->updateSubscription(
                $customerId,
                $params->subscription_id,
                $updateSubscription
            );

            $info = $this->getSubscriptionInfo(
                $customerId,
                intval($params->subscription_id),
                $params->domain,
                $params->username
            );

            return $info->setMessage('Subscription plan updated');
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        throw $this->errorResult('Operation not supported');
    }

    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * Determine whether this configuration's Enhance CP is the given version or greater.
     */
    protected function isEnhanceVersion(string $requireVersion): bool
    {
        $version = $this->getEnhanceMeta()['version'];

        return true === version_compare($version, $requireVersion, '>=');
    }

    /**
     * Assert that this configuration's Enhance CP is the given version or greater.
     *
     * @throws ProvisionFunctionError
     */
    protected function requireEnhanceVersion(string $requireVersion, string $operation = 'this operation'): void
    {
        if (!$this->isEnhanceVersion($requireVersion)) {
            throw $this->errorResult(
                sprintf('Control panel v%s is required for %s', $requireVersion, $operation)
            );
        }
    }

    /**
     * Get Enhance CP status and version.
     */
    protected function getEnhanceMeta(): array
    {
        if (isset($this->meta)) {
            return $this->meta;
        }

        $requests = [
            'status' => $this->api()->install()->orchdStatusAsync()->then(function ($status) {
                return json_decode($status) ?? $status;
            }),
            'version' => $this->api()->install()->orchdVersionAsync(),
        ];

        return PromiseUtils::all($requests)
            ->then(function ($meta) {
                $this->meta = $meta;
                return $meta;
            })
            ->wait();
    }

    protected function findCustomerIdByEmail(string $email): string
    {
        $offset = 0;
        $limit = 20;

        do {
            $customers = $this->api()->customers()->getOrgCustomers($this->configuration->org_id, $offset, $limit);
            $offset += $limit;

            foreach ($customers->getItems() as $customer) {
                if ($email === $customer->getOwnerEmail()) {
                    return $customer->getId();
                }
            }
        } while ($offset + $limit < $customers->getTotal());

        throw $this->errorResult('Customer not found', ['email' => $email]);
    }

    protected function getSubscriptionInfo(
        string $customerId,
        ?int $subscriptionId,
        ?string $domain,
        ?string $email = null
    ): AccountInfo {
        $website = $this->findWebsite($customerId, $subscriptionId, $domain);

        $subscription = $this->api()->subscriptions()
            ->getSubscription($customerId, $subscriptionId ?? $website->getSubscriptionId());

        if ($subscription->getStatus() === Status::DELETED) {
            throw $this->errorResult('Subscription terminated', ['subscription' => $subscription->jsonSerialize()]);
        }

        $nameservers = array_map(function (DomainIp $ns) {
            return $ns->getDomain();
        }, $this->api()->branding()->getBranding($this->configuration->org_id)->getNameServers());

        return AccountInfo::create()
            ->setMessage('Subscription info obtained')
            ->setCustomerId($customerId)
            ->setUsername($email ?? $this->findOwnerMember($customerId)->getEmail())
            ->setSubscriptionId($subscription->getId())
            ->setDomain($website ? $website->getDomain()->getDomain() : null)
            ->setServerHostname($this->configuration->hostname)
            ->setPackageName($subscription->getPlanName())
            ->setSuspended(boolval($subscription->getSuspendedBy()))
            ->setIp($website ? implode(', ', $this->getWebsiteIps($website)) : null)
            ->setNameservers($nameservers)
            ->setDebug([
                'website' => $website ? $website->jsonSerialize() : null,
                'subscription' => $subscription->jsonSerialize(),
            ]);
    }

    protected function findWebsite(string $customerId, ?int $subscriptionId = null, ?string $domain = null): ?Website
    {
        if (!$subscriptionId && !$domain) {
            throw $this->errorResult('Website domain name is required without subscription id');
        }

        $result = $this->api()->websites()->getWebsites(
            $customerId,
            null,
            null,
            null,
            null,
            $domain,
            null,
            null,
            $subscriptionId,
            null,
            null,
            null,
            null,
            null,
            'false'
        );

        $websites = $result->getItems();

        if (isset($domain)) {
            $websites = array_filter($websites, function (Website $website) use ($domain) {
                return strcasecmp($domain, $website->getDomain()->getDomain()) === 0;
            });

            if (count($websites) !== 1) {
                throw $this->errorResult(sprintf('Found %s websites for the given domain', count($websites)), [
                    'customer_id' => $customerId,
                    'subscription_id' => $subscriptionId,
                    'domain' => $domain,
                ]);
            }
        }

        /** @var Website $website */
        if (!$website = Arr::first($websites)) {
            return null;
        }

        // get website again to receive full object including IPs
        return $this->api()->websites()->getWebsite($customerId, $website->getId());
    }

    /**
     * @return string[]
     */
    public function getWebsiteIps(Website $website): array
    {
        if ($website->getServerIps()) {
            return array_map(function (ServerIp $ip) {
                return $ip->getIp();
            }, $website->getServerIps());
        }

        $offset = 0;
        $limit = 10;

        while (true) {
            $servers = $this->api()->servers()->getServers($offset, $limit);

            foreach ($servers->getItems() as $server) {
                if ($website->getAppServerId() === $server->getId()) {
                    return array_map(function (ServerIp $ip) {
                        return $ip->getIp();
                    }, $server->getIps());
                }
            }

            if ($servers->getTotal() <= ($offset + $limit)) {
                break;
            }

            $offset += $limit;
        }

        return []; // IPs unknown
    }

    /**
     * Finds the owner member of the given customer id, preferring the given
     * email if it exists.
     */
    protected function findOwnerMember(string $customerId, ?string $email = null): Member
    {
        $firstMember = null;
        $offset = 0;
        $limit = 10;

        while (true) {
            $members = $this->api()->members()->getMembers(
                $customerId,
                $offset,
                $limit,
                null,
                null,
                null,
                Role::OWNER
            );

            foreach ($members->getItems() as $member) {
                if (is_null($email) || $member->getEmail() === $email) {
                    return $member;
                }

                if (is_null($firstMember)) {
                    $firstMember = $member;
                }
            }

            if ($members->getTotal() <= ($offset + $limit)) {
                break;
            }

            $offset += $limit;
        }

        if (is_null($firstMember)) {
            throw $this->errorResult('Customer login not found', [
                'customer_id' => $customerId,
            ]);
        }

        return $firstMember;
    }

    /**
     * Create a new customer org, login and owner membership and return the customer id.
     */
    protected function createCustomer(string $name, string $email, string $password): string
    {
        $newCustomer = (new NewCustomer())
            ->setName($name);
        $customer = $this->api()->customers()
            ->createCustomer($this->configuration->org_id, $newCustomer);

        if (!$customerId = $customer->getId()) {
            throw $this->errorResult('Failed to create new customer', $this->getLastGuzzleRequestDebug() ?? []);
        }

        try {
            $newLogin = (new LoginInfo())
                ->setName($name)
                ->setEmail($email)
                ->setPassword($password);
            $loginId = $this->api()->logins()
                ->createLogin($customerId, $newLogin)
                ->getId();
        } catch (ApiException $e) {
            try {
                $this->api()->orgs()->deleteOrg($customerId, 'false');
            } finally {
                throw $this->handleException(
                    $e,
                    ['new_customer_id' => $customerId, 'email' => $email],
                    [],
                    'Failed to create login for new customer'
                );
            }
        }

        $newMember = (new NewMember())
            ->setLoginId($loginId)
            ->setRoles([
                Role::OWNER,
            ]);
        $this->api()->members()
            ->createMember($customerId, $newMember);

        return $customerId;
    }

    /**
     * Create a new subscription and return the id.
     */
    protected function createSubscription(string $customerId, int $planId): int
    {
        $newSubscription = (new NewSubscription())
            ->setPlanId($planId);

        return $this->api()->subscriptions()
            ->createCustomerSubscription($this->configuration->org_id, $customerId, $newSubscription)
            ->getId();
    }

    /**
     * Create a new website and return the id.
     */
    protected function createWebsite(string $customerId, int $subscriptionId, string $domain): string
    {
        $newWebsite = (new NewWebsite())
            ->setSubscriptionId($subscriptionId)
            ->setDomain($domain);

        $websiteId = $this->api()->websites()
            ->createWebsite($customerId, $newWebsite)
            ->getId();

        $updateWebsite = (new UpdateWebsite())
            ->setPhpVersion(PhpVersion::PHP74);

        $this->api()->websites()->updateWebsite($customerId, $websiteId, $updateWebsite);

        return $websiteId;
    }

    protected function findPlan(string $packageName): Plan
    {
        if (is_numeric($packageName = trim($packageName))) {
            $packageName = intval($packageName);
        }

        $offset = 0;
        $limit = 10;

        while (true) {
            $plans = $this->api()->plans()->getPlans($this->configuration->org_id, $offset, $limit);

            foreach ($plans->getItems() as $plan) {
                if (is_int($packageName) && $packageName === $plan->getId()) {
                    return $plan;
                }

                if (is_string($packageName) && $packageName === trim($plan->getName())) {
                    return $plan;
                }
            }

            if ($plans->getTotal() <= ($offset + $limit)) {
                throw $this->errorResult('Plan not found', [
                    'plan' => $packageName,
                ]);
            }

            $offset += $limit;
        }
    }

    protected function getSsoUrl(string $customerId, ?int $subscriptionId = null, ?string $domain = null): string
    {
        if ($website = $this->findWebsite($customerId, $subscriptionId, $domain ?: null)) {
            $websiteId = $website->getId();
        }

        if (strtolower((string)$this->configuration->sso_destination) === 'wordpress') {
            $this->requireEnhanceVersion('8.0.0', 'wordpress login');

            if (!$websiteId) {
                throw $this->errorResult('Website not found', [
                    'customer_id' => $customerId,
                    'subscription_id' => $subscriptionId,
                ]);
            }

            return $this->getWordpressLoginUrl($customerId, $websiteId);
        }

        return $this->getEnhanceLoginUrl($customerId, $websiteId ?? null);
    }

    protected function getEnhanceLoginUrl(string $customerId, ?string $websiteId = null): string
    {
        if (!$this->isEnhanceVersion('8.2.0')) {
            // feature not present / not working prior to v8.2.0 - just redirect them to the panel
            return sprintf('https://%s/websites/%s', $this->configuration->hostname, $websiteId ?? null);
        }

        $url = $this->api()->members()->getOrgMemberLogin($customerId, $this->findOwnerMember($customerId)->getId());

        return json_decode($url) ?? $url;
    }

    protected function getWordpressLoginUrl(string $customerId, string $websiteId): string
    {
        $appId = $this->getWordpressAppId($customerId, $websiteId);

        try {
            $wpUser = $this->api()->wordpress()->getDefaultWpSsoUser($customerId, $websiteId, $appId);
        } catch (ApiException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            $wpUser = $this->api()->wordpress()->getWordpressUsers($customerId, $websiteId, $appId)->getItems()[0];
        }

        $loginUrl = $this->api()->wordpress()->getWordpressUserSsoUrl(
            $customerId,
            $websiteId,
            $appId,
            $wpUser->getId()
        );

        return json_decode($loginUrl) ?? $loginUrl; // in-case it's returned as a JSON string
    }

    protected function getWordpressAppId(string $customerId, string $websiteId): string
    {
        $apps = $this->api()->apps()->getWebsiteApps($customerId, $websiteId);

        foreach ($apps->getItems() as $app) {
            if ($app->getApp() === WebsiteAppKind::WORDPRESS) {
                return $app->getId();
            }
        }

        throw $this->errorResult('Website does not have Wordpress installed', [
            'customer_id' => $customerId,
            'website_id' => $websiteId,
        ]);
    }

    /**
     * Returns a random password 15 chars long containing lower & uppercase alpha,
     * numeric and special characters.
     */
    protected function generateRandomPassword(): string
    {
        return Helper::generateStrictPassword(15, true, true, true);
    }

    /**
     * @param string $string
     */
    protected function isUuid($string): bool
    {
        return boolval(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string)$string));
    }

    protected function api(): Api
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $api = new Api($this->configuration);
        $api->setClient(new Client([
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->configuration->access_token,
            ],
            'verify' => !$this->configuration->ignore_ssl_errors,
        ]));

        return $this->api = $api;
    }

    /**
     * @throws ProvisionFunctionError
     * @throws Throwable
     */
    protected function handleException(Throwable $e, array $data = [], array $debug = [], ?string $message = null): void
    {
        if (!isset($data['enhance_meta'])) {
            try {
                $data['enhance_meta'] = $this->getEnhanceMeta();
            } catch (Throwable $metaException) {
                $data['enhance_meta'] = [
                    'error' => $metaException->getMessage(),
                ];
            }
        }

        if ($e instanceof ProvisionFunctionError) {
            throw $e->withData(
                array_merge($e->getData(), $data)
            )->withDebug(
                array_merge($e->getDebug(), $debug)
            );
        }

        if ($e instanceof ApiException) {
            $responseBody = $e->getResponseBody();
            $responseData = is_string($responseBody) ? json_decode($responseBody, true) : $responseBody;

            if (!$e->getCode() && !$e->getResponseBody()) {
                // hmm maybe connection failed
                if (preg_match('/cURL error (\d+): ([^\(]+)/i', $e->getMessage(), $matches)) {
                    $message = sprintf('API Connection Failed [%s]: %s', $matches[1], $matches[2]);
                }
            }

            if (!$message) {
                $message = sprintf('API Request Failed [%s]', $e->getCode());

                if (isset($responseData['message'])) {
                    $message .= ': ' . $responseData['message'];
                }
            }

            $data = array_merge([
                'response_code' => $e->getCode(),
                'response_data' => $responseData,
                'exception_message' => $e->getMessage(),
            ], $data);

            if (is_null($responseData)) {
                $debug['response_body'] = $responseBody;
            }

            throw $this->errorResult($message, $data, $debug, $e);
        }

        // let the provision system handle this one
        throw $e;
    }
}
