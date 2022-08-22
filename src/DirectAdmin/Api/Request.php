<?php

declare (strict_types = 1);

namespace Upmind\ProvisionProviders\SharedHosting\DirectAdmin\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface as Psr7Response;
use Upmind\ProvisionBase\Provider\Helper\Api\Request as ApiRequest;

class Request extends ApiRequest
{
    /**
     * @param ClientInterface $client Guzzle client
     * @param string $method HTTP verb
     * @param string $uri DirectAdmin API function
     * @param array $params Function parameters keyed by name
     */
    public function __construct(
        ClientInterface $client, 
        string $method = 'GET', 
        string $uri = '', 
        array $params = [],
        array $requestOptions = []
    ) {
        $options = $requestOptions;

        if ($params) {
            $options['query'] = array_merge(
                $client->getConfig('query'),
                $params
            );
        }

        $this->promise = $client->requestAsync(
            strtoupper($method), strtolower($uri), $options
        )->then(function (Psr7Response $response) {
            return $this->response = new Response($response);
        });
    }
}