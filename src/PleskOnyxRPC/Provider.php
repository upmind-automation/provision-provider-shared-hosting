<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC;

use Carbon\Carbon;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionProviders\SharedHosting\Category as SharedHosting;
use Upmind\ProvisionBase\Result\ProviderResult;
use Upmind\ProvisionBase\Helper;
use PleskX\Api\Client;
use PleskX\Api\Exception as PleskException;
use PleskX\Api\Client\Exception as PleskClientException;
use PleskX\Api\XmlResponse;
use Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC\Errors\ServiceMisconfiguration;
use Upmind\ProvisionBase\Provider\Helper\Exception\Contract\ProviderError;
use Throwable;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountInfo;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountUsername;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePackageParams;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePasswordParams;
use Upmind\ProvisionProviders\SharedHosting\Data\CreateParams;
use Upmind\ProvisionProviders\SharedHosting\Data\EmptyResult;
use Upmind\ProvisionProviders\SharedHosting\Data\GetLoginUrlParams;
use Upmind\ProvisionProviders\SharedHosting\Data\GrantResellerParams;
use Upmind\ProvisionProviders\SharedHosting\Data\LoginUrl;
use Upmind\ProvisionProviders\SharedHosting\Data\ResellerPrivileges;
use Upmind\ProvisionProviders\SharedHosting\Data\SuspendParams;
use Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC\Data\PleskOnyxCredentials;

class Provider extends SharedHosting implements ProviderInterface
{
    /**
     * Number of times to attempt to generate a unique username before failing.
     *
     * @var int
     */
    protected const MAX_USERNAME_GENERATION_ATTEMPTS = 5;

    /**
     * @var PleskOnyxCredentials
     */
    protected $configuration;

    /**
     * @var Client
     */
    protected $client;

    public function __construct(PleskOnyxCredentials $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create([
            'name' => 'Plesk',
            'description' => 'Create and manage Plesk accounts and resellers using the Onyx RPC API',
        ]);
    }

    public function testConfiguration()
    {
        try {
            $client = $this->getClient();

            $admin = $client->server()->getAdmin(); //administrator-only api function

            return $this->okResult('Credentials verified');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Configuration test');
        }
    }

    public function create(CreateParams $params): AccountInfo
    {
        if ($params->as_reseller) {
            return $this->createReseller($params);
        }

        $login = $params->username ?? $this->generateUsername($params->domain);
        $ownerLogin = $params->owner_username;
        $email = $params->email;
        $passwd = $params->password ?: Helper::generatePassword();
        $pname = $params->customer_name ?? $login;
        $domain = $params->domain;
        $plan = $params->package_name;
        $ip_address = $params->custom_ip;

        $client = $this->getClient();

        try {
            //create customer
            $customerParams = compact('pname', 'login', 'passwd', 'email');

            if ($ownerLogin) {
                $customerParams['owner-login'] = $ownerLogin;
            }

            $customer = $client->customer()->create($customerParams);
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Create customer');
        }

        if (!$ip_address) {
            try {
                //get a shared ip to use
                foreach ($client->ip()->get() as $ipInfo) {
                    if ($ipInfo->type === 'shared') {
                        $ip_address = $ipInfo->ipAddress;
                        break;
                    }
                }

                if (!$ip_address) {
                    throw ServiceMisconfiguration::forNoSharedIps();
                }
            } catch (PleskException | PleskClientException | ProviderError $e) {
                //cleanup customer
                $client->customer()->delete('id', $customer->id);

                return $this->handleException($e, 'Get IPs');
            } catch (Throwable $e) {
                //cleanup customer
                $client->customer()->delete('id', $customer->id);
                //let ProviderJob object handle this one
                throw $e;
            }
        }

        try {
            $plan = $client->servicePlan()->get('name', $plan);
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Get plan info');
        }

        try {
            //create webspace
            $webspaceParams = [
                'name' => $domain,
                'owner-id' => $customer->id,
                'ip_address' => $ip_address,
                'htype' => 'vrt_hst'

            ];
            $hostingParams = [
                'ftp_login' => $login,
                'ftp_password' => $passwd
            ];
            $webspace = $client->webspace()->create($webspaceParams, $hostingParams, $plan->name);
        } catch (PleskException | PleskClientException | ProviderError $e) {
            //cleanup customer
            $client->customer()->delete('id', $customer->id);

            return $this->handleException($e, 'Create webspace');
        } catch (Throwable $e) {
            //cleanup customer
            $client->customer()->delete('id', $customer->id);
            //let ProviderJob object handle this one
            throw $e;
        }

        return AccountInfo::create()
            ->setMessage('Account created')
            ->setUsername($login)
            ->setDomain($domain)
            ->setReseller(false)
            ->setServerHostname($this->configuration->hostname)
            ->setIp($ip_address)
            ->setPackageName($plan->name)
            ->setSuspended(false)
            ->setDebug(@compact('customer', 'webspace'));
    }

