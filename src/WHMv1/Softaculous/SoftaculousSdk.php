<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\WHMv1\Softaculous;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Str;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionProviders\SharedHosting\Data\SoftwareInstallation;
use Upmind\ProvisionProviders\SharedHosting\WHMv1\Data\WHMv1Credentials;

/**
 * Softaculous SDK inspired by the official Softaculous_SDK class.
 *
 * @link http://www.softaculous.com/docs/SDK
 */
class SoftaculousSdk
{
    protected string $username;
    protected string $password;
    protected WHMv1Credentials $configuration;
    protected Client $client;

    /**
     * A map of software names to Softaculous script ids.
     *
     * @var int[]
     */
    public const SOFTWARE_IDS = [
        'wordpress' => 26,
    ];

    public function __construct(
        string $cpanelUser,
        string $cpanelPassword,
        WHMv1Credentials $configuration,
        ?Client $client = null
    ) {
        $this->username = $cpanelUser;
        $this->password = $cpanelPassword;
        $this->configuration = $configuration;
        $this->client = $client ?? new Client();
    }

    /**
     * Get the redirect URI to auto-login into the installed software.
     *
     * @param string $installationId
     */
    public static function getInstallationLoginUri($installationId): string
    {
        return sprintf('/frontend/jupiter/softaculous/index.live.php?%s', http_build_query([
            'act' => 'sign_on',
            'insid' => $installationId,
            'autoid' => Helper::generateStrictPassword(32, false, true, false),
        ]));
    }

    public function installWordpress(
        string $domain,
        string $adminEmail,
        ?string $adminUsername = null,
        ?string $adminPassword = null
    ): SoftwareInstallation {
        $softwareId = self::SOFTWARE_IDS['wordpress'];

        $settings = $this->install($softwareId, [
            'softdomain' => $domain,
            'admin_username' => $adminUsername ??= 'admin_' . Helper::generateStrictPassword(5, false, true, false),
            'admin_pass' => $adminPassword ??= Helper::generateStrictPassword(10, true, true, false),
            'admin_email' => $adminEmail,
        ]);

        return new SoftwareInstallation([
            'software_id' => $softwareId,
            'install_id' => $settings['insid'],
            'install_version' => $settings['__settings']['wpver'] ?? null,
            'admin_url' => sprintf('http://%s/%s', $domain, $settings['__settings']['adminurl']),
            'admin_email' => $adminEmail,
            'admin_username' => $adminUsername,
            'admin_password' => $adminPassword,
        ]);
    }

    /**
     * Install software via Softaculous.
     *
     * @param int $scriptId Softaculous script id
     * @param array $data Installation parameters e.g., softdomain, admin_username, admin_pass, etc.
     *
     * @return array New installation settings
     */
    public function install(int $scriptId, array $data = [])
    {
        $action = [
            'act' => 'software',
            'soft' => $scriptId,
        ];

        $data['noemail'] = 1;
        $data['softsubmit'] = 1;

        $result = $this->request($action, $data);

        if (empty($result['done'])) {
            throw (new ProvisionFunctionError(
                sprintf('%s installation failed', array_search($scriptId, self::SOFTWARE_IDS) ?: 'Software')
            ))->withData([
                'script_id' => $scriptId,
                'softaculous_error' => $result['error'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * Make a Softaculous API request and return the result data.
     */
    public function request(array $action, array $post = []): array
    {
        $action['api'] = 'json';
        $action['language'] = 'en';

        $url = sprintf(
            'https://%s:%s@%s:2083/frontend/jupiter/softaculous/index.live.php?%s',
            urlencode($this->username),
            urlencode($this->password),
            $this->configuration->hostname,
            http_build_query($action)
        );

        $method = 'GET';
        $options = [];

        if ($post) {
            $method = 'POST';
            $options = [
                RequestOptions::FORM_PARAMS => $post,
            ];
        }

        $response = $this->client->request($method, $url, $options);

        return json_decode($response->getBody()->__toString(), true) ?? [];
    }
}
