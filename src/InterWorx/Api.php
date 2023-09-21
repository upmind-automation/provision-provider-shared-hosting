<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\InterWorx;

use ErrorException;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;
use SoapClient;
use Upmind\ProvisionBase\Helper;
use Throwable;
use RuntimeException;
use Illuminate\Support\Str;
use Upmind\ProvisionProviders\SharedHosting\Data\CreateParams;
use Upmind\ProvisionProviders\SharedHosting\Data\UnitsConsumed;
use Upmind\ProvisionProviders\SharedHosting\Data\UsageData;
use Upmind\ProvisionProviders\SharedHosting\InterWorx\Data\Configuration;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

class Api
{
    private Configuration $configuration;

    private ?SoapClient $client;

    public function __construct(Configuration $configuration, ?SoapClient $client = null)
    {
        $this->configuration = $configuration;
        $this->client = $client;
    }

    protected function client(): SoapClient
    {
        if (isset($this->client)) {
            return $this->client;
        }

        $client = new SoapClient("https://{$this->configuration->hostname}:{$this->configuration->port}/soap?wsdl");

        return $this->client = $client;
    }

    public function makeRequest(string $controller, string $action, array $input): ?array
    {
        $key = [
            'email' => $this->configuration->username,
            'password' => $this->configuration->password
        ];

        $response = $this->client()->route($key, $controller, $action, $input);

        if (empty($response)) {
            throw new RuntimeException('Empty api response');
        }

        return $this->parseResponseData($response);
    }

    private function parseResponseData(array $response): ?array
    {
        if (!array_key_exists('status', $response) || !array_key_exists('payload', $response)) {
            throw ProvisionFunctionError::create('Unknown Error')
                ->withData([
                    'response' => $response,
                ]);
        }

        if ($error = $this->getResponseErrorMessage($response)) {
            throw ProvisionFunctionError::create($error)
                ->withData([
                    'response' => $response,
                ]);
        }

        return $response;
    }

    private function getResponseErrorMessage(array $response): ?string
    {
        $statusCode = $response['status'] ?? 'unknown';
        if ($statusCode == 0) {
            return null;
        }

        $errorMessages = [];
        if ($statusCode == 11) {
            if (Str::contains($response['payload'], 'Unixname is already in use')) {
                $errorMessages[] = 'Username is already in use';
            }

            if (Str::contains($response['payload'], 'Domain name already exists')) {
                $errorMessages[] = 'Domain name already exists';
            }

            if (preg_match('/unixuser: .* This is not a valid option/', $response['payload'])) {
                $errorMessages[] = 'Account does not exist';
            }

            if (preg_match('/domain: .* This is not a valid option/', $response['payload'])) {
                $errorMessages[] = 'Account does not exist';
            }

            if (preg_match('/packagetemplate: .* This is not a valid option/', $response['payload'])) {
                $errorMessages[] = 'Invalid package name';
            }

            if (count($errorMessages) == 0) {
                $lines = explode("\n", $response['payload']);
                return $lines[1] ?? 'Unknown Error';
            }
        }

        return implode('. ', $errorMessages);
    }

    public function createAccount(CreateParams $params, string $username, bool $asReseller): void
    {
        $password = $params->password ?: Helper::generatePassword();

        $input = [
            'email' => $params->email,
            'password' => $password,
            'confirm_password' => $password,
            'packagetemplate' => $params->package_name,
        ];

        if ($asReseller) {
            $input['ipv4'] = $params->custom_ip;
            $input['nickname'] = $username;
            $input['status'] = 'active';

            $this->createResellerAccount($input);
        } else {
            $input['master_domain'] = $params->domain;
            $input['master_domain_ipv4'] = $params->custom_ip;
            $input['uniqname'] = $username;

            $this->createSiteworxAccount($input);
        }
    }

    private function createResellerAccount(array $input): void
    {
        $this->makeRequest('/nodeworx/reseller', 'add', $input);
    }

    private function createSiteworxAccount(array $input): void
    {
        $this->makeRequest('/nodeworx/siteworx', 'add', $input);
    }

    /**
     * @param string $domain
     * @return array
     */
    public function getAccountData(string $domain): array
    {
        $account = $this->getSiteWorxAccount($domain);
        $suspended = $account['status'] === 'inactive';
        $suspendReason = $suspended ? (string)$account['inactive_msg'] : null;

        return [
            'username' => $account['unixuser'],
            'domain' => $account['domain'],
            'reseller' => false,
            'server_hostname' => $this->configuration->hostname,
            'package_name' => $account['package_name'],
            'suspended' => $suspended,
            'suspend_reason' => $suspendReason !== '' ? $suspendReason : null,
            'ip' => $account['ip'],
        ];
    }

