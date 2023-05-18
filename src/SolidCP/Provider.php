<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\SolidCP;

use SoapClient;
use Carbon\Carbon;
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
use Upmind\ProvisionProviders\SharedHosting\SolidCP\Data\Configuration;

/**
 * SolidCP hosting provider template.
 */
class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;
    protected $client;
    protected $clientInfo;
    protected $caching = false;

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
            ->setName('SolidCP')
            ->setDescription('SolidCP provider');
    }

    /**
     * @inheritDoc
     */
    public function create(CreateParams $params): AccountInfo
    {
        $client_params = [
            'parentPackageId' => 1,
            'username' => $params['username'],
            'password' => $params['password'],
            'roleId' => ($params['as_reseller']) ? 2 : 3,
            'firstName' => explode(" ", $params['customer_name'])[0],
            'lastName' => explode(" ", $params['customer_name'])[1],
            'email' => $params['email'],
            'htmlMail' => false,
            'sendAccountLetter' => false,
            'createPackage' => true,
            'planId' => $params['package_name'],
            'sendPackageLetter' => false,
            'domainName' => $params['domain'],
            'tempDomain' => false,
            'createWebSite' => true,
            'createFtpAccount' => false,
            'createMailAccount' => false,
            'hostName' => '',
            'createZoneRecord' => true
        ];

        $this->apiCall('Packages', 'CreateUserWizard', $client_params,'CreateUserWizardResult');

        return $this->getInfo(AccountUsername::create(['username' => $params['username']]))->setMessage('Account created successfully');
    }

    /**
     * @inheritDoc
     */
    public function getInfo(AccountUsername $params): AccountInfo
    {

        $result = $this->getInfoByUsername($params['username']);
        $package = $this->getPackage($params['username']);
        $this->getLogger()->debug('error', (array)$package);
        return AccountInfo::create(
            [
                'customer_id' => (string) $result->UserId,
                'username' => $params['username'],
                'domain' => $params['domain'],
                'reseller' => ($result->RoleId == 2) ? true : false,
                'server_hostname' => $this->configuration->hostname,
                'package_name' => (string) isset($package->PackageName) ? $package->PackageName : '',
                'suspended' => ($result->Status == 'Active') ? 0 : 1,
                'suspend_reason' => null,
                'ip' => null,
                'nameservers' => null,
            ]
        );

    }
    /**
     * @inheritDoc
     */
    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        return LoginUrl::create()
                ->setMessage('Showing login url')
                ->setLoginUrl('');
    }
    public function getInfoByUsername($username): stdClass
    {
        $client_params = ['username' => $username];
        $result = $this->apiCall('Users', 'GetUserByUsername', $client_params);

        if(!(array)$result) {
            throw $this->errorResult($this->getFriendlyError('-101'));
        }

        return $result->GetUserByUsernameResult;
    }
    public function getPackage($username): Object
    {
        $userId = $this->getInfoByUsername($params['username'])->UserId;
        $client_params = ['username' => $username, 'userId' => $userId ];
        $result = $this->apiCall('Packages', 'GetMyPackages', $client_params,'GetMyPackagesResult')->PackageInfo;

        return $result;
    }
    /**
     * @inheritDoc
     */
    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        $userId = $this->getInfoByUsername($params['username'])->UserId;
        $client_params = ['username' => $params['username'], 'password' => $params['password'], 'userId' => $userId ];
        $this->apiCall('Users', 'ChangeUserPassword', $client_params,'ChangeUserPasswordResult');

        return $this->emptyResult('Password changed');
    }

    /**
     * @inheritDoc
     */
    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        $package = $this->getPackage($params['username']);
        $client_params = [
                          'packageId' => $package->PackageId,
                          'statusId' => $package->StatusId,
                          'planId' => $params['package_name'],
                          'purchaseDate' => $package->PurchaseDate,
                          'packageName' => $params['package_name'],
                          'packageComments' => isset($package->packageComments) ? $package->packageComments : ''
                         ];
        $this->apiCall('Packages', 'UpdatePackageLiteral', $client_params,'UpdatePackageLiteralResult');

        return $this->getInfo(AccountUsername::create(['username' => $params['username']]))->setMessage('Package updated');
    }
    /**
     * @inheritDoc
     */
    public function suspend(SuspendParams $params): AccountInfo
    {
        $userId = $this->getInfoByUsername($params['username'])->UserId;
        $client_params = ['username' => $params['username'], 'status' => 'Suspended', 'userId' => $userId ];
        $this->apiCall('Users', 'ChangeUserStatus', $client_params,'ChangeUserStatusResult');

        return $this->getInfo(AccountUsername::create(['username' => $username]))->setMessage('Account suspended');
    }
    /**
     * @inheritDoc
     */
    public function unSuspend(AccountUsername $params): AccountInfo
    {
        $userId = $this->getInfoByUsername($params['username'])->UserId;
        $client_params = ['username' => $params['username'], 'status' => 'Active', 'userId' => $userId ];
        $this->apiCall('Users', 'ChangeUserStatus', $client_params,'ChangeUserStatusResult');

        return $this->getInfo(AccountUsername::create(['username' => $username]))->setMessage('Account is now Active');
    }
    /**
     * @inheritDoc
     */
    public function terminate(AccountUsername $params): EmptyResult
    {
        $userId = $this->getInfoByUsername($params['username'])->UserId;
        $client_params = [ 'username' => $params['username'], 'userId' => $userId ];
        $result = $this->apiCall('Users', 'DeleteUser', $client_params,'DeleteUserResult');
        if($result < 0) {
            throw $this->errorResult($this->getFriendlyError($result));
        }

        return $this->emptyResult('User has been deleted');
    }
    /**
     * @inheritDoc
     */
    public function changeReseller($username, $roleid, $message): ResellerPrivileges
    {
        $client_params = $this->getInfoByUsername($username);
        $client_params->RoleId = $roleid;
        $client_params->Role = ($roleid == '2') ? 'Reseller' : 'User';

        $this->apiCall('Users', 'UpdateUser', ['user' => $client_params],'UpdateUserResult');

        return ResellerPrivileges::create()
            ->setMessage($message)
            ->setReseller(($roleid == '2') ? true : false);
    }
    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        return $this->changeReseller($params['username'], '2', 'Reseller privileges granted');
    }
    /**
     * @inheritDoc
     */
    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        return $this->changeReseller($params['username'], '3', 'Reseller privileges revoked');
    }

    /**
     * Get a SOAP
     */
    protected function apiCall($service, $method, $params,$res_param = null)
    {
        $serverPort = $this->configuration->port ?: 9002;
        $host = "http://{$this->configuration->hostname}:{$serverPort}/es{$service}.asmx?WSDL";

        try {
            // Create the SoapClient
            $client = new SoapClient(
                $host,
                [
                                                'login'       => $this->configuration->username,
                                                'password'    => $this->configuration->password,
                                                'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
                                                'cache_wsdl'  => ($this->caching) ? 1 : 0
                                            ]
            );
            // Execute the request and process the results
            $result = call_user_func(array($client, $method), $params);

                if ($this->coniguration->debug) {
                    $this->getLogger()->debug('SOAP Request: ' . $client->__getLastResquest());
                    $this->getLogger()->debug('SOAP Response: ' . $client->__getLastResponse());
                }
            if($res_param)
                $result = $result->$res_param;
            return $result;
        } catch (\SoapFault $e) {
            throw $this->errorResult("SOAP Fault: (Code: {$e->getCode()}, Message: {$e->getMessage()})");
        } catch (\Exception | \ErrorException $e) {
            throw $this->errorResult("General Fault: (Code: {$e->getCode()}, Message: {$e->getMessage()})",$e);
        }
    }
    public static function getFriendlyError($code)
    {
        $errors = [
                            -100  => 'Username not available, already in use',
                            -101  => 'Username not found, invalid username',
                            -102  => 'User\'s account has child accounts',
                            -300  => 'Hosting package could not be found',
                            -301  => 'Hosting package has child hosting spaces',
                            -501  => 'The sub-domain belongs to an existing hosting space that does not allow sub-domains to be created',
                            -502  => 'The domain or sub-domain exists in another hosting space / user account',
                            -511  => 'Preview Domain is enabled, but not configured',
                            -601  => 'The website already exists on the target hosting space or server',
                            -700  => 'The email domain already exists on the target hosting space or server',
                            -1100 => 'User already exists',
                            0     => 'Success'
                            ];

        // Find the error and return it, else a general error will do!
        if (array_key_exists($code, $errors)) {
            return $errors[$code];
        } else {
            return "An unknown error occured (Code: {$code}).";
        }
    }
}
