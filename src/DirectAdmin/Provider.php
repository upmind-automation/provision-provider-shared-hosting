<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\DirectAdmin;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Result\ProviderResult;
use Upmind\ProvisionProviders\SharedHosting\Category as SharedHosting;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountInfo;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountUsername;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePackageParams;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePasswordParams;
use Upmind\ProvisionProviders\SharedHosting\Data\CreateParams;
use Upmind\ProvisionProviders\SharedHosting\Data\EmptyResult;
use Upmind\ProvisionProviders\SharedHosting\Data\GetLoginUrlParams;
use Upmind\ProvisionProviders\SharedHosting\Data\GrantResellerParams;
use Upmind\ProvisionProviders\SharedHosting\Data\LoginUrl;
use Upmind\ProvisionProviders\SharedHosting\Data\ResellerOptionParams;
use Upmind\ProvisionProviders\SharedHosting\Data\ResellerPrivileges;
use Upmind\ProvisionProviders\SharedHosting\Data\SuspendParams;
use Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Api\Request;
use Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Api\Response;
use Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Data\DirectAdminCredentials;
use Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Utility\Conversion;

class Provider extends SharedHosting implements ProviderInterface
{
    /**
     * Number of times to attempt to generate a unique username before failing.
     *
     * @var int
     */
    protected const MAX_USERNAME_GENERATION_ATTEMPTS = 5;

    protected const MAX_NAMESERVERS = 5;

    /**
     * @var DirectAdminCredentials
     */
    protected $configuration;

    /**
     * @var Client
     */
    protected $client;

    /**
     * List of whm functions available to this configuration.
     *
     * @var string[]|null
     */
    protected $functions;
    /**
     * @var Client
     */
    private $connection;

    public function __construct(DirectAdminCredentials $configuration)
    {
        $this->configuration = $configuration;
        $this->connection = new Client([
            'base_uri' => sprintf('https://%s:2222/api/login', $this->configuration->hostname),
            'auth' => [$this->configuration->username, $this->configuration->password],
        ]);
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('DirectAdmin')
            ->setDescription('Create and manage DirectAdmin accounts and resellers using the DirectAdmin API');
    }

    public function testConfiguration(): EmptyResult
    {
        $functionsResult = ProviderResult::createFromProviderOutput($this->listFunctions());

        if ($functionsResult->getStatus() !== ProviderResult::STATUS_OK) {
            $errorMessage = "Configuration is unable to list available API commands: "
                . $functionsResult->getMessage();

            return $this->errorResult(
                $errorMessage,
                $functionsResult->getData(),
                $functionsResult->getDebug(),
                $functionsResult->getException()
            );
        }

        $availableFunctions = array_get($functionsResult->getData(), 'result_data');

        $requiredFunctions = [
            'createacct',
            'setupreseller',
            'unsetupreseller',
            'accountsummary',
            'changepackage',
            'create_user_session',
            'suspendacct',
            'unsuspendacct',
            'passwd',
            'removeacct'
        ];

        $missingFunctions = collect($requiredFunctions)->diff($availableFunctions);

        if ($missingFunctions->isNotEmpty()) {
            return $this->errorResult(
                "Configuration is unable to execute all required API functions",
                ['missing_commands' => $missingFunctions->values()->all()],
                ['available_commands' => $availableFunctions]
            );
        }

        return $this->emptyResult("Credentials verified");
    }

    /**
     * @return string[] Array of API function names
     */
    public function listFunctions()
    {
        if (isset($this->functions)) {
            return $this->functions;
        }

        $response = $this->makeApiCall('GET', 'applist');

        return $this->functions = $this->processResponse($response, function ($responseData) {
            return collect(array_get($responseData, 'app'))->sortKeys()->all();
        });
    }

