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
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'multipart/form-data',
            ],
            'http_errors' => true,
            'handler' => $this->getGuzzleHandlerStack(true),
            'base_uri' => sprintf('https://%s:%s/api/login', $this->configuration->hostname, ($this->configuration->port)?? 2222),
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

    /**
     * @param CreateParams $params
     * @return AccountInfo
     * @throws \Exception
     */
    public function create(CreateParams $params): AccountInfo
    {
        $command = 'ACCOUNT_USER';
        if ($params->as_reseller) {
            $command = 'ACCOUNT_RESELLER';
        }
        $requestParams = [
            'username' => $params->username,
            'action' => 'create',
            'email' => $params->email,
            'passwd' => $params->password,
            'passwd2' => $params->password,
            'domain' => $params->domain,
            'package' => $params->package_name, //TODO - there is no validation for package
            'ip' => $params->custom_ip, //TODO - doesnt work for reseller because of system problem
            'notify' => 'no',
        ];

        $this->invokeApi('POST', $command, ['form_params' => $requestParams]);
        return $this->getAccountInfo($params->username)
            ->setMessage('Package/limits updated');
    }

    /**
     * @param GrantResellerParams $params
     * @return ResellerPrivileges
     * @throws \Exception
     */
    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {

        $this->invokeApi('POST', 'convert-user-to-reseller', [
            'json' => [
                'account' => $params->username,
                'creator' => $this->configuration->username,
            ]
        ], '/api/');

        return ResellerPrivileges::create()
            ->setMessage('Reseller privileges granted')
            ->setReseller(true);
    }

    /**
     * @param AccountUsername $params
     * @return ResellerPrivileges
     * @throws \Exception
     */
    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        $this->invokeApi('POST', 'convert-reseller-to-user', [
            'json' => [
                'account' => $params->username,
                'creator' => $this->configuration->username,
            ]
        ], '/api/');

        return ResellerPrivileges::create()
            ->setMessage('Reseller privileges granted')
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

    /**
     * @param ChangePackageParams $params
     * @return AccountInfo
     * @throws \Exception
     */
    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        $accSummary = $this->invokeApi('GET', 'SHOW_USER_CONFIG', ['query' => ['user' => $params->username]]);
        $command = ($accSummary['usertype'] == 'reseller')? 'MODIFY_RESELLER' : 'MODIFY_USER';

        $requestParams = [
            'user' => $params->username,
            'action' => 'package',
            'package' => $params->package_name,
        ];

        $this->invokeApi('POST', $command, ['form_params' => $requestParams]);
        return $this->getAccountInfo($params->username)
            ->setMessage('Package/limits updated');
    }

    /**
     * @param GetLoginUrlParams $params
     * @return LoginUrl
     * @throws \Exception
     */
    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {

        $keyParams = [
            'action' => 'create',
            'type' => 'one_time_url',
            'keyname' => $params->username,
            'expiry' => '1h',
            'max_uses' => 1,
            'clear_key' => 'yes',
            'allow_html' => 'yes',
            'login_keys_notify_on_creation' => 0,
            'user' => $this->configuration->username . '|' . $params->username,
            'user' => $params->username,
            'passwd' => $this->configuration->password,
        ];


        $result = $this->invokeApi('POST', 'LOGIN_KEYS', ['form_params' => $keyParams, 'auth' => [
            $this->configuration->username . '|' . $params->username,
            $this->configuration->password
        ]]);

        $exparationDate = Carbon::now()->addHour();

        return LoginUrl::create()
            ->setMessage('Login URL generated')
            ->setLoginUrl($result['details'])
            ->setForIp($params->user_ip) //DirectAdmin login urls aren't tied to specific IDs
            ->setExpires($exparationDate);
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

    /**
     * @param AccountUsername $params
     * @return AccountInfo
     * @throws \Exception
     */
    public function unSuspend(AccountUsername $params): AccountInfo
    {
        $this->unSuspendAccount($params->username);

        return $this->getInfo(AccountUsername::create(['username' => $params->username]))
            ->setMessage('Account unsuspended');
    }

    /**
     * @param ChangePasswordParams $params
     * @return EmptyResult
     * @throws \Exception
     */
    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        $requestParams = [
            'username' => $params->username,
            'passwd' => $params->password,
            'passwd2' => $params->password,
        ];

        $this->invokeApi('POST', 'USER_PASSWD', ['form_params' => $requestParams]);
        return $this->emptyResult('Password changed');
    }

    /**
     * @param AccountUsername $params
     * @return EmptyResult
     * @throws \Exception
     */
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

    /**
     * @param string $username
     * @return void
     * @throws \Exception
     */
    protected function deleteAccount(string $username): void
    {
        $requestParams = [
            'select0' => $username,
            'delete' => 'yes',
            'confirmed' => 'Confirm',
        ];

        $this->invokeApi('POST', 'SELECT_USERS', ['form_params' => $requestParams]);
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
            ->setSuspended(!($accSummary['suspended'] == 'no'))
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
     * @param string $method
     * @param string $command
     * @param array $options
     * @param string $basePath
     * @return array
     * @throws \Exception
     */
    public function invokeApi(string $method, string $command, array $options = [], string $basePath = '/CMD_API_'): array
    {
        $result = $this->rawRequest($method, $basePath . $command, $options);
        if (!empty($result['error'])) {
            throw new \Exception("{$result['text']} ({$result['details']}) on $method to /CMD_API_$command");
        }
        return Conversion::sanitizeArray($result);
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return array
     * @throws \Exception
     */
    public function rawRequest(string $method, string $uri, array $options): array
    {
        try {
            $response = $this->connection->request($method, $uri, $options);

            if (isset($response->getHeader('Content-Type')[0]) && $response->getHeader('Content-Type')[0] == 'text/html') {
                //TODO
//                throw new \Exception(sprintf('DirectAdmin API returned text/html to %s %s containing "%s"', $method, $uri, strip_tags($response->getBody()->getContents())));
                throw new \Exception('Invalid request - data or access!');
            }
            $body = $response->getBody()->getContents();
            return Conversion::responseToArray($body);
        } catch (\Exception $exception) {
            // Rethrow anything that causes a network issue
            throw new \Exception(sprintf('%s request to %s failed', $method, $uri), 0, $exception);
        }
    }
}
