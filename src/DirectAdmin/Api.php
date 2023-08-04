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

    public function __construct(Client $client, Configuration $configuration)
    {
        $this->client = $client;
        $this->configuration = $configuration;
    }

    public function makeRequest(
        string  $command,
        ?array  $params = null,
        ?array  $body = null,
        ?string $method = 'GET',
        ?array  $credentials = null
    ): ?array
    {
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

    public function createAccount(CreateParams $params, string $username, bool $asReseller): void
    {
        $password = $params->password ?: Helper::generatePassword();

        if ($asReseller) {
            $command = '/CMD_API_ACCOUNT_RESELLER';
        } else {
            $command = '/CMD_API_ACCOUNT_USER';
        }

        $query = array(
            'action' => 'create',
            'add' => 'Submit',
            'username' => $username,
            'email' => $params->email,
            'passwd' => $password,
            'passwd2' => $password,
            'domain' => $params->domain,
            'package' => $params->package_name,
            'ip' => $params->custom_ip,
        );

        $this->makeRequest($command, $query);
    }

    public function getAccountData(string $username): array
    {
        $account = $this->getUserConfig($username);

        return array(
            'username' => $account['username'],
            'domain' => $account['domain'] ?? null,
            'reseller' => $account['usertype'] === 'reseller',
            'server_hostname' => $this->configuration->hostname,
            'package_name' => $account['package'],
            'suspended' => $account['suspended'] === 'yes',
            'suspend_reason' => $account['suspended_reason'] ?? null,
            'ip' => $account['ip'] ?? null,
            'nameservers' => [$account['ns1'] ?? null, $account['ns2'] ?? null],
        );
    }

    public function getUserConfig(string $username): array
    {
        $command = '/CMD_API_SHOW_USER_CONFIG';
        $query = array(
            'user' => $username,
        );

        return $this->makeRequest($command, $query);
    }

    public function suspendAccount(string $username): void
    {
        $command = '/CMD_API_SELECT_USERS';
        $query = array(
            'select0' => $username,
            'suspend' => 'Suspend',
        );

        $this->makeRequest($command, $query, null, 'POST');
    }

    public function unsuspendAccount(string $username): void
    {
        $command = '/CMD_API_SELECT_USERS';
        $query = array(
            'select0' => $username,
            'suspend' => 'Unsuspend',
        );

        $this->makeRequest($command, $query, null, 'POST');
    }

    public function deleteAccount(string $username): void
    {
        $command = '/CMD_API_SELECT_USERS';
        $query = array(
            'confirmed' => 'Confirm',
            'select0' => $username,
            'delete' => 'yes',
        );

        $this->makeRequest($command, $query, null, 'POST');
    }

    public function updatePassword(string $username, string $password): void
    {
        $command = '/CMD_API_USER_PASSWD';
        $body = array(
            'username' => $username,
            'passwd' => $password,
            'passwd2' => $password,
        );

        $this->makeRequest($command, null, $body, 'POST');
    }

    public function updatePackage(string $username, string $package_name)
    {
        $account = $this->getUserConfig($username);
        $asReseller = $account['usertype'] === 'reseller';

        if ($asReseller) {
            $command = '/CMD_API_MODIFY_RESELLER';
        } else {
            $command = '/CMD_API_MODIFY_USER';
        }

        $query = array(
            'action' => 'package',
            'user' => $username,
            'package' => $package_name,
        );

        $this->makeRequest($command, $query, null, 'POST');
    }

    public function getAccountUsage(string $username)
    {
        $command = '/CMD_API_SHOW_USER_USAGE';
        $query = array(
            'user' => $username,
        );

        $usage = $this->makeRequest($command, $query);
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

        $command = '/CMD_API_LOGIN_KEYS';
        $query = array(
            'action' => 'create',
            'type' => 'one_time_url',
            'login_keys_notify_on_creation' => 0,
            'clear_key' => 'yes',
            'expiry' => '30m',
            'ips' => $ip,
        );

        $credentials = array(
            $this->configuration->username . '|' . $username,
            $this->configuration->password
        );

        $response = $this->makeRequest($command, $query, null, 'POST', $credentials);

        return $response['result'];
    }
}
