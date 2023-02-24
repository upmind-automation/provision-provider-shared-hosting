<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Information about a software installation.
 *
 * @property-read string|integer $software_id ID or name of the software
 * @property-read string|integer $install_id ID of the software installation
 * @property-read string|null $install_version Version of the installed software
 * @property-read string|null $admin_url
 * @property-read string|null $admin_email
 * @property-read string|null $admin_username
 * @property-read string|null $admin_password
 */
class SoftwareInstallation extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'software_id' => ['required'],
            'install_id' => ['required'],
            'install_version' => ['nullable'],
            'admin_url' => ['required', 'url'],
            'admin_email' => ['nullable', 'email'],
            'admin_username' => ['nullable', 'string'],
            'admin_password' => ['nullable', 'string'],
        ]);
    }

    /**
     * @param string|int $softwareId Software name or id
     *
     * @return self $this
     */
    public function setSoftwareId($softwareId): self
    {
        $this->setValue('software_id', $softwareId);
        return $this;
    }

    /**
     * @param string|int $installId ID of the software installation
     *
     * @return self $this
     */
    public function setInstallId($installId): self
    {
        $this->setValue('install_id', $installId);
        return $this;
    }

    /**
     * @param string|int|null $version Installed software version
     *
     * @return self $this
     */
    public function setInstallVersion($version): self
    {
        $this->setValue('install_version', $version);
        return $this;
    }

    /**
     * @param string|null $adminUrl Installation admin URL
     *
     * @return self $this
     */
    public function setAdminUrl(?string $adminUrl): self
    {
        $this->setValue('admin_url', $adminUrl);
        return $this;
    }

    /**
     * @param string|null $adminEmail Installation admin email
     *
     * @return self $this
     */
    public function setAdminEmail(?string $adminEmail): self
    {
        $this->setValue('admin_email', $adminEmail);
        return $this;
    }

    /**
     * @param string|null $adminUsername Installation admin username
     *
     * @return self $this
     */
    public function setAdminUsername(?string $adminUsername): self
    {
        $this->setValue('admin_username', $adminUsername);
        return $this;
    }

    /**
     * @param string|null $adminPassword Installation admin password
     *
     * @return self $this
     */
    public function setAdminPassword(?string $adminPassword): self
    {
        $this->setValue('admin_password', $adminPassword);
        return $this;
    }
}
