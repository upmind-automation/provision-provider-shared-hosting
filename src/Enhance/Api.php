<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Enhance;

use GuzzleHttp\Client;
use Upmind\EnhanceSdk\Api\AppsApi;
use Upmind\EnhanceSdk\Api\BackupsApi;
use Upmind\EnhanceSdk\Api\BrandingApi;
use Upmind\EnhanceSdk\Api\CustomersApi;
use Upmind\EnhanceSdk\Api\DnsApi;
use Upmind\EnhanceSdk\Api\DomainApi;
use Upmind\EnhanceSdk\Api\DomainsApi;
use Upmind\EnhanceSdk\Api\EmailClientApi;
use Upmind\EnhanceSdk\Api\EmailsApi;
use Upmind\EnhanceSdk\Api\FtpApi;
use Upmind\EnhanceSdk\Api\InstallApi;
use Upmind\EnhanceSdk\Api\InvitesApi;
use Upmind\EnhanceSdk\Api\LetsencryptApi;
use Upmind\EnhanceSdk\Api\LicenceApi;
use Upmind\EnhanceSdk\Api\LoginsApi;
use Upmind\EnhanceSdk\Api\MembersApi;
use Upmind\EnhanceSdk\Api\MetricsApi;
use Upmind\EnhanceSdk\Api\MigrationsApi;
use Upmind\EnhanceSdk\Api\MysqlApi;
use Upmind\EnhanceSdk\Api\OrgsApi;
use Upmind\EnhanceSdk\Api\OwnerApi;
use Upmind\EnhanceSdk\Api\PlansApi;
use Upmind\EnhanceSdk\Api\ServersApi;
use Upmind\EnhanceSdk\Api\SettingsApi;
use Upmind\EnhanceSdk\Api\SslApi;
use Upmind\EnhanceSdk\Api\SubscriptionsApi;
use Upmind\EnhanceSdk\Api\TagsApi;
use Upmind\EnhanceSdk\Api\WebsiteApi;
use Upmind\EnhanceSdk\Api\WebsitesApi;
use Upmind\EnhanceSdk\Api\WordpressApi;
use Upmind\EnhanceSdk\Configuration as ApiConfiguration;
use Upmind\ProvisionProviders\SharedHosting\Enhance\Data\Configuration;

class Api
{
    /**
     * @var \Upmind\EnhanceSdk\Configuration
     */
    protected $api_config;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var object[]
     */
    protected $services = [];

    public function __construct(Configuration $configuration)
    {
        $this->client = new Client();
        $this->api_config = (new ApiConfiguration())
            ->setHost(sprintf('http://%s/api', $configuration->hostname))
            ->setAccessToken($configuration->access_token);
    }

    public function setClient(Client $client): void
    {
        $this->client = $client;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function apps(): AppsApi
    {
        return new AppsApi($this->client, $this->api_config);
    }

    public function backups(): BackupsApi
    {
        return new BackupsApi($this->client, $this->api_config);
    }

    public function branding(): BrandingApi
    {
        return new BrandingApi($this->client, $this->api_config);
    }

    public function customers(): CustomersApi
    {
        return new CustomersApi($this->client, $this->api_config);
    }

    public function dns(): DnsApi
    {
        return new DnsApi($this->client, $this->api_config);
    }

    public function domain(): DomainApi
    {
        return new DomainApi($this->client, $this->api_config);
    }

    public function domains(): DomainsApi
    {
        return new DomainsApi($this->client, $this->api_config);
    }

    public function emailClient(): EmailClientApi
    {
        return new EmailClientApi($this->client, $this->api_config);
    }

    public function emails(): EmailsApi
    {
        return new EmailsApi($this->client, $this->api_config);
    }

    public function ftp(): FtpApi
    {
        return new FtpApi($this->client, $this->api_config);
    }

    public function install(): InstallApi
    {
        return new InstallApi($this->client, $this->api_config);
    }

    public function invites(): InvitesApi
    {
        return new InvitesApi($this->client, $this->api_config);
    }

    public function letsencrypt(): LetsencryptApi
    {
        return new LetsencryptApi($this->client, $this->api_config);
    }

    public function licence(): LicenceApi
    {
        return new LicenceApi($this->client, $this->api_config);
    }

    public function logins(): LoginsApi
    {
        return new LoginsApi($this->client, $this->api_config);
    }

    public function members(): MembersApi
    {
        return new MembersApi($this->client, $this->api_config);
    }

    public function metrics(): MetricsApi
    {
        return new MetricsApi($this->client, $this->api_config);
    }

    public function migrations(): MigrationsApi
    {
        return new MigrationsApi($this->client, $this->api_config);
    }

    public function mysql(): MysqlApi
    {
        return new MysqlApi($this->client, $this->api_config);
    }

    public function orgs(): OrgsApi
    {
        return new OrgsApi($this->client, $this->api_config);
    }

    public function owner(): OwnerApi
    {
        return new OwnerApi($this->client, $this->api_config);
    }

    public function plans(): PlansApi
    {
        return new PlansApi($this->client, $this->api_config);
    }

    public function servers(): ServersApi
    {
        return new ServersApi($this->client, $this->api_config);
    }

    public function settings(): SettingsApi
    {
        return new SettingsApi($this->client, $this->api_config);
    }

    public function ssl(): SslApi
    {
        return new SslApi($this->client, $this->api_config);
    }

    public function subscriptions(): SubscriptionsApi
    {
        return new SubscriptionsApi($this->client, $this->api_config);
    }

    public function tags(): TagsApi
    {
        return new TagsApi($this->client, $this->api_config);
    }

    public function website(): WebsiteApi
    {
        return new WebsiteApi($this->client, $this->api_config);
    }

    public function websites(): WebsitesApi
    {
        return new WebsitesApi($this->client, $this->api_config);
    }

    public function wordpress(): WordpressApi
    {
        return new WordpressApi($this->client, $this->api_config);
    }
}
