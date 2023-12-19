<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\DirectAdmin;

use ErrorException;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use Upmind\ProvisionBase\Helper;
use Throwable;
use RuntimeException;
use Illuminate\Support\Str;
use Upmind\ProvisionProviders\SharedHosting\Data\CreateParams;
use Upmind\ProvisionProviders\SharedHosting\Data\UnitsConsumed;
use Upmind\ProvisionProviders\SharedHosting\Data\UsageData;
use Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Data\Configuration;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

class Api
{
    protected Client $client;
    private Configuration $configuration;
    private const METHOD_POST = 'POST';
    private const METHOD_GET = 'GET';
    private const COMMAND_FREE_IPS = '/CMD_API_SHOW_RESELLER_IPS';
    private const COMMAND_ACCOUNT_RESELLER = '/CMD_API_ACCOUNT_RESELLER';
    private const COMMAND_ACCOUNT_USER = '/CMD_API_ACCOUNT_USER';
    private const COMMAND_SHOW_INFO = '/CMD_API_SHOW_USER_CONFIG';
    private const COMMAND_SELECT_USERS = '/CMD_API_SELECT_USERS';
    private const COMMAND_USER_PASSWORD = '/CMD_API_USER_PASSWD';
    private const COMMAND_MODIFY_RESELLER = '/CMD_API_MODIFY_RESELLER';
    private const COMMAND_MODIFY_USER = '/CMD_API_MODIFY_USER';
    private const COMMAND_SHOW_USER_USAGE = '/CMD_API_SHOW_USER_USAGE';
    private const COMMAND_LOGIN_KEYS = '/CMD_API_LOGIN_KEYS';
    private const STATUS_FREE = 'free';

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    public function makeRequest(
        string  $command,
        ?array  $params = null,
        ?array  $body = null,
        ?string $method = self::METHOD_GET,
        ?array  $credentials = null
    ): ?array {
        $requestParams = [];

        if ($params) {
            $requestParams['query'] = $params;
        }

        if ($body) {
            $requestParams['form_params'] = $body;
        }

        if ($credentials) {
            $requestParams['auth'] = $credentials;
        }

        $requestParams['query']['json'] = 'yes';

        $response = $this->client->request($method, $command, $requestParams);
        $result = $response->getBody()->getContents();

        $response->getBody()->close();

        if ($result === "") {
            return null;
        }

        return $this->parseResponseData($result);
    }

    private function parseResponseData(string $response): array
    {
        $parsedResult = json_decode($response, true);

        if (!$parsedResult) {
            throw ProvisionFunctionError::create('Unknown Provider API Error')
                ->withData([
                    'response' => $response,
                ]);
        }

        return $parsedResult;
    }

    public function createAccount(CreateParams $params, string $username, bool $asReseller, string $customIp): void
    {
        $password = $params->password ?: Helper::generatePassword();
        $command = $asReseller ? self::COMMAND_ACCOUNT_RESELLER : self::COMMAND_ACCOUNT_USER;

        $query = [
            'action' => 'create',
            'add' => 'Submit',
            'username' => $username,
            'email' => $params->email,
            'passwd' => $password,
            'passwd2' => $password,
            'domain' => $params->domain,
            'package' => $params->package_name,
            'ip' => $customIp
        ];

        $this->makeRequest($command, null, $query, self::METHOD_POST);
    }

    public function getAccountData(string $username): array
    {
        $account = $this->getUserConfig($username);

        return [
            'username' => $account['username'],
            'domain' => $account['domain'] ?? null,
            'reseller' => $account['usertype'] === 'reseller',
            'server_hostname' => $this->configuration->hostname,
            'package_name' => $account['package'],
            'suspended' => $account['suspended'] === 'yes',
            'suspend_reason' => $account['suspended_reason'] ?? null,
            'ip' => $account['ip'] ?? null,
            'nameservers' => [$account['ns1'] ?? null, $account['ns2'] ?? null],
        ];
    }

    public function getUserConfig(string $username): array
    {
        return $this->makeRequest(self::COMMAND_SHOW_INFO, ['user' => $username]);
    }

    public function suspendAccount(string $username): void
    {
        $query = [
            'select0' => $username,
            'suspend' => 'Suspend',
        ];

        $this->makeRequest(self::COMMAND_SELECT_USERS, $query, null, self::METHOD_POST);
    }

    public function unsuspendAccount(string $username): void
    {
        $query = [
            'select0' => $username,
            'suspend' => 'Unsuspend',
        ];

        $this->makeRequest(self::COMMAND_SELECT_USERS, $query, null, self::METHOD_POST);
    }

    public function deleteAccount(string $username): void
    {
        $query = [
            'confirmed' => 'Confirm',
            'select0' => $username,
            'delete' => 'yes',
        ];

        $this->makeRequest(self::COMMAND_SELECT_USERS, $query, null, self::METHOD_POST);
    }

    public function updatePassword(string $username, string $password): void
    {
        $body = [
            'username' => $username,
            'passwd' => $password,
            'passwd2' => $password,
        ];

        $this->makeRequest(self::COMMAND_USER_PASSWORD, null, $body, self::METHOD_POST);
    }

    public function updatePackage(string $username, string $package_name)
    {
        $account = $this->getUserConfig($username);
        $asReseller = $account['usertype'] === 'reseller';
        $command = $asReseller ? self::COMMAND_MODIFY_RESELLER : self::COMMAND_MODIFY_USER;

        $query = [
            'action' => 'package',
            'user' => $username,
            'package' => $package_name,
        ];

        $this->makeRequest($command, $query, null, self::METHOD_POST);
    }

    public function getAccountUsage(string $username): UsageData
    {
        $usage = $this->makeRequest(self::COMMAND_SHOW_USER_USAGE, ['user' => $username]);
        $config = $this->getUserConfig($username);

        $disk = UnitsConsumed::create()
            ->setUsed((float)$usage['quota'] ?? null)
            ->setLimit($config['quota'] === 'unlimited' ? null : $config['quota']);

        $bandwidth = UnitsConsumed::create()
            ->setUsed((float)$usage['bandwidth'] ?? null)
            ->setLimit($config['bandwidth'] === 'unlimited' ? null : $config['bandwidth']);

        $inodes = UnitsConsumed::create()
            ->setUsed((float)$usage['inode'] ?? null)
            ->setLimit($config['inode'] === 'unlimited' ? null : $config['inode']);

        return UsageData::create()
            ->setDiskMb($disk)
            ->setBandwidthMb($bandwidth)
            ->setInodes($inodes);
    }

    public function getLoginUrl(string $username, string $ip): string
    {
        $this->getUserConfig($username);

        $query = [
            'action' => 'create',
            'type' => 'one_time_url',
            'login_keys_notify_on_creation' => 0,
            'clear_key' => 'yes',
            'expiry' => '30m',
            'ips' => $ip,
        ];

        $credentials = [
            $this->configuration->username . '|' . $username,
            $this->configuration->password
        ];

        $response = $this->makeRequest(
            self::COMMAND_LOGIN_KEYS,
            $query,
            null,
            self::METHOD_POST,
            $credentials
        );

        return $response['result'];
    }

    public function freeIpList(): string
    {
        $ipList = $this->makeRequest(self::COMMAND_FREE_IPS);

        foreach($ipList as $ip) {
            $ipInfo = $this->makeRequest(self::COMMAND_FREE_IPS, ['ip' => $ip]);
            if (!empty($ipInfo['status']) && $ipInfo['status'] === self::STATUS_FREE) {
                return $ip;
            }
        }

        return '';
    }
}
