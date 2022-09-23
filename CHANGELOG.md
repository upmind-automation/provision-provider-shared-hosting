# Changelog

All notable changes to the package will be documented in this file.

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
