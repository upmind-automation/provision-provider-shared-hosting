<?php

declare (strict_types = 1);

namespace Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC\Api;

use PleskX\Api\Client;
use Nette\Utils\Json;
use Upmind\ProvisionProviders\SharedHosting\PleskOnyxRPC\Errors\ConfigurationError;

class ClientFactory
{
    /**
     * Instantiate a PleskX Client from the Provider's Configuration array.
     * 
     * @param array $configuration Provider Configuration array
     * 
     * @return Client
     */
    public static function make(array $configuration) : Client
    {
        self::checkConfiguration($configuration);

        $hostname = array_get($configuration, 'hostname');
        $port = array_get($configuration, 'port', 8443);
        $protocol = array_get($configuration, 'protocol', 'https');

        $client = new Client($hostname, $port, $protocol);

        $admin_username = array_get($configuration, 'admin_username');
        $admin_password = array_get($configuration, 'admin_password');
        $secret_key = array_get($configuration, 'secret_key');

        if ($secret_key) {
            $client->setSecretKey($secret_key);
        } else {
            $client->setCredentials($admin_username, $admin_password);
        }

        return $client;
    }

    /**
     * Ensure the given Provider Configuration contains the necessary fields to
     * construct a PleskX Client.
     * 
     * @throws MissingConfigurationData
     */
    protected static function checkConfiguration(array $configuration)
    {
        $requiredFields = [
            'hostname',
        ];

        if (array_key_exists('secret_key', $configuration)) {
            $requiredFields[] = 'secret_key';
        } else {
            $requiredFields[] = 'admin_username';
            $requiredFields[] = 'admin_password';
        }

        $missingFields = collect($requiredFields)
            ->diff(array_keys($configuration));

        if ($missingFields->isNotEmpty()) {
            throw ConfigurationError::forMissingData($missingFields->all());
        }
    }
}