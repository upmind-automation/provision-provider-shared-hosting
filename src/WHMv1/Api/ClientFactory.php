<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\WHMv1\Api;

use GuzzleHttp\ClientInterface;
use Illuminate\Support\Arr;
use Upmind\ProvisionBase\Provider\Helper\Api\ClientFactory as BaseClientFactory;
use Upmind\ProvisionBase\Provider\Helper\Exception\ConfigurationError;

class ClientFactory extends BaseClientFactory
{
    /**
     * @param array $configuration Provider Configuration array
     *
     * @return array Guzzle Request options
     */
    protected static function getGuzzleOptions(array $configuration, array $requestOptions = []): array
    {
        $protocol = 'https';
        $hostname = Arr::get($configuration, 'hostname');
        $port = 2087;
        $whm_username = Arr::get($configuration, 'whm_username');
        $api_key = Arr::get($configuration, 'api_key');

        return array_merge([
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
        ], $requestOptions);
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
