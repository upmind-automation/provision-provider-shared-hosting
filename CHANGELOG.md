# Changelog

All notable changes to the package will be documented in this file.

## v4.2 - 2022-07-12

- Add support for optional hosting platform customer_id
- Update 20i provider; add API call debug logging and implement support for
  customer_id

## v4.1 - 2022-06-07

Update cPanel (WHMv1) provider; remove confusing configuration values, simplify
HTTP client creation, and increase request timeout for suspend/unsuspend/terminate
functions for reseller accounts
## v4.0.1 - 2022-05-30

Fix WHMv1 Api ClientFactory compatibility with base ClientFactory

## v4.0 - 2022-04-29

Initial public release