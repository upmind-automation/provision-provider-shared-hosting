<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\TwentyI;

use ErrorException;
use Illuminate\Support\Arr;
use libphonenumber\PhoneNumberUtil;
use Psr\Log\LoggerInterface;
use Throwable;
use TwentyI\API\CurlException;
use TwentyI\API\HTTPException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionProviders\SharedHosting\Data\CustomerAddressParams;
use Upmind\ProvisionProviders\SharedHosting\TwentyI\Api\Authentication;
use Upmind\ProvisionProviders\SharedHosting\TwentyI\Api\Services;

/**
 * 20i StackCP reseller API helper.
 */
class Api
{
    /**
     * @var Authentication $auth
     */
    protected $auth;

    /**
     * @var Services $services
     */
    protected $services;

    /**
     * @param string $generalApiKey Bearer token E.g., "7a528cf6921cc713"
     */
    public function __construct(string $generalApiKey, ?LoggerInterface $logger = null)
    {
        $this->auth = new Authentication($generalApiKey);
        $this->auth->setLogger($logger);
        $this->services = new Services($generalApiKey);
        $this->services->setLogger($logger);
    }

    /**
     * Attempt to find a stack user reference for the given email address.
     *
     * @param string $email Customer email address
     * @param bool $orFail Whether to throw ProvisionFunctionError if stack user is not found
     *
     * @return string|null Stack user reference, if found
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If list users request fails
     * @throws \Throwable
     */
    public function searchForStackUser(string $email, bool $orFail = false): ?string
    {
        try {
            $user = $this->services->postWithFields('/reseller/*/explicitStackUserCheck', [
                'email' => $email,
            ])->result ?? null;

            if (!$user && $orFail) {
                throw (new ProvisionFunctionError('Stack user not found'))->withData(['email' => $email]);
            }

            return $user;
        } catch (\Throwable $e) {
            $this->handleException($e, 'Could not list stack users', ['email' => $email]);
        }
    }

    /**
     * Create a new stack user, returning the new stack user reference.
     *
     * @param string $email Customer email address
     * @param string|null $customerName Customer name
     * @param string|null $customerRef Customer reference
     * @param string|null $address1 Customer address line 1
     * @param string|null $city Customer city
     * @param string|null $postcode Customer postcode
     * @param string|null $countryCode Customer country code
     *
     * @return string Stack user reference E.g., stack-user:12345
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If create request fails
     * @throws \Throwable
     */
    public function createStackUser(
        string $email,
        ?string $customerName,
        ?string $customerRef,
        ?string $address1,
        ?string $city,
        ?string $postcode,
        ?string $countryCode,
        ?string $internationalPhone
    ): string {
        try {
            $internationalPhone = $this->internationalPhoneToEpp($internationalPhone);

            $createResponse = $this->services->postWithFields('/reseller/*/susers', [
                "newUser" => [
                    "email" => $email,
                ],
            ]);

            if (empty($createResponse->result->ref)) {
                throw HTTPException::create('/reseller/*/susers', $createResponse, 409);
            }

            try {
                // attempt to update stack user with their full details
                $this->updateStackUser(
                    $createResponse->result->ref,
                    $email,
                    $customerName,
                    $customerRef,
                    $address1,
                    $city,
                    $postcode,
                    $countryCode,
                    $internationalPhone
                );
            } finally {
                // ignore any errors updating the stack user
                return $createResponse->result->ref;
            }
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not create new stack user', ['email' => $email]);
        }
    }

    /**
     * Update an existing stack user.
     */
    public function updateStackUser(
        string $userRef,
        string $email,
        ?string $customerName,
        ?string $customerRef,
        ?string $address1,
        ?string $city,
        ?string $postcode,
        ?string $countryCode,
        ?string $internationalPhone
    ): void {
        try {
            $this->services->postWithFields("/reseller/*/susers", [
                "contact" => [
                    $userRef => [
                        "person_name" => $customerName,
                        "email" => $email,
                        // "sendNewStackUserEmail" => true,
                        // "company_name" => '',
                        "address" => $address1,
                        "city" => $city,
                        "sp" => $city,
                        "pc" => $postcode,
                        "cc" => $countryCode,
                        "voice" => $internationalPhone,
                        // "notes" => $domain,
                        "billing_ref" => $customerRef,
                        "nominet_contact_type" => 'IND',
                    ],
                ]
            ]);
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not update stack user', ['stack_user' => $userRef]);
        }
    }