    public function create(CreateParams $params): AccountInfo
    {
        $username = $params->username ?: $this->generateUsername($params->domain);
        $password = $params->password ?: Helper::generatePassword();
        $domain = $params->domain;
        $contactemail = $params->email;
        $plan = $params->package_name;
        $customip = $params->custom_ip;
        $reseller = intval($params->as_reseller ?? false);

        if ($params->owns_itself) {
            $owner = $username;
        } else {
            $owner = $params->owner_username ?: $this->configuration->whm_username;
        }

        if ($reseller && !$this->canGrantReseller()) {
            return $this->errorResult('Configuration lacks sufficient privileges to create resellers');
        }

        $requestParams = compact(
            'username',
            'password',
            'domain',
            'contactemail',
            'plan',
            'customip',
            'owner',
            'reseller'
        );

        $response = $this->makeApiCall('POST', 'createacct', $requestParams);
        $this->processResponse($response, function ($responseData) {
            return collect($responseData)->filter()->sortKeys()->all();
        });

        $info = $this->getAccountInfo($username)
            ->setMessage('Account created');

        if ($info->reseller && $params->reseller_options) {
            try {
                $this->changeResellerOptions($username, $params->reseller_options);
            } catch (\Throwable $e) {
                // clean-up
                $this->deleteAccount($username);

                throw $e;
            }
        }

        return $info;
    }

    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        $response = $this->makeApiCall('POST', 'setupreseller', [
            'user' => $params->username,
            'makeowner' => intval($params->owns_itself ?? false),
        ]);
        $this->processResponse($response);

