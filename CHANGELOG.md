# Changelog

All notable changes to the package will be documented in this file.

## v5.8.2 - 2023-04-07

- Update Enhance provider, add better tolerance for website domain not found

## v5.8.1 - 2023-03-15

- Update Demo provider AccountInfo

## 5.8 - 2023-03-15

- Add Example provider as a basic template for creating new providers
- Add stateless Demo provider which returns fake data

## v5.7 - 2023-02-24

- Add SoftwareInstallation datasets
- Implement Wordpress installation via softaculous as a WHMv1 configuration variable
- Implement softaculous installation SSO in WHMv1 getLoginUrl()
- Add debug logging to WHMv1 provider

## v5.6.6 - 2023-02-13

- Fix implementation of `remove_www` in v5.6.5

## v5.6.5 - 2023-02-13

- Update Enhance configuration add `remove_www` setting to optionally remove www.
  subdomain from hostnames during create()

## v5.6.4 - 2023-02-13

- Update WHMv1 Provider asyncApiCall() exception handling to catch and re-throw
  any Guzzle TransferException (incl. connection errors/timeouts)

## v5.6.3 - 2023-01-25

- Update `upmind/enhance-sdk` to v9

## v5.6.2 - 2023-01-17

- Update Enhance handleException() improve 404 error messages

## v5.6.1 - 2023-01-17

- Handle + debug null $result in Enhance findWebsite()

## v5.6.0 - 2023-01-10

- Make domain optional when creating new Enhance subscriptions

## v5.5.1 - 2023-01-10

- Fix Enhance findWebsite() domain search logic where subscription contained
  subdomains of the searched root domain

## v5.5.0 - 2023-01-09

- Add optional domain param to GetLoginUrlParams
- Utilize domain param for Enhance Wordpress login

## v5.4.4 - 2023-01-03

- Fix Enhance provider findWebsite() to exclude deleted websites

## v5.4.3 - 2022-12-16

- Fix Enhance getLoginUrl() where websiteId is not known
- Update Enhance findWebsite() to load whole website object including IPs

## v5.4.2 - 2022-12-14

- Update Enhance provider with QoL improvements for manually importing accounts
  - Make customer_id optional, falling back to finding customer by username (owner
    email address)
  - Update getInfo() to find subscription by domain if subscription id is not passed

## v5.4.1 - 2022-12-05

- Fix Enhance getLoginUrl() for configurations with null sso_destination

## v5.4.0 - 2022-12-05

- Update to Enhance SDK v8.1.0
- Implement Enhance CP SSO for panels running v8.2+

## v5.3.0 - 2022-11-23

- Add optional domain parameter for getInfo(), changePackage(), suspend()
  unsuspend() & terminate() functions
- Update Plesk provider to make use of customer_id and subscription_id with fall-
  back to domain for cases where username is re-used across subscriptions, and
  subscription_id is not known (non-reseller)
- Improve Plesk error handling / messages

## v5.2.5 - 2022-11-09

- Fix Enhance getWordpressLoginUrl() return value

## v5.2.4 - 2022-11-09

- Update Enhance sso_destination configuration field options to be lowercase (fixes
  an issue with consumers using HtmlField which doesnt support uppercase chars in
  option values)

## v5.2.3 - 2022-11-09

- Update to Enhance SDK v8.0.0
- Append Enhance CP status + version meta-data to error result data
- Add Enhance configuration value for getLoginUrl() to return either Enhance or
  Wordpress SSO urls

## v5.2.2 - 2022-11-08

- Improve handling of cURL errors (e.g., SSL issues) in Enhance provider
- Add option to ignore Enhance SSL errors

## v5.2.1 - 2022-11-07

- Fix several Enhance provider type errors

## v5.2.0 - 2022-10-21

- Require upmind/provision-provider-base ^3.4
- Update Enhance Api config to use https instead of http
- Update Enhance createCustomer() return debug error if customer id is empty
- Update Enhance handleException() append response message to result error message

## v5.1.1 - 2022-10-20

- Make CreateParams `customer_name` optional

## v5.1.0 - 2022-10-20

- Require upmind/provision-provider-base ^3.3
- Fix Enhance provider generateRandomPassword() so a valid password is always
  returned

## v5.0.0 - 2022-10-20

#### New
- Implement Enhance provider using OpenAPI sdk
- Change param and result sets to include optional subscription_id

#### Breaking
- Require customer_name in param dataset CreateParams
- Make expires optional in result dataset LoginUrl

## v4.3.1 - 2022-10-18

- Update TwentyI Provider to not implement LogsDebugData twice

## v4.3.0 - 2022-10-14

- Update to `upmind/provision-provider-base` v3.0.0
- Add icon to Category AboutData
- Add logo_url to Providers' AboutData

## v4.2.6 - 2022-09-23

- Update WHMv1\Provider to automatically prepend reseller username to package names
  when needed

## v4.2.5 - 2022-09-23

- Fix WHMv1\Provider::processResponse()

## v4.2.4 - 2022-09-23

- Improve debug data for WHMv1 error results when API response contains no parsable
  result data

## v4.2.3 - 2022-09-12

- Fix PleskOnyxRPC provider

## v4.2.2 - 2022-08-26

- Treat WHMv1 HTTP 524 responses as regular request timeouts [#11](https://github.com/upmind-automation/provision-provider-shared-hosting/issues/11)

## v4.2.1 - 2022-08-25

- Increase WHMv1 createacct timeout to 240
- Attempt to recover from create/suspend/unsuspend timeouts by checking the status
  of the account and returning success if it appears the operation has succeeded
  but is still in progress [#11](https://github.com/upmind-automation/provision-provider-shared-hosting/issues/11)

## v4.2 - 2022-07-12

- Add support for optional hosting platform customer_id
- Update 20i provider; add API call debug logging, always find or create an
  explicit stack user when creating new accounts, and implement support for customer_id

## v4.1 - 2022-06-07

Update cPanel (WHMv1) provider; remove confusing configuration values, simplify
HTTP client creation, and increase request timeout for suspend/unsuspend/terminate
functions for reseller accounts
## v4.0.1 - 2022-05-30

Fix WHMv1 Api ClientFactory compatibility with base ClientFactory

## v4.0 - 2022-04-29

Initial public release
