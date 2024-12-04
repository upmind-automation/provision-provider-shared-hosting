<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\OviPanel;

use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;
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
use Upmind\ProvisionProviders\SharedHosting\OviPanel\Data\Configuration;


/**
 * Example hosting provider template.
 *
 * @property-read string $ip The IP address of the OviPanel server
 * @property-read string $api_key The API key for accessing the OviPanel API
 * @property-read string $adminusername The admin username for OviPanel
 * @property-read string $adminpassword The admin password for OviPanel
 */


class Provider extends Category implements ProviderInterface
{
    protected $configuration;

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
            ->setName('ovipanel')
            ->setDescription('Manage Ovipanel User Control Panel')
            ->setLogoUrl('https://www.ovipanel.in/images/logo/logo.png');
    }


    /**
     * @inheritDoc
     */


    public function create(CreateParams $params): AccountInfo
    {
        $user = htmlspecialchars(trim($params->username));
        $email_notify = htmlspecialchars(trim($params->email));
        $password = htmlspecialchars(trim($params->password));
        $inDomainName = htmlspecialchars(trim(strtolower($params->domain)));
        $package_id = htmlspecialchars(trim($params->package_name));

        $input_tag = '<resellerid>1</resellerid>' .
            '<username>' . $user . '</username>' .
            '<packageid>' . $package_id . '</packageid>' .
            '<groupid>3</groupid>' .
            '<fullname></fullname>' .
            '<email>' . $email_notify . '</email>' .
            '<address></address>' .
            '<postcode></postcode>' .
            '<phone></phone>' .
            '<password>' . $password . '</password>' .
            '<sendemail>true</sendemail>' .
            '<emailsubject></emailsubject>' .
            '<emailbody></emailbody>' .
            '<domain>' . $inDomainName . '</domain>';

        $data = $this->ovipanel_CurlExecute($input_tag, "CreateClient");

        $data = (array) json_decode($data);

        if ($data['error']) {
            $error = $data['error'];
            throw $this->errorResult('Error: ' . $error);
        }

        return $this->getInfo(AccountUsername::create(['username' => $params->username]))
            ->setMessage('Account created');
    }


    /**
     * @inheritDoc
     */


    public function getInfo(AccountUsername $params): AccountInfo
    {
        return $this->getAccountInfo(
            $params->username,
            isset($params->is_reseller) ? boolval($params->is_reseller) : null
        );
    }


    /**
     * @inheritDoc
     */


    public function getUsage(AccountUsername $params): AccountUsage
    {
        throw $this->errorResult('Not implemented');
    }


    /**
     * @inheritDoc
     */


    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        // throw $this->errorResult('Not implemented');

        $username = $params->username;
        $user_ip = $params->user_ip;

        $client = $this->getClient();

        try {

            $url = "http://" . $user_ip . ":2082/index.php";

            return LoginUrl::create()
                ->setMessage('Session created')
                ->setLoginUrl($url)
                ->setForIp(null)
                ->setExpires(Carbon::now()->addMinutes(30)); // default 30 minute session idle time
        } catch (Exception $e) {
            return $this->handleException($e, 'Create session');
        }
    }


    /**
     * @inheritDoc
     */


    public function getAccountInfo(AccountUsername $params): AccountInfo
    {
       $username = htmlspecialchars(trim($params->username));
       $username_valid = $this->ovipanel_UsernameExists($params->username);

       if (!$username_valid) {
          $error = 'Account Info Failed. Username ' . $username . ' does not exist.';
          return $this->errorResult('Error: ' . $error);
       }

       $input_tag = '<username>' . $username . '</username>';
       $return = $this->ovipanel_CurlExecute($input_tag, "GetClientDetails");
       $data = json_decode($return);

       if ($data === null) {
          $error = "Failed to decode JSON";
          return $this->errorResult('Error: ' . $error);
       } 
       elseif ($data->error) {
          $error = "Error: " . $data->error;
          return $this->errorResult($error);
       }

       $accountInfo = AccountInfo::create([
         'username' => $username,
         'domain' => $data->domain ?? '',
         'package_id' => $data->packageid ?? '',
         'email' => $data->email ?? '',
        // Add other account information properties here
       ]);

         return $accountInfo;

    }


    /**
     * @inheritDoc
     */


    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        // throw $this->errorResult('Not implemented');

        $username = htmlspecialchars(trim($params->username));
        $username_valid = $this->ovipanel_UsernameExists($params->username);

        if ($username_valid == 'false') {
            $error = 'Account changePassword Failed. Username ' . $username . ' does not exists.';
            return $this->emptyResult('Error: ' . $error);
        }

        $input_tag = '<username>' . $username . '</username>';
        $return = $this->ovipanel_CurlExecute($input_tag, "changepassword");
        $data = json_decode($return);

        if ($data === null) {
            $error = "Failed to decode JSON";
            throw $this->emptyResult('Error: ' . $error);
        } elseif ($data->success) {
            return $this->emptyResult('Password changed');
        }
    }


    /**
     * @inheritDoc
     */


    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        //  throw $this->errorResult('Not implemented');

        $username = htmlspecialchars(trim($params->username));
        $username_valid = $this->ovipanel_UsernameExists($params->username);

        if ($username_valid == 'false') {
            $error = 'Account changePackage Failed. Username ' . $username . ' does not exists.';
            return $this->getInfo(AccountUsername::create(['username' => $params->username]))
                ->setMessage($error);
        }
        $input_tag = '<username>' . $username . '</username>';
        $return = $this->ovipanel_CurlExecute($input_tag, "changePackage");
        $data = json_decode($return);

        if ($data === null) {
            $error = "Failed to decode JSON";
            return $this->errorResult('Error: ' . $error);
        } elseif ($data->success) {
            return $this->getInfo(AccountUsername::create(['username' => $params->username]))->setMessage($data->success);
        }
    }


    /**
     * @inheritDoc
     */


    public function suspend(SuspendParams $params): AccountInfo
    {
        // throw $this->errorResult('Not implemented');
        $username = htmlspecialchars(trim($params->username));
        $username_valid = $this->ovipanel_UsernameExists($params->username);

        if ($username_valid == 'false') {
            $error = 'Account Suspend Failed. Username ' . $username . ' does not exists.';
            return $this->getInfo(AccountUsername::create(['username' => $params->username]))->setMessage($error);
        }

        $input_tag = '<username>' . $username . '</username>';
        $return = $this->ovipanel_CurlExecute($input_tag, "DisableClient");
        $data = json_decode($return);

        if ($data === null) {
            $error = "Failed to decode JSON";
            return $this->errorResult('Error: ' . $error);
        } elseif ($data->success) {
            return $this->getInfo(AccountUsername::create(['username' => $params->username]))->setMessage($data->success);
        }
    }


    /**
     * @inheritDoc
     */


    public function unSuspend(AccountUsername $params): AccountInfo
    {
        // throw $this->errorResult('Not implemented');

        $username = htmlspecialchars(trim($params->username));
        $username_valid = $this->ovipanel_UsernameExists($params->username);

        if ($username_valid == 'false') {
            $error = 'Account Unsuspend Failed. Username ' . $username . ' does not exists.';
            return $this->errorResult('Error: ' . $error);
        }

        $input_tag = '<username>' . $username . '</username>';
        $return = $this->ovipanel_CurlExecute($input_tag, "EnableClient");
        $data = json_decode($return);

        if ($data === null) {
            $error = "Failed to decode JSON";
            return $this->errorResult('Error: ' . $error);
        } elseif ($data->success) {
            return $this->getInfo(AccountUsername::create(['username' => $params->username]))->setMessage($data->success);
        }
    }


    /**
     * @inheritDoc
     */


    public function terminate(AccountUsername $params): EmptyResult
    {
        // throw $this->errorResult('Not implemented');
        //$relid = $params['serviceid'];
        $username = htmlspecialchars(trim($params->username));
        $username_valid = $this->ovipanel_UsernameExists($params->username);

        if ($username_valid == 'false') {
            $error = 'Account Terminate Failed. Username ' . $username . ' does not exists.';
            return $this->errorResult('Error: ' . $error);
        }

        $input_tag = '<username>' . $username . '</username>';
        $return = $this->ovipanel_CurlExecute($input_tag, "DeleteClient");
        $data = json_decode($return);

        if ($data === null) {
            $error = "Failed to decode JSON";
            return $this->errorResult('Error: ' . $error);
        } elseif ($data->success) {
            return $this->emptyResult('Account deleted');
        }
    }


    /**
     * @inheritDoc
     */


    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        throw $this->errorResult('Not implemented');

        $username = htmlspecialchars(trim($params->username));
        $username_valid = $this->ovipanel_UsernameExists($params->username);

        if ($username_valid == 'false') {
            $error = 'Account grantReseller Failed. Username ' . $username . ' does not exists.';
            return $this->errorResult('Error: ' . $error);
        }

        $input_tag = '<username>' . $username . '</username>';
        $return = $this->ovipanel_CurlExecute($input_tag, "grantReseller");
        $data = json_decode($return);

        if ($data === null) {
            $error = "Failed to decode JSON";
            return $this->errorResult('Error: ' . $error);
        } elseif ($data->success) {
            return ResellerPrivileges::create()
                ->setMessage('Reseller privileges granted')
                ->setReseller(true);
        }
    }


    /**
     * @inheritDoc
     */


    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        throw $this->errorResult('Not implemented');

        $username = htmlspecialchars(trim($params->username));
        $username_valid = $this->ovipanel_UsernameExists($params->username);

        if ($username_valid == 'false') {
            $error = 'Account revokeReseller Failed. Username ' . $username . ' does not exists.';
            return $this->errorResult('Error: ' . $error);
        }

        $input_tag = '<username>' . $username . '</username>';
        $return = $this->ovipanel_CurlExecute($input_tag, "revokeReseller");
        $data = json_decode($return);

        if ($data === null) {
            $error = "Failed to decode JSON";
            return $this->errorResult('Error: ' . $error);
        } elseif ($data->success) {
            return ResellerPrivileges::create()->setMessage('Reseller privileges revoked')->setReseller(false);
        }
    }


    /**
     * Executes a cURL request to the Ovipanel API.
     *
     * @param string $input_tag The input XML tag
     * @param string $request The request type
     * @return string The response from the API
     */


    public function ovipanel_CurlExecute(string $input_tag, string $request): string
    {
        $ip = htmlspecialchars(trim($this->configuration->ip));
        $api_key = htmlspecialchars(trim($this->configuration->api_key));
        $serverusername = htmlspecialchars(trim($this->configuration->adminusername));
        $serverpassword = htmlspecialchars(trim($this->configuration->adminpassword));

        $input_xml = '<?xml version="1.0" encoding="UTF-8" ?>' .
            '<xmws>' .
            '<apikey>' . $api_key . '</apikey>' .
            '<request>' . $request . '</request>' .
            '<authuser>' . $serverusername . '</authuser>' .
            '<authpass>' . $serverpassword . '</authpass>' .
            '<content>' . $input_tag . '</content>' .
            '</xmws>';

        $url = "http://$ip:2086/bin/oviapi.php?module=manage_clients";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "xmlRequest=" . $input_xml);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $data = curl_exec($ch);
        curl_close($ch);

        return $data;
    }


    /**
     * Checks if the username exists in Ovipanel.
     *
     * @param string $username The username to check
     * @return bool Returns true if the username exists, false otherwise
     */


    public function ovipanel_UsernameExists($username)
    {
        $input_tag = '<username>' . $username . '</username>';
        $data = $this->ovipanel_CurlExecute($input_tag, "UsernameExists");
        $data = json_decode($data, true);
        return isset($data['success']) ? (bool) $data['success'] : false;
    }


    /**
     * Get a Guzzle HTTP client instance.
     *
     * @return Client The Guzzle HTTP client instance
     */


    protected function client(): Client
    {
       return new Client([
          'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
          'base_uri' => sprintf('https://%s/v1/', $this->configuration->hostname),
          'headers' => [
            'Authorization' => 'Bearer ' . $this->configuration->api_token,
            ],
        ]);

    }
}