    protected function createReseller(CreateParams $params): AccountInfo
    {
        $login = $params->username ?? $this->generateUsername($params->domain);
        $ownerLogin = $params->owner_username;
        $email = $params->email;
        $passwd = $params->password;
        $pname = $params->customer_name ?? $login;
        $domain = $params->domain;
        $plan = $params->package_name;
        $ip_address = $params->custom_ip;

        if ($ownerLogin) {
            return $this->errorResult("Cannot specify owner_username when creating a reseller");
        }

        $client = $this->getClient();

        $planRequest = [
            'get' => [
                'filter' => [
                    'name' => $plan
                ]
            ]
        ];

        try {
            $plan = $client->resellerPlan()->request($planRequest);
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Get reseller plan info');
        }

        $resellerRequest = [
            'add' => [
                'gen-info' => compact('pname', 'login', 'passwd', 'email'),
                'plan-id' => $plan->id
            ]
        ];

        try {
            //create reseller
            $customer = $client->reseller()->request($resellerRequest);
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Create reseller');
        }

        if (!$ip_address) {
            try {
                //get a shared ip to use
                foreach ($client->ip()->get() as $ipInfo) {
                    if ($ipInfo->type === 'shared') {
                        $ip_address = $ipInfo->ipAddress;
                        break;
                    }
                }

                if (!$ip_address) {
                    throw new PleskException("Plesk server has no shared IPs");
                }
            } catch (PleskException | PleskClientException | ProviderError $e) {
                //cleanup reseller
                $client->reseller()->delete('id', $customer->id);

                return $this->handleException($e, 'Get IPs');
            } catch (Throwable $e) {
                //cleanup reseller
                $client->reseller()->delete('id', $customer->id);
                //let ProviderJob object handle this one
                throw $e;
            }
        }

        // try {
        //     //assign ip
        //     $resellerRequest = [
        //         'ippool-set-ip' => [
        //             'reseller-id' => $customer->id,
        //             'filter' => [
        //                 'ip-address' => $ip_address,
        //             ]
        //         ]
        //     ];

        //     $client->reseller()->request($resellerRequest);

        // } catch (PleskException | PleskClientException | ProviderError $e) {
        //     //cleanup reseller
        //     $client->reseller()->delete('id', $customer->id);

        //     return $this->handleException($e, 'Assign ip address');
        // } catch (Throwable $e) {
        //     //cleanup reseller
        //     $client->reseller()->delete('id', $customer->id);
        //     //let ProviderJob object handle this one
        //     throw $e;
        // }

        try {
            //create webspace
            $webspaceParams = [
                'name' => $domain,
                'owner-id' => $customer->id,
                'ip_address' => $ip_address,
                'htype' => 'vrt_hst'

            ];
            $hostingParams = [
                'ftp_login' => $login,
                'ftp_password' => $passwd
            ];
            $webspace = $client->webspace()->create($webspaceParams, $hostingParams);
        } catch (PleskException | PleskClientException | ProviderError $e) {
            //cleanup reseller
            $client->reseller()->delete('id', $customer->id);

            return $this->handleException($e, 'Create webspace');
        } catch (Throwable $e) {
            //cleanup reseller
            $client->reseller()->delete('id', $customer->id);
            //let ProviderJob object handle this one
            throw $e;
        }

        return AccountInfo::create()
            ->setMessage('Reseller account created')
            ->setUsername($login)
            ->setDomain($domain)
            ->setReseller(true)
            ->setServerHostname($this->configuration->hostname)
            ->setIp($ip_address)
            ->setPackageName($params->package_name)
            ->setSuspended(false)
            ->setDebug(@compact('customer', 'webspace'));
    }

    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        $username = $params->username;
        // $plan = $params->package_name ?: 'Custom';
        $plan = 'Custom'; // plan quotas / settings don't change

