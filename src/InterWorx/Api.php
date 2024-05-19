<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\InterWorx;

use ErrorException;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;
use SoapClient;
use Upmind\ProvisionBase\Helper;
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
    private ?LoggerInterface $logger;
    private ?SoapClient $client = null;

    public function __construct(Configuration $configuration, ?LoggerInterface $logger = null)
    {
        $this->configuration = $configuration;
        $this->logger = $logger;
    }

    protected function client(): SoapClient
    {
        if (isset($this->client)) {
            return $this->client;
        }

        $wsdl = "https://{$this->configuration->hostname}:{$this->configuration->port}/soap?wsdl";
        return $this->client = new SoapClient($wsdl, [
            'trace' => true,
        ]);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
    public function makeRequest(string $controller, string $action, array $input = []): array
    {
        $key = [
            'email' => $this->configuration->username,
            'password' => $this->configuration->password
        ];

        $response = $this->client()->route($key, $controller, $action, $input);

        $this->logLastRequest();

        if (empty($response)) {
            throw new RuntimeException('Empty api response');
        }

        return $this->parseResponseData($response);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    private function parseResponseData(array $response): array
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

            if (count($errorMessages) === 0) {
                $lines = explode("\n", $response['payload']);
                return $lines[1] ?? 'Unknown Error';
            }
        }

        return implode('. ', $errorMessages);
    }

    public function createAccount(CreateParams $params, string $username, bool $asReseller): void
    {
        $ipAddress = $params->custom_ip ?: $this->getFreeIp();

        $password = $params->password ?: Helper::generatePassword();

        $input = [
            'nickname' => $params->customer_name ?: $params->email,
            'email' => $params->email,
            'password' => $password,
            'confirm_password' => $password,
            'packagetemplate' => $params->package_name,
        ];

        if ($asReseller) {
            $input['ipv4'] = $ipAddress;
            $input['status'] = 'active';

            $this->createResellerAccount($input);
        } else {
            $input['master_domain'] = $params->domain;
            $input['master_domain_ipv4'] = $ipAddress;
            $input['uniqname'] = $username;

            $this->createSiteworxAccount($input);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
    private function createResellerAccount(array $input): void
    {
        $this->makeRequest('/nodeworx/reseller', 'add', $input);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
    private function createSiteworxAccount(array $input): void
    {
        $this->makeRequest('/nodeworx/siteworx', 'add', $input);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
    private function getFreeIp(): string
    {
        $result = $this->makeRequest('/nodeworx/siteworx', 'listFreeIps');

        return Arr::first(Arr::first($result['payload']));
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
    private function getSiteWorxAccount(string $domain)
    {
        $apiController = '/nodeworx/siteworx';
        $action = 'querySiteworxAccountDetails';
        $input = [
            'domain' => $domain
        ];

        return $this->makeRequest($apiController, $action, $input)['payload'];
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
    private function getResellerAccount(string $username): array
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
    public function unsuspendAccount(string $username): void
    {
        $controller = '/nodeworx/siteworx';
        $action = 'unsuspendByUser';
        $input = [
            'user' => $username
        ];

        $this->makeRequest($controller, $action, $input);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
    public function deleteAccount(string $domain): void
    {
        $controller = '/nodeworx/siteworx';
        $action = 'delete';
        $input = [
            'domain' => $domain
        ];

        $this->makeRequest($controller, $action, $input);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
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

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
    public function updatePackage(string $domain, string $package): void
    {
        $controller = '/nodeworx/siteworx';
        $action = 'edit';
        $input = [
            'domain' => $domain,
            'packagetemplate' => $package,
        ];

        $this->makeRequest($controller, $action, $input);
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \RuntimeException
     */
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

    /**
     * Logs the last request and response if a logger is set.
     */
    protected function logLastRequest(): void
    {
        if ($this->logger) {
            $this->logger->debug(sprintf(
                "SOAP Request:\n%s\nSOAP Response:\n%s",
                $this->formatLog($this->client()->__getLastRequest()),
                $this->formatLog($this->client()->__getLastResponse())
            ));
        }
    }

    /**
     * Format the given log message, masking the username and password.
     *
     * @param string|null $message
     */
    protected function formatLog($message): string
    {
        return str_replace(
            array_map(
                fn ($string) => htmlspecialchars($string, ENT_XML1, 'UTF-8'),
                [$this->configuration->username, $this->configuration->password]
            ),
            ['[USERNAME]', '[PASSWORD]'],
            trim(strval($message))
        );
    }

    /**
     * @return static
     */
    public function setLogger(?LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }
}
