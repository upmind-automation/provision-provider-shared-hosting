<?php

declare (strict_types = 1);

namespace Upmind\ProvisionProviders\SharedHosting\WHMv1\Api;

use GuzzleHttp\ClientInterface;
use Upmind\ProvisionBase\Provider\Helper\Api\ClientFactory as BaseClientFactory;
use Upmind\ProvisionBase\Provider\Helper\Exception\ConfigurationError;

class ClientFactory extends BaseClientFactory
{
    /**
     * @param array $configuration Provider Configuration array
     * 
     * @return array Guzzle Request options
     */
    protected static function getGuzzleOptions(array $configuration): array
    {
        $protocol = array_get($configuration, 'protocol', 'https');
        $hostname = array_get($configuration, 'hostname');
        $port = array_get($configuration, 'port', 2087);
        $whm_username = array_get($configuration, 'whm_username');
        $api_key = array_get($configuration, 'api_key');

        $debugMode = array_get($configuration, 'debug', false);
        $debugStream = $debugMode 
            ? fopen(storage_path("logs/{$hostname}.log"), 'w+') 
            : false;

        return [
            'base_uri' => "{$protocol}://{$hostname}:{$port}/json-api/",
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => "whm {$whm_username}:{$api_key}"
            ],
            'query' => [
                'api.version' => 1
            ],
            'connect_timeout' => 10,
            'timeout' => 60,
            'http_errors' => false,
            'allow_redirects' => false,
            'debug' => $debugStream
        ];
    }

    /**
     * Ensure the given Provider Configuration contains the necessary fields to
     * construct a Guzzle Client.
     * 
     * @throws ConfigurationError
     */
    protected static function checkConfiguration(array $configuration)
    {
        $requiredFields = [
            'hostname', 'whm_username', 'api_key'
        ];

        $missingFields = collect($requiredFields)
            ->diff(array_keys($configuration));

        if ($missingFields->isNotEmpty()) {
            throw ConfigurationError::forMissingData($missingFields->all());
        }
    }
}