    /**
     * Obtain a signed login url for the given stack user and domain name.
     *
     * @param string $stackUser Stack user reference E.g., stack-user:1235
     * @param string $domain Domain name
     *
     * @return array Login URL and TTL e.g., ['foo.com/login', 86400]
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If auth request fails
     * @throws \Throwable
     */
    public function getLoginUrl(string $stackUser, ?string $domain): array
    {
        try {
            /** @var object $tokenInfo */
            $tokenInfo = $this->auth->controlPanelTokenForUser($stackUser);
            $loginUrl = $this->services->singleSignOn($tokenInfo->access_token, $domain);

            return [$loginUrl, $tokenInfo->expires_in];
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not obtain login URL', [
                'stack_user' => $stackUser,
                'domain' => $domain,
            ]);
        }
    }

    /**
     * Update a stack user's password.
     *
     * @param string $stackUser Stack user reference E.g., stack-user:1235
     * @param string $password New password
     *
     * @return void
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If change password request fails
     * @throws \Throwable
     */
    public function changeStackUserPassword(string $stackUser, string $password): void
    {
        try {
            $changeResponse = $this->services->postWithFields('/reseller/*/susers', [
                "users" => [
                    $stackUser => [
                        "password" => $password,
                    ]
                ],
            ]);
            if (empty($changeResponse->result)) {
                throw HTTPException::create('/reseller/*/susers', $changeResponse, 409);
            }
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not change stack user password', ['stack_user' => $stackUser]);
        }
    }

    /**
     * Delete a stack user.
     *
     * @param string $stackUser Stack user reference E.g., stack-user:1235
     *
     * @return void
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If delete request fails
     * @throws \Throwable
     */
    public function deleteStackUser(string $stackUser): void
    {
        try {
            $deleteResponse = $this->services->postWithFields('/reseller/*/susers', [
                "users" => [
                    $stackUser => [
                        "delete" => true,
                    ]
                ],
            ]);
            if (empty($deleteResponse->result)) {
                throw HTTPException::create('/reseller/*/susers', $deleteResponse, 409);
            }
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not delete stack user', ['stack_user' => $stackUser]);
        }
    }

    /**
     * Returns info about a plan (package type).
     *
     * @param int|string $planId Plan id (package type)
     *
     * @return object Plan (package type) info
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If plan cannot be found
     * @throws \Throwable
     */
    public function getPlanInfo($planId): object
    {
        try {
            // "list all + filter results" appears to be the only way to find a plan which is also horrendous !
            $allPlans = $this->services->getWithFields('/reseller/*/packageTypes');
            $plan = Arr::first($allPlans, function ($plan) use ($planId) {
                return $plan->id === (int)$planId;
            });

            if (!$plan) {
                throw (new ProvisionFunctionError('Plan (package type) not found'))->withData(['plan_id' => $planId]);
            }

            return $plan;
        } catch (\Throwable $e) {
            $this->handleException($e, 'Could not list plans (package types)', ['plan_id' => $planId]);
        }
    }

    /**
     * Create a new hosting package, returning the new hosting account id.
     *
     * @link https://jsapi.apiary.io/apis/20i/reference/reseller/package-add/add-a-hosting-package.html
     *
     * @param int|string $planId Stack cp plan id / reference
     * @param string $domain New hosting package domain name
     * @param string $locationId Location/data-centre identifier
     * @param string|null $stackUser Stack user reference E.g., stack-user:1235
     *
     * @return int New hosting package id
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If create request fails
     * @throws \Throwable
     */
    public function createPackage($planId, string $domain, ?string $locationId = null, ?string $stackUser = null): int
    {
        try {
            $params = [
                'type' => $planId,
                'domain_name' => $domain,
            ];

            if (isset($locationId)) {
                $params['location'] = $locationId;
            }

            if (isset($stackUser)) {
                $params['stackUser'] = $stackUser;
            }

            $createResponse = $this->services->postWithFields('/reseller/*/addWeb', $params);

            if (empty($createResponse->result)) {
                throw HTTPException::create('/reseller/*/addWeb', $createResponse, 409);
            }

            return $createResponse->result;
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not create new hosting package', [
                'plan_id' => $planId,
                'domain' => $domain,
                'location' => $locationId,
                'stack_user' => $stackUser,
            ]);
        }
    }

    /**
     * List available locations (data centres).
     *
     * @return array<string,string> Map of location ids to location names E.g. ['uk' => 'United Kingdom']
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If create request fails
     * @throws \Throwable
     */
    public function listDataCentreLocations()
    {
        try {
            return $this->services->getWithFields('/reseller/*/availableDcLocations');
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not list data centre locations');
        }
    }

    /**
     * Returns raw hosting package info for the given hosting id.
     *
     * @link https://jsapi.apiary.io/apis/20i/reference/packages/package/retrieve-package-information.html
     *
     * @param int|string $hostingId Hosting package id
     *
     * @return object Raw api response data
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If get info request fails
     * @throws \Throwable
     */
    public function getPackageInfo($hostingId): object
    {
        try {
            return $this->services->getWithFields(sprintf('/package/%s', trim((string)$hostingId)));
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not get hosting package info', [
                'hosting_id' => $hostingId,
            ]);
        }
    }

    /**
     * Returns hosting package limits for the given hosting id.
     *
     * @link https://jsapi.apiary.io/apis/20i/reference/packages/limits-web/retrieve-limits-for-a-web.html
     *
     * @param int|string $hostingId Hosting package id
     *
     * @return object Raw api response data
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If get info request fails
     * @throws \Throwable
     */
    public function getPackageLimits($hostingId): object
    {
        try {
            return $this->services->getWithFields(sprintf('/package/%s/web/limits', $hostingId));
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not get hosting package limits', [
                'hosting_id' => $hostingId,
            ]);
        }
    }

    /**
     * Returns hosting package usage stats for the given hosting id.
     *
     * @link https://jsapi.apiary.io/apis/20i/reference/packages/web-usage-stats/retreive-web-stats.html
     *
     * @param int|string $hostingId Hosting package id
     *
     * @return object Raw api response data
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If get info request fails
     * @throws \Throwable
     */
    public function getPackageUsage($hostingId): object
    {
        try {
            return $this->services->getWithFields(sprintf('/package/%s/web/usage', $hostingId));
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not get hosting package usage', [
                'hosting_id' => $hostingId,
            ]);
        }
    }

    /**
     * Update the plan of an existing hosting package.
     *
     * @link https://jsapi.apiary.io/apis/20i/reference/reseller/package-update/update/delete-a-package.html
     *
     * @param int|string $hostingId Hosting package id
     * @param int|string $planId New plan (package type) id
     *
     * @return void
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If change plan request fails
     * @throws \Throwable
     */
    public function changePackagePlan($hostingId, $planId): void
    {
        try {
            $changeResponse = $this->services->postWithFields('/reseller/*/updatePackage', [
                "id" => [
                    $hostingId,
                ],
                "packageBundleTypes" => [
                    $hostingId => $planId,
                ]
            ]);
            if (empty($changeResponse->result)) {
                throw HTTPException::create('/reseller/*/updatePackage', $changeResponse, 409);
            }
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not change hosting package to the given plan', [
                'hosting_id' => $hostingId,
                'plan_id' => $planId,
            ]);
        }
    }

    /**
     * Disable/suspend a hosting package.
     *
     * @param int|string $hostingId Hosting package id
     *
     * @return void
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If disable request fails
     * @throws \Throwable
     */
    public function disablePackage($hostingId): void
    {
        try {
            $disableResponse = $this->services->postWithFields(sprintf('/package/%s/userStatus', $hostingId), [
                'subservices' => [
                    'default' => false,
                ],
            ]);
            if (empty($disableResponse->result)) {
                throw HTTPException::create('/package/%s/userStatus', $disableResponse, 409);
            }
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not suspend package', [
                'hosting_id' => $hostingId,
            ]);
        }
    }

    /**
     * Enable/unsuspend a hosting package.
     *
     * @param int|string $hostingId Hosting package id
     *
     * @return void
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If enable request fails
     * @throws \Throwable
     */
    public function enablePackage($hostingId): void
    {
        try {
            $enableResponse = $this->services->postWithFields(sprintf('/package/%s/userStatus', $hostingId), [
                'subservices' => [
                    'default' => true,
                ],
            ]);
            if (empty($enableResponse->result)) {
                throw HTTPException::create('/package/%s/userStatus', $enableResponse, 409);
            }
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not unsuspend package', [
                'hosting_id' => $hostingId,
            ]);
        }
    }

    /**
     * Permanently delete a hosting package (account).
     *
     * @param int|string $hostingId Hosting package id
     *
     * @return void
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError If delete request fails
     * @throws \Throwable
     */
    public function terminatePackage($hostingId): void
    {
        try {
            $deleteResponse = $this->services->postWithFields('/reseller/*/deleteWeb', [
                "delete-id" => [$hostingId],
            ]);
            if (empty($deleteResponse->result)) {
                throw HTTPException::create('/reseller/*/deleteWeb', $deleteResponse, 409);
            }
        } catch (Throwable $e) {
            $this->handleException($e, 'Could not delete hosting account', [
                'hosting_id' => $hostingId,
            ]);
        }
    }

    /**
     * Wrap StackCP reseller api exceptions in a ProvisionFunctionError with the
     * given message and data, if appropriate. Otherwise re-throws original error.
     *
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(Throwable $e, ?string $errorMessage = null, array $data = [], array $debug = [])
    {
        $errorMessage = $errorMessage ?? 'StackCP API request failed';

        if ($this->exceptionIs401($e)) {
            $errorMessage = 'API authentication error';
        }

        if ($this->exceptionIs404($e)) {
            $errorMessage .= ' (not found)';
        }

        if ($this->exceptionIs409($e)) {
            $errorMessage .= ' (conflict)';
        }

        if ($this->exceptionIsTimeout($e)) {
            $errorMessage .= ' (request timed out)';
        }

        if ($e instanceof HTTPException) {
            if (!empty($e->decodedBody->error->message)) {
                $errorMessage .= ': ' . $e->decodedBody->error->message;
            }

            $data['request_url'] = $e->fullURL;
            $data['response_data'] = $e->decodedBody;
        }

        if ($e instanceof ProvisionFunctionError) {
            // merge any additional error data / debug data
            $data = array_merge($e->getData(), $data);
            $debug = array_merge($e->getDebug(), $debug);

            $e = $e->withData($data)
                ->withDebug($debug);
        }

        if ($this->shouldWrapException($e)) {
            throw (new ProvisionFunctionError($errorMessage, 0, $e))
                ->withData($data)
                ->withDebug($debug);
        }

        throw $e;
    }

    /**
     * Determine whether the given exception should be wrapped in a
     * ProvisionFunctionError.
     */
    protected function shouldWrapException(Throwable $e): bool
    {
        return $e instanceof HTTPException
            || $this->exceptionIs401($e)
            || $this->exceptionIs404($e)
            || $this->exceptionIs409($e)
            || $this->exceptionIsTimeout($e);
    }

    /**
     * Determine whether the given exception was thrown due to a 401 response
     * from the stack cp api.
     */
    protected function exceptionIs401(Throwable $e): bool
    {
        return $e instanceof HTTPException
            && preg_match('/(^|[^\d])401([^\d]|$)/', $e->getMessage());
    }

    /**
     * Determine whether the given exception was thrown due to a 404 response
     * from the stack cp api.
     */
    protected function exceptionIs404(Throwable $e): bool
    {
        return $e instanceof ErrorException
            && preg_match('/(^|[^\d])404([^\d]|$)/', $e->getMessage());
    }

    /**
     * Determine whether the given exception was thrown due to a 409 response
     * from the stack cp api.
     */
    protected function exceptionIs409(Throwable $e): bool
    {
        return $e instanceof HTTPException
            && preg_match('/(^|[^\d])409([^\d]|$)/', $e->getMessage());
    }

    /**
     * Determine whether the given exception was thrown due to a request timeout.
     */
    protected function exceptionIsTimeout(Throwable $e): bool
    {
        return $e instanceof CurlException
            && preg_match('/(^|[^\w])timed out([^\w]|$)/i', $e->getMessage());
    }

    /**
     * Convert a phone from "international format" (beginning with `+` and intl
     * dialling code) to "EPP format" described in RFC5733. To validate a phone
     * number is in valid international format, you can use the provided
     * `international_phone` rule.
     *
     * @link https://tools.ietf.org/html/rfc5733#section-2.5
     *
     * @param string|null $number Phone number in "international format" E.g., +447515878251
     *
     * @return string|null Phone number in "EPP format" E.g., +44.7515878251
     *
     * @throws \libphonenumber\NumberParseException If not a valid international phone number
     */
    protected function internationalPhoneToEpp(?string $number): ?string
    {
        if (empty($number)) {
            return null;
        }

        $phone = PhoneNumberUtil::getInstance()->parse($number, null);
        $diallingCode = $phone->getCountryCode();
        $nationalNumber = $phone->getNationalNumber();

        return sprintf('+%s.%s', $diallingCode, $nationalNumber);
    }
}
