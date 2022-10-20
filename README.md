# Upmind Provision Providers - Shared Hosting

[![Latest Version on Packagist](https://img.shields.io/packagist/v/upmind/provision-provider-shared-hosting.svg?style=flat-square)](https://packagist.org/packages/upmind/provision-provider-shared-hosting)

This provision category contains the common functions used in provisioning flows for accounts/websites on various popular shared hosting platforms.

- [Installation](#installation)
- [Usage](#usage)
  - [Quick-start](#quick-start)
- [Supported Providers](#supported-providers)
- [Functions](#functions)
  - [create()](#create)
  - [getInfo()](#getInfo)
  - [getLoginUrl()](#getLoginUrl)
  - [changePassword()](#changePassword)
  - [changePackage()](#changePackage)
  - [suspend()](#suspend)
  - [unSuspend()](#unSuspend)
  - [terminate()](#terminate)
  - [grantReseller()](#grantReseller)
  - [revokeReseller()](#revokeReseller)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)
- [Upmind](#upmind)

## Installation

```bash
composer require upmind/provision-provider-shared-hosting
```

## Usage

This library makes use of [upmind/provision-provider-base](https://packagist.org/packages/upmind/provision-provider-base) primitives which we suggest you familiarize yourself with by reading the usage section in the README.

### Quick-start

See the below example to create a cPanel account:

```php
<?php

use Illuminate\Support\Facades\App;
use Upmind\ProvisionBase\ProviderFactory;

$configuration = [
    'hostname' => 'whm.example.com',
    'port' => 2087,
    'whm_username' => 'root',
    'api_key' => '{secret key}',
];

$factory = App::make(ProviderFactory::class);
$provider = $factory->create('shared-hosting', 'cpanel', $configuration);

$createParameters = [
    'email' => 'harry@upmind.com',
    'domain' => 'example.com',
    'package_name' => 'starter_package',
];
$function = $provider->makeJob('create', $createParameters);

$createResult = $function->execute();

if ($createResult->isError()) {
    throw new RuntimeException($createResult->getMessage(), 0, $createResult->getException());
}

/** @var \Upmind\ProvisionProviders\SharedHosting\Data\AccountInfo */
$accountInfo = $createResult->getData();

// $accountInfo->username; // username/identifier of the created hosting account
// ...
```

## Supported Providers

The following providers are currently implemented:
  - cPanel/WHM
  - Plesk (Onyx)
  - 20i
  - Enhance

## Functions

### create()

Creates a web hosting account / website and returns the `username` which can be used to identify the account in subsequent requests, and other account information.

### getInfo()

Gets information about a hosting account such as the main domain name, whether or not it is suspended, the hostname of it's server, nameservers etc.

### getLoginUrl()

Obtains a signed URL which a user can be redirected to which automatically logs them into their account.

### changePassword()

Changes the password of the hosting account.

### changePackage()

Update the product/package a hosting account is set to.

### suspend()

Suspends services for a hosting account.

### unSuspend()

Un-suspends services for a hosting account.

### terminate()

Completely delete a hosting account.

### grantReseller()

Grants reseller privileges to a web hosting account, if supported.

### revokeReseller()

Revokes reseller privileges from a web hosting account, if supported.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

 - [Harry Lewis](https://github.com/uphlewis)
 - [Nikolai Arsov](https://github.com/nikiarsov777)
 - [All Contributors](../../contributors)

## License

GNU General Public License version 3 (GPLv3). Please see [License File](LICENSE.md) for more information.

## Upmind

Sell, manage and support web hosting, domain names, ssl certificates, website builders and more with [Upmind.com](https://upmind.com/start) - the ultimate web hosting billing and management solution.