        return ResellerPrivileges::create()
            ->setMessage('Reseller privileges granted')
            ->setReseller(true);
    }

    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        $user = $params->username;
        $requestParams = compact('user');

        $response = $this->makeApiCall('POST', 'unsetupreseller', $requestParams);
        $this->processResponse($response);

        return ResellerPrivileges::create()
            ->setMessage('Reseller privileges revoked')
            ->setReseller(true);
    }

    /**
     * @param AccountUsername $params
     * @return AccountInfo
     * @throws \Exception
     */
    public function getInfo(AccountUsername $params): AccountInfo
    {
        return $this->getAccountInfo($params->username);
    }

    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        $isReseller = $this->userIsReseller($params->username);

        if ($params->as_reseller) {
            if (!$isReseller) {
                if (!$this->canGrantReseller()) {
                    return $this->errorResult('Configuration lacks sufficient privileges to create resellers');
                }

                $this->grantReseller(GrantResellerParams::create($params));
            }

            $this->changeResellerOptions($params->username, $params->reseller_options ?? new ResellerOptionParams());
        } else {
            if ($isReseller) {
                $this->revokeReseller(AccountUsername::create($params));
            }
        }

        $response = $this->makeApiCall('POST', 'changepackage', [
            'user' => $params->username,
            'pkg' => $params->package_name
        ]);
        $this->processResponse($response);

        return $this->getInfo(AccountUsername::create(['username' => $params->username]))
            ->setMessage('Package/limits updated');
    }

    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        $user = $params->username;
        $whm = $params->is_reseller ?? false;
        $service = $whm ? 'whostmgrd' : 'cpaneld';
        $requestParams = compact('user', 'service');

        $response = $this->makeApiCall('POST', 'create_user_session', $requestParams);
        $data =  $this->processResponse($response);

        return LoginUrl::create()
            ->setMessage('Login URL generated')
            ->setLoginUrl($data['url'])
            ->setForIp(null) //DirectAdmin login urls aren't tied to specific IDs
            ->setExpires(Carbon::createFromTimestampUTC($data['expires']));
    }


    /**
     * @param SuspendParams $params
     * @return AccountInfo
     * @throws \Exception
     */
    public function suspend(SuspendParams $params): AccountInfo
    {
        $this->suspendAccount($params->username, $params->reason);

        return $this->getInfo(AccountUsername::create(['username' => $params->username]))
            ->setMessage('Account suspended');
    }

    public function unSuspend(AccountUsername $params): AccountInfo
    {
        $this->unSuspendAccount($params->username);

        return $this->getInfo(AccountUsername::create(['username' => $params->username]))
            ->setMessage('Account unsuspended');
    }

    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        $user = $params->username;
        $password = $params->password;
        $requestParams = compact('user', 'password');

        $response = $this->makeApiCall('POST', 'passwd', $requestParams);
        $this->processResponse($response);

        return $this->emptyResult('Password changed');
    }

    public function terminate(AccountUsername $params): EmptyResult
    {
        $this->deleteAccount($params->username);

        return $this->emptyResult('Account deleted');
    }

    /**
     * @param string $username
     * @param string|null $reason
     * @return array
     * @throws \Exception
     */
    protected function suspendAccount(string $username, ?string $reason = null): array
    {
        $requestParams = [
            'select0' => $username,
            'dosuspend' => 'anything',
            'reason' => $reason,
        ];

        return $this->invokeApi('POST', 'SELECT_USERS', ['form_params' => $requestParams]);
    }

    /**
     * @param string $username
     * @return array
     * @throws \Exception
     */
    protected function unSuspendAccount(string $username): array
    {
        $requestParams = [
            'select0' => $username,
            'dounsuspend' => 'anything',
        ];

        return $this->invokeApi('POST', 'SELECT_USERS', ['form_params' => $requestParams]);
    }

    protected function deleteAccount(string $username): void
    {
        $response = $this->userIsReseller($username)
            ? $this->makeApiCall(
                'POST',
                'terminatereseller',
                ['user' => $username, 'terminatereseller' => true],
                ['timeout' => 240]
            )
            : $this->makeApiCall(
                'POST',
                'removeacct',
                ['user' => $username]
            );
        $this->processResponse($response);
    }

    /**
     * @param string $username
     * @return AccountInfo
     * @throws \Exception
     */
    public function getAccountInfo(string $username): AccountInfo
    {
        $nameServers = [];
        $accSummary = $this->invokeApi('GET', 'SHOW_USER_CONFIG', ['query' => ['user' => $username]]);

        //TODO
        for ($i = 1; $i < self::MAX_NAMESERVERS; $i++) {
            if (isset($accSummary['ns' . $i])) {
                $nameServers[] = $accSummary['ns' .$i];
            }
        }

        $suspendReason = (isset($accSummary['suspended_reason']) && $accSummary['suspended_reason'] !== 'not suspended')? $accSummary['suspended_reason'] : null;
        $reseller = ($accSummary['usertype'] == 'reseller')? true : (($accSummary['usertype'] == 'user')? false : null);
        return AccountInfo::create()
            ->setMessage('Account info retrieved')
            ->setUsername($accSummary['username'])
            ->setDomain($accSummary['domain'])
            ->setReseller($reseller)
            ->setServerHostname($this->configuration->hostname)
            ->setPackageName($accSummary['package'])
            ->setSuspended(boolval($accSummary['suspended']))
            ->setSuspendReason($suspendReason)
            ->setIp($accSummary['ip'])
            ->setNameservers($nameServers);
    }

    /**
     * Update the given reseller's ACL and/or account/resource limits.
     */
    protected function changeResellerOptions(string $username, ResellerOptionParams $params): void
    {
        $this->changeResellerACL($username, $params->acl_name);

        $this->changeResellerLimits($username, $params);
    }

    protected function changeResellerACL(string $username, ?string $aclName): void
    {
        $response = $this->makeApiCall('POST', 'setacls', [
            'reseller' => $username,
            'acllist' => $aclName,
        ]);
        $this->processResponse($response);
    }

    protected function changeResellerLimits(string $username, ResellerOptionParams $params): void
    {
        $enableResourceLimits = isset($params->bandwidth_mb_limit) || isset($params->diskspace_mb_limit);
        $enableOverselling = $enableResourceLimits && ($params->bandwidth_overselling
                || $params->diskspace_overselling);

        $response = $this->makeApiCall('POST', 'setresellerlimits', [
            'user' => $username,
            'enable_account_limit' => intval(isset($params->account_limit)),
            'account_limit' => $params->account_limit ?? 9999,
            'enable_resource_limits' => intval($enableResourceLimits),
            'diskspace_limit' => $params->diskspace_mb_limit ?? 99999999,
            'bandwidth_limit' => $params->bandwidth_mb_limit ?? 99999999,
            'enable_overselling' => intval($enableOverselling),
            'enable_overselling_diskspace' => intval($params->diskspace_overselling ?? false),
            'enable_overselling_bandwidth' => intval($params->bandwidth_overselling ?? false),
        ]);
        $this->processResponse($response);
    }

    protected function userIsReseller(string $username): ?bool
    {
        if ($this->canGrantReseller()) {
            $response = $this->makeApiCall('POST', 'listresellers');
            $data = $this->processResponse($response);

            return in_array($username, $data['reseller']);
        }

        return null; // probably not, but can't say for sure
    }

    protected function canGrantReseller(): bool
    {
        return in_array('listresellers', $this->listFunctions());
    }

    /**
     * @param string $base Base string to generate a username from
     * @param int $count Number of attempts so far
     *
     * @return string A unique valid username
     */
    protected function generateUsername(string $base, int $count = 0): string
    {
        if ($count >= self::MAX_USERNAME_GENERATION_ATTEMPTS) {
            $this->errorResult('Unable to generate a unique username');
        }

        // usernames must be of a certain length, start with a letter and only contain alpha-numeric chars
        $username = substr(
            preg_replace('/^[^a-z]+/', '', preg_replace('/[^a-z0-9]/', '', strtolower($base))),
            0,
            $this->getMaxUsernameLength()
        );

        if ($this->newUsernameIsValid($username)) {
            return $username;
        }

        $username = substr($username, 0, $this->getMaxUsernameLength() - 1) . rand(1, 9);

        return $this->generateUsername($username, $count + 1);
    }

    protected function newUsernameIsValid(string $username): bool
    {
        $response = $this->makeApiCall('POST', 'verify_new_username', [
            'user' => $username,
        ]);

        try {
            $this->processResponse($response);
            return true;
        } catch (ProvisionFunctionError $e) {
            if (Str::contains($e->getMessage(), 'already has')) {
                // a message like 'This system already has an account named "$username"'
                return false;
            }

            throw $e;
        }
    }

    protected function getMaxUsernameLength(): int
    {
        return 8; //some server versions support 16, but earlier only support max 8
    }

    /**
     * @param string $method HTTP method
     * @param string $function DirectAdmin API function name
     * @param array $params API function params
     * @param array $requestOptions Guzzle request options
     *
     * @return \Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Api\Response
     */
    protected function makeApiCall(
        string $method,
        string $function,
        array $params = [],
        array $requestOptions = []
    ): Response {
        return $this->asyncApiCall($method, $function, $params, $requestOptions)->wait();
    }

    /**
     * @param string $method HTTP method
     * @param string $function DirectAdmin API function name
     * @param array $params API function params
     * @param array $requestOptions Guzzle request options
     *
     * @return PromiseInterface<Response>
     */
    protected function asyncApiCall(
        string $method,
        string $function,
        array $params = [],
        array $requestOptions = []
    ): PromiseInterface {
        $client = $this->getClient();
        $request = new Request($client, $method, $function, $params, $requestOptions);

        return $request->getPromise()->otherwise(function ($e) use ($function) {
            if ($e instanceof RequestException) {
                return $this->errorResult(
                    'DirectAdmin API Connection Error',
                    ['function' => $function, 'error' => $e->getMessage()],
                    ['response' => $e->hasResponse() ? $e->getResponse()->getBody()->__toString() : null],
                    $e
                );
            }

            throw $e;
        });
    }

    protected function processResponse(
        Response $response,
        ?callable $successDataTransformer = null
    ): array {
        $http_code = $response->getHttpCode();
        $message = $response->getMessage();
        $result_body = $response->getBody();
        $result_data = $response->getBodyAssoc('data', []);
        $result_meta = $response->getBodyAssoc('metadata');

        if ($response->isSuccess()) {
            if ($successDataTransformer) {
                $result_data = transform($result_data, $successDataTransformer);
            }

            return $result_data;
        }

        return $this->errorResult(
            'DirectAdmin API Error: ' . $message,
            compact('result_data'),
            compact('http_code', 'result_meta')
        );
    }

    /**
     * @return Client
     */
    protected function getClient(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        return $this->client = new Client([
            'base_uri' => sprintf('https://%s:2222/api/login', $this->configuration->hostname),
            'auth' => [$this->configuration->username, $this->configuration->password],
        ]);
    }

    /**
     * @param $method
     * @param $command
     * @param $options
     * @return array
     * @throws \Exception
     */
    public function invokeApi($method, $command, $options = []): array
    {
        $result = $this->rawRequest($method, '/CMD_API_' . $command, $options);
        if (!empty($result['error'])) {
            throw new \Exception("{$result['text']} ({$result['details']}) on $method to /CMD_API_$command");
        }
        return Conversion::sanitizeArray($result);
    }

    /**
     * @param $method
     * @param $uri
     * @param $options
     * @return array
     * @throws \Exception
     */
    public function rawRequest($method, $uri, $options): array
    {
        try {
            $response = $this->connection->request($method, $uri, $options);
            if ($response->getHeader('Content-Type')[0] == 'text/html') {
                throw new \Exception(sprintf('DirectAdmin API returned text/html to %s %s containing "%s"', $method, $uri, strip_tags($response->getBody()->getContents())));
            }
            $body = $response->getBody()->getContents();
            return Conversion::responseToArray($body);
        } catch (TransferException $exception) {
            // Rethrow anything that causes a network issue
            throw new \Exception(sprintf('%s request to %s failed', $method, $uri), 0, $exception);
        }
    }
}
