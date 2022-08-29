<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Enhance;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
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
use Upmind\ProvisionProviders\SharedHosting\Enhance\Api\Request;
use Upmind\ProvisionProviders\SharedHosting\Enhance\Api\Response;
use Upmind\ProvisionProviders\SharedHosting\Enhance\Data\EnhanceCredentials;

class Provider extends SharedHosting implements ProviderInterface
{
    /**
     * Number of times to attempt to generate a unique username before failing.
     *
     * @var int
     */
    protected const MAX_USERNAME_GENERATION_ATTEMPTS = 5;
    const MAX_NAMESERVERS = 5;

    /**
     * @var EnhanceCredentials
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
    private $orgId;
    /**
     * @var mixed
     */
    private $cookie;

    public function __construct(EnhanceCredentials $configuration)
    {
        $this->configuration = $configuration;
        $this->setClient();

    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Enhance')
            ->setDescription('Create and manage Enhance accounts and resellers using the Enhance API');
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
        if($this->isMemberExists($params->email)) {
            throw new \Exception(sprintf('Account with email %s exists!', $params->email));
        }

        if ($params->password == null ) {
            throw new \Exception('For that service password is required for registration!');
        }

        $customerId = $this->createCustomer($params->username);

        $planId = $this->getPlanId($params->package_name);

        $this->setSubscription($customerId, $planId);

        $loginId = $this->createLogin($customerId, $params->username, $params->email, $params->password);
        $this->createMember($customerId, $loginId);

        return $this->getAccountInfo($customerId)
            ->setMessage('Account created');
    }

    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        throw new \Exception('Method not supported!', 401);
    }

    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        throw new \Exception('Method not supported!', 401);
    }

    /**
     * @param AccountUsername $params
     * @return AccountInfo
     * @throws \Exception
     */
    public function getInfo(AccountUsername $params): AccountInfo
    {
        return $this->getAccountInfo($params->customer_id);
    }

    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        throw new \Exception('Method not supported!', 401);
    }

    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        throw new \Exception('Method not supported!', 401);
