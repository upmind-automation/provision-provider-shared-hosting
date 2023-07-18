<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\ovi;

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
use Upmind\ProvisionProviders\SharedHosting\ovi\Data\Configuration;


/**
 * Example hosting provider template.
 */
class Provider extends Category implements ProviderInterface
{
   // protected Configuration $configuration;
   // protected Client $client;

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
       // throw $this->errorResult('Not implemented');
          
        $user = runtime_sanatizeItem::sanatizeItem(trim($params->username),"string");
        $email_notify = runtime_sanatizeItem::sanatizeItem(trim($params->email),"string");
        $password = runtime_sanatizeItem::sanatizeItem(trim($params->password),"pass");
        $inDomainName = runtime_sanatizeItem::sanatizeItem(trim(strtolower($params->domain)),"string");
        $package_id = runtime_sanatizeItem::sanatizeItem(trim($params->package_name),"string");

        $input_tag='<resellerid>1</resellerid>'.
           '<username>'.$user.'</username>'.
           '<packageid>'.$package_id.'</packageid>'.
           '<groupid>3</groupid>'.
           '<fullname></fullname>'.
           '<email>'.$email_notify.'</email>'.
           '<address></address>'.
           '<postcode></postcode>'.
           '<phone></phone>'.
           '<password>'.$password.'</password>'.
           '<sendemail>true</sendemail>'.
           '<emailsubject></emailsubject>'.
           '<emailbody></emailbody>'.
           '<domain>'.$inDomainName.'</domain>';

        $data = $this->ovipanel_CurlExecute($input_tag, "CreateClient");

       
        $data = (array)json_decode($data);
       

        if($data['error'])
        {
            $error = $data['error'];
            //return $error;
 	    return $this->errorResult('Error: '.$error);

       } else {
            return $this->getInfo(AccountUsername::create(['username' => $params->username]))
            ->setMessage('Account created');

	}
    }
    /**
     * @inheritDoc
     */
    
    public function getInfo(AccountUsername $params): AccountInfo
    {
        // $accountInfo = $this->client()->get(sprintf('accounts/%s', $username));

        return AccountInfo::create()
            ->setDomain($params->domain)
            ->setUsername($params->username)
            ->setServerHostname($this->configuration->hostname)
            ->setPackageName('Example Hosting')
            ->setReseller(false)
            ->setSuspended(false);
    }