        if ($this->loginBelongsToReseller($username)) {
            return $this->emptyResult('Account is already a reseller');
        }

        if ($plan !== 'Custom') {
            try {
                $this->getPlan($plan, 'reseller'); //check reseller plan exists
            } catch (PleskException | PleskClientException | ProviderError $e) {
                return $this->handleException($e, 'Get reseller plan info');
            }
        }

        $customerRequest = [
            'convert-to-reseller' => [
                'filter' => [
                    'login' => $username
                ],
                'reseller-plan-name' => $plan
            ]
        ];

        $client = $this->getClient();

        try {
            $client->customer()->request($customerRequest);

            return ResellerPrivileges::create()
                ->setMessage('Reseller privileges granted')
                ->setReseller(true);
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Grant reseller privileges');
        }
    }

    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        $username = $params->username;
        // $plan = $params->package_name ?: 'Custom';
        $plan = 'Custom'; // plan quotas / settings don't change

        if (! $this->loginBelongsToReseller($username)) {
            return $this->emptyResult('Account is already not a reseller');
        }

        if ($plan !== 'Custom') {
            try {
                $this->getPlan($plan, 'service'); //check service plan exists
            } catch (PleskException | PleskClientException | ProviderError $e) {
                return $this->handleException($e, 'Get plan info');
            }
        }

        $resellerRequest = [
            'convert-to-customer' => [
                'filter' => [
                    'login' => $username
                ],
                'plan-name' => $plan
            ]
        ];

        $client = $this->getClient();

        try {
            $client->reseller()->request($resellerRequest);

            return ResellerPrivileges::create()
                ->setMessage('Reseller privileges revoked')
                ->setReseller(false);
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Revoke reseller privileges');
        }
    }

    public function getInfo(AccountUsername $params): AccountInfo
    {
        $username = $params->username;

        if ($this->loginBelongsToReseller($username)) {
            return $this->getResellerInfo($username);
        }

        $customerRequest = [
            'get' => [
                'filter' => [
                    'login' => $username
                ],
                'dataset' => [
                    'stat' => 'stat'
                ]
            ]
        ];

        $client = $this->getClient();

        try {
            $account_info = $client->customer()->request($customerRequest)->data->stat;

            return $this->emptyResult('Customer stats retrieved', compact('account_info'));
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Get customer stats');
        }
    }

    protected function getResellerInfo(string $username): EmptyResult
    {
        $resellerRequest = [
            'get' => [
                'filter' => [
                    'login' => $username
                ],
                'dataset' => [
                    'stat' => 'stat'
                ]
            ]
        ];

        $client = $this->getClient();

        try {
            $account_info = $client->reseller()->request($resellerRequest)->data->stat;

            return $this->emptyResult('Reseller stats retrieved', compact('account_info'));
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Get reseller stats');
        }
    }

    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        $username = $params->username;
        $plan = $params->package_name;

        if ($this->loginBelongsToReseller($username)) {
            return $this->changeResellerPackage($username, $plan);
        }

        $client = $this->getClient();

        try {
            $plan = $this->getPlan($plan);
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Get plan info');
        }

        $webspaceRequest = [
            'switch-subscription' => [
                'filter' => [
                    'owner-login' => $username
                ],
                'plan-guid' => $plan->guid
            ]
        ];

        try {
            $response = $client->webspace()->request($webspaceRequest);

            return $this->getInfo(AccountUsername::create(['username' => $params->username]))
                ->setMessage('Package changed');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, "Change customer package");
        }
    }

    protected function changeResellerPackage(string $username, string $plan): EmptyResult
    {
        $client = $this->getClient();

        try {
            $plan = $this->getPlan($plan, 'reseller');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Get reseller plan info');
        }

        $webspaceRequest = [
            'switch-subscription' => [
                'filter' => [
                    'login' => $username
                ],
                'plan-guid' => $plan->guid
            ]
        ];

        try {
            $response = $client->reseller()->request($webspaceRequest);

            return $this->emptyResult("Reseller package changed");
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, "Change reseller package");
        }
    }

    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        $username = $params->username;
        $user_ip = $params->user_ip;

        $client = $this->getClient();

        try {
            $sessionId = $client->server()->createSession($username, $user_ip);

            if ('windows' === $this->configuration->operating_system) {
                $sessionKey = 'PHPSESSID';
            } else {
                // linux
                $sessionKey = 'PLESKSESSID';
            }

            $path = "/enterprise/rsession_init.php?{$sessionKey}={$sessionId}";
            $url = $this->getServerUrl($path);

            return LoginUrl::create()
                ->setMessage('Session created')
                ->setLoginUrl($url)
                ->setForIp($user_ip)
                ->setExpires(Carbon::now()->addMinutes(30)); // default 30 minute session idle time
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Create session');
        }
    }

    public function suspend(SuspendParams $params): AccountInfo
    {
        $username = $params->username;

        if ($this->loginBelongsToReseller($username)) {
            return $this->suspendReseller($username);
        }

        $requestParams = [
            'set' => [
                'filter' => [
                    'login' => $username
                ],
                'values' => [
                    'gen_info' => [
                        // disabled by administrator - https://support.plesk.com/hc/en-us/articles/213902805
                        'status' => 16
                    ]
                ]
            ]
        ];

        $client = $this->getClient();

        try {
            $client->customer()->request($requestParams);

            return $this->getInfo(AccountUsername::create(['username' => $params->username]))
            ->setMessage('Account suspended');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Suspend account');
        }
    }

    protected function suspendReseller(string $username): AccountInfo
    {
        $requestParams = [
            'set' => [
                'filter' => [
                    'login' => $username
                ],
                'values' => [
                    'gen-info' => [
                        // disabled by administrator - https://support.plesk.com/hc/en-us/articles/213902805
                        'status' => 16
                    ]
                ]
            ]
        ];

        $client = $this->getClient();

        try {
            $client->reseller()->request($requestParams);

            return $this->getInfo(AccountUsername::create(['username' => $username]))
            ->setMessage('Reseller suspended');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Suspend reseller');
        }
    }

    public function unSuspend(AccountUsername $params): AccountInfo
    {
        $username = $params->username;

        if ($this->loginBelongsToReseller($username)) {
            return $this->unSuspendReseller($username);
        }

        $requestParams = [
            'set' => [
                'filter' => [
                    'login' => $username
                ],
                'values' => [
                    'gen_info' => [
                        'status' => 0 //active - https://support.plesk.com/hc/en-us/articles/213902805
                    ]
                ]
            ]
        ];

        $client = $this->getClient();

        try {
            $client->customer()->request($requestParams);

            return $this->getInfo(AccountUsername::create(['username' => $params->username]))
            ->setMessage('Account unsuspended');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Unsuspend account');
        }
    }

    public function unSuspendReseller(string $username): AccountInfo
    {
        $requestParams = [
            'set' => [
                'filter' => [
                    'login' => $username
                ],
                'values' => [
                    'gen-info' => [
                        'status' => 0 //active - https://support.plesk.com/hc/en-us/articles/213902805
                    ]
                ]
            ]
        ];

        $client = $this->getClient();

        try {
            $client->reseller()->request($requestParams);

            return $this->getInfo(AccountUsername::create(['username' => $username]))
            ->setMessage('Reseller unsuspended');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Unsuspend reseller');
        }
    }

    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        $username = $params->username;
        $password = $params->password;

        if ($this->loginBelongsToReseller($username)) {
            return $this->changeResellerPassword($username, $password);
        }

        $requestParams = [
            'set' => [
                'filter' => [
                    'login' => $username
                ],
                'values' => [
                    'gen_info' => [
                        'passwd' => $password
                    ]
                ]
            ]
        ];

        $client = $this->getClient();

        try {
            $client->customer()->request($requestParams);

            return $this->emptyResult('Password changed');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Change password');
        }
    }

    public function changeResellerPassword(string $username, string $password): EmptyResult
    {
        $requestParams = [
            'set' => [
                'filter' => [
                    'login' => $username
                ],
                'values' => [
                    'gen-info' => [
                        'passwd' => $password
                    ]
                ]
            ]
        ];

        $client = $this->getClient();

        try {
            $client->reseller()->request($requestParams);

            return $this->emptyResult('Password changed');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Change password');
        }
    }

    public function terminate(AccountUsername $params): EmptyResult
    {
        $username = $params->username;

        if ($this->loginBelongsToReseller($username)) {
            return $this->terminateReseller($username);
        }

        $client = $this->getClient();

        try {
            $client->customer()->delete('login', $username);

            return $this->emptyResult('Account deleted');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Delete account');
        }
    }

    public function terminateReseller(string $username): EmptyResult
    {
        $client = $this->getClient();

        try {
            $client->reseller()->delete('login', $username);

            return $this->emptyResult('Reseller deleted');
        } catch (PleskException | PleskClientException | ProviderError $e) {
            return $this->handleException($e, 'Delete reseller');
        }
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

        return $username;

        // if ($this->newUsernameIsValid($username)) {
        //     return $username;
        // }

        $username = substr($username, 0, $this->getMaxUsernameLength() - 1) . rand(1, 9);

        return $this->generateUsername($username, $count++);
    }

    protected function getMaxUsernameLength(): int
    {
        return 8;
    }

    /**
     * @param string $username Login username
     *
     * @return bool
     */
    protected function loginBelongsToReseller(string $username): bool
    {
        $client = $this->getClient();

        try {
            $client->reseller()->get('login', $username); //throws api exception if login not a reseller

            return true;
        } catch (PleskException $e) {
            return false;
        }
    }

    /**
     * @param string $name Name of the plan
     * @param string $type Type of plan i.e., service or reseller
     *
     * @throws PleskException If plan doesn't exist
     *
     * @return XmlResponse
     */
    protected function getPlan(string $name, string $type = 'service'): XmlResponse
    {
        $operator = strtolower($type) . 'Plan';

        $planRequest = [
            'get' => [
                'filter' => [
                    'name' => $name
                ]
            ]
        ];

        return $this->getClient()->$operator()->request($planRequest);
    }

    protected function getServerUrl(string $path = ''): string
    {
        $hostname = $this->configuration->hostname;
        $port = $this->configuration->port ?: 8443;
        $protocol = $this->configuration->protocol ?: 'https';
        $path = ltrim($path, '/');

        return "{$protocol}://{$hostname}:{$port}/{$path}";
    }

    protected function handleException(
        Throwable $e,
        string $failedOperation = 'XML API request',
        array $data = [],
        array $debugData = []
    ): void {
        $message = $failedOperation . " failed: " . str_replace("\n", ' ', $e->getMessage());

        $this->errorResult($message, $data, $debugData, $e);
    }

    protected function getClient(): Client
    {
        if ($this->client) {
            return $this->client;
        }

        $hostname = $this->configuration->hostname;
        $port = $this->configuration->port ?: 8443;
        $protocol = $this->configuration->protocol ?: 'https';

        $client = new Client($hostname, $port, $protocol);

        $admin_username = $this->configuration->admin_username;
        $admin_password = $this->configuration->admin_password;
        $secret_key = $this->configuration->secret_key;

        if ($secret_key) {
            $client->setSecretKey($secret_key);
        } else {
            $client->setCredentials($admin_username, $admin_password);
        }

        return $this->client = $client;
    }
}
