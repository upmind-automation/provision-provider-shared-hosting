<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\SolidCP;

use Psr\Log\LoggerInterface;
use SoapClient;
use stdClass;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\SharedHosting\SolidCP\Data\Configuration;

class Api
{
    /**
     * @var int
     */
    private const DEFAULT_SOCKET_TIMEOUT = 60;

    /**
     * SoapClient instances keyed by service name.
     *
     * @var array<string,SoapClient>
     * */
    private array $clients = [];
    private Configuration $configuration;
    private ?LoggerInterface $logger;
    private int $originalSocketTimeout;

    public function __construct(Configuration $configuration, ?LoggerInterface $logger = null)
    {
        $this->configuration = $configuration;
        $this->logger = $logger;

        $timeout = $this->configuration->socket_timeout ?: static::DEFAULT_SOCKET_TIMEOUT;
        $this->originalSocketTimeout = (int)ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string)$timeout);
    }

    public static function getFriendlyError($code)
    {
        $errors = [
            -100 => 'Username not available, already in use',
            -101 => 'Username not found, invalid username',
            -102 => 'User\'s account has child accounts',
            -300 => 'Hosting package could not be found',
            -301 => 'Hosting package has child hosting spaces',
            -501 => 'The sub-domain belongs to an existing hosting space that does not allow sub-domains to be created',
            -502 => 'The domain or sub-domain exists in another hosting space / user account',
            -511 => 'Preview Domain is enabled, but not configured',
            -601 => 'The website already exists on the target hosting space or server',
            -700 => 'The email domain already exists on the target hosting space or server',
            -1100 => 'User already exists',
        ];

        // Find the error and return it, else a general error will do!
        if (array_key_exists($code, $errors)) {
            return $errors[$code];
        }

        return "An unknown error occurred (Code: {$code}).";
    }

    /**
     * Make a SOAP call and return the result.
     *
     * @param string $service WSDL Service name e.g., Packages, Users
     * @param string $method Method name e.g., GetPackageByName, GetUserByUsername
     * @param mixed[] $params Assoc array of parameters to pass to the WSDL method
     * @param string|null $returnProperty Name of the property to check and return from the result, if any
     *
     * @return stdClass|mixed
     */
    public function execute(string $service, string $method, array $params, ?string $returnProperty = null)
    {
        try {
            $client = $this->getClient($service);

            // Execute the request and process the results
            $result = call_user_func([$client, $method], $params);

            if ($returnProperty && isset($result->$returnProperty)) {
                $returnResult = $result->$returnProperty;

                if (is_numeric($returnResult) && $returnResult < 0) {
                    throw $this->errorResult($this->getFriendlyError($returnResult));
                }

                return $returnResult;
            }

            return $result;
        } catch (\SoapFault $e) {
            $errorData = [
                'fault_code' => $e->faultcode ?? null,
                'fault_name' => $e->faultname ?? null,
                'fault_string' => $e->faultstring ?? null,
                'fault_actor' => $e->faultactor ?? null,
                'header_fault' => $e->headerfault ?? null,
                'detail' => $e->detail ?? null,
            ];

            $errorMessage = 'SOAP Connection Error';

            if ($e->faultcode === 'soap:Server') {
                $errorMessage = 'Unexpected Provider API Error';
            }

            if ($e->faultcode === 'HTTP') {
                $errorMessage = sprintf('API Error: %s', $e->faultstring);

                if ($e->faultstring === 'Error Fetching http headers') {
                    $errorMessage = 'API Socket Timeout';
                }
            }

            if ($e->faultcode === 'Client') {
                $errorMessage = 'Unexpected API Usage Error';
            }

            throw $this->errorResult($errorMessage, $errorData, [], $e);
        } finally {
            if (isset($client)) {
                $this->logLastSoapCall($client, $service, $method);
            }
        }
    }

    private function getClient(string $service): SoapClient
    {
        if (isset($this->clients[$service])) {
            return $this->clients[$service];
        }

        $serverPort = $this->configuration->port ?: 9002;
        $host = "http://{$this->configuration->hostname}:{$serverPort}/es{$service}.asmx?WSDL";

        return $this->clients[$service] = new SoapClient($host, [
            'login' => $this->configuration->username,
            'password' => $this->configuration->password,
            'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
            'cache_wsdl' => true,
            'connection_timeout' => 30,
            'trace' => true,
        ]);
    }

    /**
     * Logs the last request and response if a logger is set.
     */
    private function logLastSoapCall(SoapClient $client, string $service, string $method): void
    {
        if ($this->logger) {
            $this->logger->debug(sprintf(
                "%s::%s\nSOAP REQUEST:\n%s\nSOAP RESPONSE:\n%s",
                $service,
                $method,
                $this->formatSoapLog($client->__getLastRequest()),
                $this->formatSoapLog($client->__getLastResponse())
            ));
        }
    }

    /**
     * Format the given log message, masking the username and password.
     *
     * @param string|null $message
     */
    private function formatSoapLog($message): string
    {
        return str_replace(
            array_map(
                fn ($string) => htmlspecialchars($string, ENT_XML1, 'UTF-8'),
                [$this->configuration->username, $this->configuration->password]
            ),
            ['[USERNAME]', '[PASSWORD]'],
            trim(strval($message))
        );
    }

    /**
     * Throw an error to fail this provision function execution.
     *
     * @param string $message A user-friendly error message
     * @param mixed[] $data JSONable data to be passed back to the System Client
     * @param mixed[] $debugData JSONable debug data
     * @param Throwable $previous Previous exception, if any
     *
     * @throws ProvisionFunctionError
     *
     * @return no-return
     */
    private function errorResult($message, $data = [], $debug = [], $previous = null): void
    {
        throw (new ProvisionFunctionError($message, 0, $previous))
            ->withData($data)
            ->withDebug($debug);
    }

    /**
     * Restore original socket timeout.
     */
    public function __destruct()
    {
        ini_set('default_socket_timeout', (string)$this->originalSocketTimeout);
    }
}
