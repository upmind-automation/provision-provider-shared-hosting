<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\DirectAdmin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use Throwable;
use Carbon\Carbon;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\SharedHosting\Category;
use Upmind\ProvisionProviders\SharedHosting\Data\CreateParams;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountInfo;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountUsage;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountUsername;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePackageParams;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePasswordParams;
use Upmind\ProvisionProviders\SharedHosting\Data\EmptyResult;
use Upmind\ProvisionProviders\SharedHosting\Data\GetLoginUrlParams;
use Upmind\ProvisionProviders\SharedHosting\Data\GrantResellerParams;
use Upmind\ProvisionProviders\SharedHosting\Data\LoginUrl;
use Upmind\ProvisionProviders\SharedHosting\Data\ResellerPrivileges;
use Upmind\ProvisionProviders\SharedHosting\Data\SuspendParams;
use Upmind\ProvisionProviders\SharedHosting\Data\UsageData;
use Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Data\Configuration;

class Provider extends Category implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected Configuration $configuration;

    /**
     * @var Api
     */
    protected $api;

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
            ->setName('DirectAdmin')
            ->setDescription('Create and manage DirectAdmin accounts and resellers using the DirectAdmin API')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/direct-admin-logo@2x.png');
    }

    /**
     * @inheritDoc
     */
    public function create(CreateParams $params): AccountInfo
    {
        $asReseller = boolval($params->as_reseller ?? false);

        if (!$asReseller) {
            if (!$params->domain) {
                throw $this->errorResult('Domain name is required');
            }
        }

        if (!$params->custom_ip) {
            throw $this->errorResult('Domain IP is required');
        }

        $username = $params->username ?? $this->generateUsername($params->domain);

        try {
            $this->api()->createAccount(
                $params,
                $username,
                $asReseller,
            );

            if ($asReseller) {
                return $this->_getInfo($username, 'Reseller account created');
            } else {
                return $this->_getInfo($username, 'Account created');
            }

        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    protected function generateUsername(string $base): string
    {
        return substr(
            preg_replace('/^[^a-z]+/', '', preg_replace('/[^a-z0-9]/', '', strtolower($base))),
            0,
            $this->getMaxUsernameLength()
        );
    }

    protected function getMaxUsernameLength(): int
    {
        return 16;
    }

    protected function _getInfo(string $username, string $message): AccountInfo
    {
        $info = $this->api()->getAccountData($username);

        return AccountInfo::create($info)->setMessage($message);
    }

    /**
     * @inheritDoc
     */
    public function getInfo(AccountUsername $params): AccountInfo
    {
        try {
            return $this->_getInfo($params->username,
                'Account info retrieved',
            );
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    public function getUsage(AccountUsername $params): AccountUsage
    {
        try {
            $usage = $this->api()->getAccountUsage($params->username);

            return AccountUsage::create()
                ->setUsageData($usage);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        try {
            $loginUrl = $this->api()->getLoginUrl($params->username, $params->user_ip);

            return LoginUrl::create()
                ->setLoginUrl($loginUrl)
                ->setExpires(Carbon::now()->addMinutes(30))
                ->setForIp($params->user_ip);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        try {
            $this->api()->updatePassword($params->username, $params->password);

            return $this->emptyResult('Password changed');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        try {
            $this->api()->updatePackage($params->username, $params->package_name);

            return $this->_getInfo(
                $params->username,
                'Package changed');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function suspend(SuspendParams $params): AccountInfo
    {
        try {
            $this->api()->suspendAccount($params->username);

            return $this->_getInfo($params->username, 'Account suspended');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function unSuspend(AccountUsername $params): AccountInfo
    {
        try {
            $this->api()->unsuspendAccount($params->username);

            return $this->_getInfo($params->username, 'Account unsuspended');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function terminate(AccountUsername $params): EmptyResult
    {
        try {
            $this->api()->deleteAccount($params->username);

            return $this->emptyResult('Account deleted');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     */
    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        throw $this->errorResult('Operation not supported');
    }

    /**
     * @inheritDoc
     */
    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        throw $this->errorResult('Operation not supported');
    }


    /**
     * @throws ProvisionFunctionError
     * @throws Throwable
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof ServerException) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $reason = $response->getReasonPhrase();
                $responseBody = $response->getBody()->__toString();
                $responseData = json_decode($responseBody, true);
                $errorMessage = $responseData['error'] . '. ' . $responseData['result'];

                throw $this->errorResult(
                    sprintf('Provider API error: %s', $errorMessage ?? $reason ?? null),
                    [],
                    ['response_data' => $responseData ?? null],
                    $e
                );
            }
        }

        // let the provision system handle this one
        throw $e;
    }

    protected function api(): Api
    {
        if ($this->api) {
            return $this->api;
        }

        $credentials = base64_encode("{$this->configuration->username}:{$this->configuration->password}");

        $client = new Client([
            'base_uri' => sprintf('https://%s:2222', $this->configuration->hostname),
            'headers' => [
                'Authorization' => ['Basic ' . $credentials],
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'http_errors' => true,
            'allow_redirects' => false,
            'handler' => $this->getGuzzleHandlerStack(!!$this->configuration->debug),
        ]);

        return $this->api = new Api($client, $this->configuration);
    }
}