public function getUsage(AccountUsername $params): AccountUsage
    {
        throw $this->errorResult('Not implemented');
     /*      $username = runtime_sanatizeItem::sanatizeItem(trim($params->username),"string");
           $username_valid = $this->ovipanel_UsernameExists($params->username);

           if($username_valid ==  'false')
           {
        $error = 'Account getUsage Failed. Username '.$username.' does not exists.';
        return $error;
           }


            $input_tag='<username>'.$username.'</username>';
            $data = $this->ovipanel_CurlExecute( $input_tag, "getUsage");

            return $data;
*/
    }

    /**
     * @inheritDoc
     */
    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        throw $this->errorResult('Not implemented');
    }

    /**
     * @inheritDoc
     */
    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
       // throw $this->errorResult('Not implemented');

           $username = runtime_sanatizeItem::sanatizeItem(trim($params->username),"string");
           $username_valid = $this->ovipanel_UsernameExists($params->username);

           if($username_valid ==  'false')
           {
        	$error = 'Account changePassword Failed. Username '.$username.' does not exists.';
                return $this->emptyResult('Error: ' . $error);
	   }
           $input_tag='<username>'.$username.'</username>';
           $return = $this->ovipanel_CurlExecute($input_tag, "changepassword");
           $data = json_decode($return);
           if ($data === null) 
	   {
	        $error = "Failed to decode JSON";
		return $this->emptyResult('Error: ' . $error);
           }
           elseif ($data->success) 
	   {
     
		return $this->emptyResult('Password changed');
          }

   } 

    /**
     * @inheritDoc
     */
    public function changePackage(ChangePackageParams $params): AccountInfo
    {
      //  throw $this->errorResult('Not implemented');

           $username = runtime_sanatizeItem::sanatizeItem(trim($params->username),"string");
           $username_valid = $this->ovipanel_UsernameExists($params->username);

           if($username_valid ==  'false')
           {
		$error = 'Account changePackage Failed. Username '.$username.' does not exists.';
        	return $this->getInfo(AccountUsername::create(['username' => $params->username]))
            ->setMessage($error);
           }
            $input_tag='<username>'.$username.'</username>';
            $return = $this->ovipanel_CurlExecute($input_tag, "changePackage");
            $data = json_decode($return);
            if ($data === null) 
	    {
	        $error = "Failed to decode JSON";
        	return $this->errorResult('Error: ' . $error);
            }
           elseif ($data->success) 
	   {
		return $this->getInfo(AccountUsername::create(['username' => $params->username]))->setMessage($data->success);
           }
    }

    /**
     * @inheritDoc
     */
    public function suspend(SuspendParams $params): AccountInfo
    {
       // throw $this->errorResult('Not implemented');
         $username = runtime_sanatizeItem::sanatizeItem(trim($params->username),"string");
         $username_valid = $this->ovipanel_UsernameExists($params->username);

          if($username_valid ==  'false')
         {
	        $error = 'Account Suspend Failed. Username '.$username.' does not exists.';
        	return $this->getInfo(AccountUsername::create(['username' => $params->username]))->setMessage($error);
          }

          $input_tag='<username>'.$username.'</username>';
          $return = $this->ovipanel_CurlExecute( $input_tag, "DisableClient");
          $data = json_decode($return);
          if ($data === null) 
	  {
	        $error = "Failed to decode JSON";
        	return $this->errorResult('Error: ' . $error);
          }
         elseif ($data->success) 
	{
		return $this->getInfo(AccountUsername::create(['username' => $params->username]))->setMessage($data->success);
        }
    }

    /**
     * @inheritDoc
     */
    public function unSuspend(AccountUsername $params): AccountInfo
    {
       // throw $this->errorResult('Not implemented');
         
          $username = runtime_sanatizeItem::sanatizeItem(trim($params->username),"string");
          $username_valid = $this->ovipanel_UsernameExists($params->username);

          if($username_valid ==  'false')
          {
           $error = 'Account Unsuspend Failed. Username '.$username.' does not exists.';
           return $this->errorResult('Error: ' . $error);
          }

          $input_tag='<username>'.$username.'</username>';
          $return = $this->ovipanel_CurlExecute( $input_tag, "EnableClient");
          $data = json_decode($return);
          if ($data === null) {
          $error = "Failed to decode JSON";
          return $this->errorResult('Error: ' . $error);
          }
         elseif ($data->success) 
         {
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
           $username = runtime_sanatizeItem::sanatizeItem(trim($params->username),"string");
           $username_valid = $this->ovipanel_UsernameExists($params->username);

           if($username_valid ==  'false')
           {
        	$error = 'Account Terminate Failed. Username '.$username.' does not exists.';
		return $this->errorResult('Error: ' . $error);
           }

            $input_tag='<username>'.$username.'</username>';
            $return = $this->ovipanel_CurlExecute( $input_tag, "DeleteClient");
	    $data = json_decode($return);
		if ($data === null) {
	        $error = "Failed to decode JSON";
        	return $this->errorResult('Error: ' . $error);
            	}
	         elseif ($data->success) {
          	return $this->emptyResult('Account deleted');
		}
    }

    /**
     * @inheritDoc
     */
    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        throw $this->errorResult('Not implemented');
           $username = runtime_sanatizeItem::sanatizeItem(trim($params->username),"string");
           $username_valid = $this->ovipanel_UsernameExists($params->username);

           if($username_valid ==  'false')
           {
        $error = 'Account grantReseller Failed. Username '.$username.' does not exists.';
           return $this->errorResult('Error: ' . $error);
	   }

            $input_tag='<username>'.$username.'</username>';
            $return = $this->ovipanel_CurlExecute( $input_tag, "grantReseller");

            $data = json_decode($return);
                if ($data === null) {
           $error = "Failed to decode JSON";
           return $this->errorResult('Error: ' . $error);
            }
         elseif ($data->success) {
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

           $username = runtime_sanatizeItem::sanatizeItem(trim($params->username),"string");
           $username_valid = $this->ovipanel_UsernameExists($params->username);

           if($username_valid ==  'false')
           {
        	$error = 'Account revokeReseller Failed. Username '.$username.' does not exists.';
	        return $this->errorResult('Error: ' . $error);
           }

            $input_tag='<username>'.$username.'</username>';
            $return = $this->ovipanel_CurlExecute( $input_tag, "revokeReseller");

            $data = json_decode($return);
                if ($data === null) 
		{
		        $error = "Failed to decode JSON";
		        return $this->errorResult('Error: ' . $error);
            	}
	         elseif ($data->success) {
		   return ResellerPrivileges::create()->setMessage('Reseller privileges revoked')->setReseller(false);
          	}
    }

    /**
     * @inheritDoc
     */

      public function sanatizeItem($var, $type)
        {
                if($var == '' || isset($var) == NULL)
                return NULL ;
                $flags = NULL;
                switch($type)
                {
                        case 'url':
                        $filter = FILTER_SANITIZE_URL;
                        break;

                        case 'int':
                        $filter = FILTER_SANITIZE_NUMBER_INT;
                        break;

                        case 'pass':
                        $filter = FILTER_UNSAFE_RAW;
                        $flags = null;
                        break ;

                        case 'IP':
                        $filter = FILTER_VALIDATE_IP;
                        $flags = FILTER_FLAG_IPV4;
                        break;

                        case 'email':
                        $var = substr($var, 0, 254);
                        $filter = FILTER_SANITIZE_EMAIL;
                        break;

                        case 'string':
                        $filter = FILTER_SANITIZE_STRING;
                        break;

                        case 'boolean':
                        $filter = FILTER_VALIDATE_BOOLEAN;
                        break;

                        default:
                        $filter = FILTER_SANITIZE_STRING;
                        $flags = FILTER_FLAG_NO_ENCODE_QUOTES;
                        break;
                }

        $output = filter_var($var, $filter, $flags);
        if($type != 'pass'){
                $output = str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a","=","`"), array('', '', '', '', '', '', '','','','',''), $output);
        }else{
                $output = str_replace(array('\\', "\0", "\n", "\r", "\x1a","`"), array('', '', '', '', '', '', '','','','',''), $output);
        }
                return($output);
        }


    public function ovipanel_CurlExecute($input_tag, $request)
   {
    $ip = runtime_sanatizeItem::sanatizeItem(trim($this->configuration->ip),"IP");
    $api_key = runtime_sanatizeItem:: sanatizeItem(trim($this->configuration->api_key),"string");
    $serverusername = runtime_sanatizeItem::sanatizeItem(trim($this->configuration->adminusername),"string");
    $serverpassword = runtime_sanatizeItem::sanatizeItem(trim($this->configuration->adminpassword),"pass");

    $input_xml='<?xml version="1.0" encoding="UTF-8" ?>'.
           '<xmws>'.
           '<apikey>'.$api_key.'</apikey>'.
           '<request>'.$request.'</request>'.
           '<authuser>'.$serverusername.'</authuser>'.
           '<authpass>'.$serverpassword.'</authpass>'.
           '<content>' . $input_tag . '</content>'.
           '</xmws>';

    $url="http://$ip:2086/bin/oviapi.php?module=manage_clients";
 //   ovipanel_MainErrorLog("Request URL : $url");
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

  //  ovipanel_MainErrorLog("Request $request");
  //  ovipanel_MainErrorLog("input_xml : $input_xml");
 //   ovipanel_MainErrorLog("Resposne $data");

    return $data;


}
    public function ovipanel_UsernameExists($username)
  {
    $input_tag='<username>'.$username.'</username>';
    $data = $this->ovipanel_CurlExecute($input_tag, "UsernameExists");
    $data = json_decode($data, true);
    return isset($data['success']) ? $data['success'] : false;
  }

    /**
     * Get a Guzzle HTTP client instance.
     */
    protected function client(): Client
    {/*
        return $this->client ??= new Client([
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
            'base_uri' => sprintf('https://%s/v1/', $this->configuration->hostname),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->configuration->api_token,
            ],
        ]);
    }*/
}
}