//        $user = $params->username;
//        $whm = $params->is_reseller ?? false;
//        $service = $whm ? 'whostmgrd' : 'cpaneld';
//        $requestParams = compact('user', 'service');
//
//        $response = $this->makeApiCall('POST', 'create_user_session', $requestParams);
//        $data =  $this->processResponse($response);
//
//        return LoginUrl::create()
//            ->setMessage('Login URL generated')
//            ->setLoginUrl($data['url'])
//            ->setForIp(null) //cpanel login urls aren't tied to specific IDs
//            ->setExpires(Carbon::createFromTimestampUTC($data['expires']));
    }

    /**
     * @param SuspendParams $params
     * @return AccountInfo
     * @throws \Exception
     */
    public function suspend(SuspendParams $params): AccountInfo
    {
        $customerName = '';
        $this->suspendAccount($params->customer_id);
        $accountInfo = $this->invokeApi('GET', sprintf('/orgs/%s/customers', $this->orgId, $params->customer_id));
        foreach ($accountInfo['items'] as $customer) {
            if ($customer['id'] == $params->customer_id) {
             $customerName = $customer['name'];
            }
        }

        return $this->getInfo(AccountUsername::create(['username' => $customerName, 'customer_id' => $params->customer_id]))
            ->setMessage('Account suspended');
    }

    /**
     * @param AccountUsername $params
     * @return AccountInfo
     * @throws \Exception
     */
    public function unSuspend(AccountUsername $params): AccountInfo
    {

        $customerName = '';
        $this->unSuspendAccount($params->customer_id);
        $accountInfo = $this->invokeApi('GET', sprintf('/orgs/%s/customers', $this->orgId, $params->customer_id));
        foreach ($accountInfo['items'] as $customer) {
            if ($customer['id'] == $params->customer_id) {
                $customerName = $customer['name'];
            }
        }

        return $this->getInfo(AccountUsername::create(['username' => $customerName, 'customer_id' => $params->customer_id]))
            ->setMessage('Account unsuspended');
    }

    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        throw new \Exception('Method not supported!', 401);
    }

    public function terminate(AccountUsername $params): EmptyResult
    {
        $this->deleteAccount($params->customer_id);

        return $this->emptyResult('Account deleted');
    }

    /**
     * @param string $customerId
     * @return void
     * @throws \Exception
     */
    protected function suspendAccount(string $customerId): void
    {
        $requestParams = [
            'isSuspended' => true
        ];

        $this->invokeApi('PATCH','/orgs/' . $customerId, $requestParams);
    }

    /**
     * @param string $customerId
     * @return void
     * @throws \Exception
     */
    protected function unSuspendAccount(string $customerId): void
    {
        $requestParams = [
            'isSuspended' => false
        ];

        $this->invokeApi('PATCH','/orgs/' . $customerId, $requestParams);
    }

    protected function deleteAccount(string $customerId): void
    {
        $this->invokeApi('DELETE', sprintf('/orgs/%s', $customerId));
    }

    /**
     * @param string $customerId
     * @return AccountInfo
     * @throws \Exception
     */
    public function getAccountInfo(string $customerId): AccountInfo
    {
        $nameServers = [];
        $plan = [];

        $accSummary = $this->invokeApi('GET', sprintf('/orgs/%s', $customerId));
        //TODO
//        for ($i = 1; $i < self::MAX_NAMESERVERS; $i++) {
//            if (isset($accSummary['ns' . $i])) {
//                $nameServers[] = $accSummary['ns' .$i];
//            }
//        }

        $domains = $this->invokeApi('GET', sprintf('/orgs/%s/domains', $customerId));

        if ($accSummary['subscriptionsCount'] > 0) {
            $plan = $this->invokeApi('GET', sprintf('/orgs/%s/customers/%s/subscriptions', $this->orgId, $customerId));
        }

        return AccountInfo::create()
            ->setMessage('Account info retrieved')
            ->setUsername($accSummary['name'])
            ->setDomain($domains['items'][0]['name']?? $this->configuration->hostname)
            ->setReseller(false)
            ->setServerHostname($this->configuration->hostname)
            ->setPackageName($plan['items'][0]['planName']?? $accSummary['name'])
            ->setSuspended(!($accSummary['status'] == 'active'))
            ->setSuspendReason(null)
            ->setIp(null)
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
     * @param string $function Enhance API function name
     * @param array $params API function params
     * @param array $requestOptions Guzzle request options
     *
     * @return \Upmind\ProvisionProviders\SharedHosting\Enhance\Api\Response
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
     * @param string $function Enhance API function name
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
                    'WHM API Connection Error',
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
            'WHM API Error: ' . $message,
            compact('result_data'),
            compact('http_code', 'result_meta')
        );
    }


    private function setClient(string $cookie = null): Client
    {
        $clientOptions = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'http_errors' => true,
            'handler' => $this->getGuzzleHandlerStack(true),
            'base_uri' => sprintf('https://%s/api', $this->configuration->hostname),
        ];

        if ($cookie) {
            $cookieArr = explode(';', $cookie);
            if (isset($cookieArr[0])) {
                $cookie = $cookieArr[0];
            }
            $clientOptions['headers']['Cookie'] = $cookie;

            return $this->connection = new Client($clientOptions);
        }

        $this->connection = new Client($clientOptions);

        $options = [
            'json' => [
                'email' => $this->configuration->email,
                'password' => $this->configuration->password,
            ]
        ];

        $response = $this->connection->request('POST',  sprintf('https://%s/api/login/sessions', $this->configuration->hostname), $options);

        if (isset($response->getHeader('Content-Type')[0]) && $response->getHeader('Content-Type')[0] == 'text/html') {
            throw new \Exception('Invalid request - data or access!');
        }

        $body = $response->getBody()->getContents();
        $cookie = $response->getHeader('set-cookie')[0];

        $response = json_decode($body, true);
        if (isset($response['memberships'][0]['orgId'])) {
            $this->orgId = $response['memberships'][0]['orgId'];
        } else {
            throw new \Exception(sprinf('Can not loggin to host %s', $this->configuration->hostname));
        }

        return $this->setClient($cookie);
    }

    protected function getClient(): Client
    {
        if ($this->connection) {
            return $this->connection;
        }

        return $this->setClient();
    }


    /**
     * @param string $method
     * @param string $command
     * @param array $options
     * @param string $basePath
     * @return array
     * @throws \Exception
     */
    public function invokeApi(string $method, string $command, array $options = [], string $basePath = '/api'): array
    {
        $result = $this->rawRequest($method, $basePath . $command, $options);
        if (!empty($result['error'])) {
            throw new \Exception("{$result['text']} ({$result['details']}) on $method to /CMD_API_$command");
        }
        return $result;
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
            if ($method == 'GET') {
                $key = \GuzzleHttp\RequestOptions::QUERY;
            } else {
                $key = \GuzzleHttp\RequestOptions::JSON;
            }

            $params = [
                $key => $options
            ];

            $response = $this->getClient()->request($method, $uri, $params);

            if (isset($response->getHeader('Content-Type')[0]) && $response->getHeader('Content-Type')[0] == 'text/html') {
                //TODO
//                throw new \Exception(sprintf('DirectAdmin API returned text/html to %s %s containing "%s"', $method, $uri, strip_tags($response->getBody()->getContents())));
                throw new \Exception('Invalid request - data or access!');
            }
            $body = $response->getBody()->getContents();
            return json_decode($body, true)?? [];
        } catch (\Exception $exception) {
            // Rethrow anything that causes a network issue
            throw new \Exception(sprintf('%s request to %s failed', $method, $uri), 0, $exception);
        }
    }

    private function getDomainName(int $subscriptionId): string
    {
        $domainName = '';
        $params = [
            'sortBy' => 'createdAt',
            'sortOrder' => 'asc',
            'recursion' => 'infinite',
            'status' => 'active',
            'subscriptionId' => $subscriptionId
        ];

        $websites = $this->invokeApi('GET', sprintf('/orgs/%s/websites', $this->orgId), $params);

        if (isset($websites['items'][0]['domain']['domain'])) {
            $domainName = $websites['items'][0]['domain']['domain'];
        }

        return $domainName;
    }

    /**
     * @param string $email
     * @return bool
     * @throws \Exception
     */
    private function isMemberExists(string $email): bool
    {
        $params = [
            'search' => $email
        ];
        $result = $this->invokeApi('GET', sprintf('/orgs/%s/members', $this->orgId), $params);

        if (isset($result['total']) && $result['total'] != 0) {
            return true;
        }

        return false;
    }

    /**
     * @param string $username
     * @return string
     * @throws \Exception
     */
    private function createCustomer(string $username): string
    {
        $params = [
            'name' => $username
        ];

        $result = $this->invokeApi('POST', sprintf('/orgs/%s/customers', $this->orgId), $params);

        if (!isset($result['id'])) {
            throw new \Exception('Can not create customer!');
        }
        return $result['id'];
    }

    /**
     * @param string $packageName
     * @return int
     * @throws \Exception
     */
    private function getPlanId(string $packageName): int
    {
        $params = [
            'limit' => 10
        ];
        $planId = 0;

        while (true) {

            $result = $this->invokeApi('GET', sprintf('/orgs/%s/plans', $this->orgId), $params);

            if (isset($result['total']) && $result['total'] == 0) {
                throw new \Exception(sprintf('Plan %s does not exist!', $packageName));
            }

            foreach ($result['items'] as $item) {
                if ($item['name'] == $packageName) {
                    $planId = $item['id'];
                    break 2;
                }
            }
        }

        return $planId;
    }

    /**
     * @param string $customerId
     * @param int $planId
     * @return void
     * @throws \Exception
     */
    private function setSubscription(string $customerId, int $planId): void
    {
        $params = [
            'planId' => $planId
        ];
        $result = $this->invokeApi('POST', sprintf('/orgs/%s/customers/%s/subscriptions', $this->orgId, $customerId), $params);

        if (!isset($result['id'])) {
            throw new \Exception('Can not create subscription!');
        }
    }

    /**
     * @param string $customerId
     * @param string $username
     * @param string $email
     * @param string $password
     * @return string
     * @throws \Exception
     */
    private function createLogin(string $customerId, string $username, string $email, string $password): string
    {
        $params = [
            'email' => $email,
            'password' => $password,
            'name' => $username
        ];
        $result = $this->invokeApi('POST', '/logins?orgId=' . $customerId, $params);
        if (!isset($result['id'])) {
            throw new \Exception('Can not create login!');
        }

        return $result['id'];
    }

    /**
     * @param string $customerId
     * @param string $loginId
     * @return string
     * @throws \Exception
     */
    private function createMember(string $customerId, string $loginId): string
    {
        $params = [
            'loginId' => $loginId,
            'roles' => ['Owner'],
        ];

        $result = $this->invokeApi('POST', sprintf('/orgs/%s/members', $customerId), $params);
        if (!isset($result['id'])) {
            throw new \Exception('Can not create member!');
        }

        return $result['id'];
    }

}