    private function getSiteWorxAccount(string $domain)
    {
        $apiController = '/nodeworx/siteworx';
        $action = 'querySiteworxAccountDetails';
        $input = [
            'domain' => $domain
        ];

        return $this->makeRequest($apiController, $action, $input)['payload'];
    }

    private function getResellerAccount(string $username): ?array
    {
        $apiController = '/nodeworx/reseller';
        $action = 'listResellers';

        $response = $this->makeRequest($apiController, $action, [])['payload'];

        $result = null;

        foreach ($response as $reseller) {
            $reseller = (array)$reseller;

            if ($reseller['nickname'] === $username) {
                $result = $reseller;
                break;
            }
        }

        if (!$result) {
            throw ProvisionFunctionError::create('Account does not exist');
        }

        return $result;
    }

    public function getResellerData(string $username): ?array
    {
        $reseller = $this->getResellerAccount($username);

        return [
            'username' => $reseller['nickname'],
            'server_hostname' => $this->configuration->hostname,
            'package_name' => '-',
            'reseller' => true,
            'suspended' => false,
        ];
    }

    public function getDomainName(string $username): string
    {
        $controller = '/nodeworx/siteworx';
        $action = 'querySiteworxAccounts';
        $input = [
            'unixuser' => $username
        ];

        $response = $this->makeRequest($controller, $action, $input)['payload'];

        return $response[0]['domain'];
    }

    public function getAccountUsage(string $domain): UsageData
    {
        $account = $this->getSiteWorxAccount($domain);

        $storage = $account['storage'] ?? null;

        if (!is_null($storage)) {
            $storage = (int)$storage;
        }

        $disk = UnitsConsumed::create()
            ->setUsed((int)$account['storage_used'])
            ->setLimit($storage != 0 ? $storage : null);

        $bandwidth = UnitsConsumed::create()
            ->setUsed((int)$account['bandwidth_used'] * 1024)
            ->setLimit((int)$account['bandwidth'] * 1024);

        return UsageData::create()
            ->setDiskMb($disk)
            ->setBandwidthMb($bandwidth);
    }

    public function getResellerAccountUsage(string $username): UsageData
    {
        $account = $this->getResellerAccount($username);

        $maxStorage = $account['max_storage'] ?? null;
        if (!is_null($maxStorage)) {
            $maxStorage = (int)$maxStorage;
        }

        $disk = UnitsConsumed::create()
            ->setUsed((int)$account['storage'])
            ->setLimit($maxStorage != 0 ? $maxStorage : null);

        $bandwidth = UnitsConsumed::create()
            ->setUsed((int)$account['bandwidth'] * 1024)
            ->setLimit((int)$account['max_bandwidth'] * 1024);

        return UsageData::create()
            ->setDiskMb($disk)
            ->setBandwidthMb($bandwidth);
    }

    public function suspendAccount(string $domain, ?string $reason): void
    {
        $controller = '/nodeworx/siteworx';
        $action = 'suspend';
        $input = [
            'domain' => $domain
        ];

        if ($reason) {
            $input['message'] = $reason;
        }

        $this->makeRequest($controller, $action, $input);
    }

    public function unsuspendAccount(string $username): void
    {
        $controller = '/nodeworx/siteworx';
        $action = 'unsuspendByUser';
        $input = [
            'user' => $username
        ];

        $this->makeRequest($controller, $action, $input);
    }

    public function deleteAccount(string $domain): void
    {
        $controller = '/nodeworx/siteworx';
        $action = 'delete';
        $input = [
            'domain' => $domain
        ];

        $this->makeRequest($controller, $action, $input);
    }

    public function updatePassword(string $domain, string $password): void
    {
        $controller = '/nodeworx/siteworx';
        $action = 'edit';
        $input = [
            'domain' => $domain,
            'password' => $password,
            'confirm_password' => $password,
        ];

        $this->makeRequest($controller, $action, $input);
    }

    public function updatePackage(string $domain, string $package)
    {
        $controller = '/nodeworx/siteworx';
        $action = 'edit';
        $input = [
            'domain' => $domain,
            'packagetemplate' => $package,
        ];

        $this->makeRequest($controller, $action, $input);
    }

    public function deleteReseller(string $username): void
    {
        $reseller = $this->getResellerAccount($username);

        $controller = '/nodeworx/reseller';
        $action = 'delete';
        $input = [
            'reseller_id' => $reseller['reseller_id'],
        ];

        $this->makeRequest($controller, $action, $input);
    }
}
