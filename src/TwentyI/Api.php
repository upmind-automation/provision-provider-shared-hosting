<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\TwentyI;

use ErrorException;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;
use Throwable;
use TwentyI\API\Authentication;
use TwentyI\API\HTTPException;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
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
        $this->services = new Services($generalApiKey);
        $this->services->setLogger($logger);
    }

    /**
     * Attempt to find a stack user reference for the given email address.
     *
     * @param string $email Customer email address
     * @param bool $orFail Whether to throw ProvisionFunctionError if stack user is not found
     *
     * @throws ProvisionFunctionError If list users request fails
     *
     * @return string|null Stack user reference, if found
     */
    public function searchForStackUser(string $email, bool $orFail = false): ?string
    {
        try {
            // "list all + filter results" appears to be the only way to find a user which is horrendous !
            $allUsers = $this->services->getWithFields('/reseller/*/susers')->users;
            $user = Arr::first($allUsers, function ($user) use ($email) {
                return $user->name === $email;
            });

            if (!$user && $orFail) {
                throw (new ProvisionFunctionError('Stack user not found'))->withData(['email' => $email]);
            }

            return $user ? sprintf('%s:%s', $user->type, $user->id) : null;
        } catch (\Throwable $e) {
            return $this->handleException($e, 'Could not list stack users', ['email' => $email]);
        }
    }

    /**
     * Create a new stack user, returning the new stack user reference.
     *
     * @param string $email Customer email address
     *
     * @throws ProvisionFunctionError If create request fails
     *
     * @return string Stack user reference E.g., stack-user:12345
     */
    public function createStackUser(string $email): string
    {
        try {
            $createResponse = $this->services->postWithFields('/reseller/*/susers', [
                "newUser" => [
                    // "person_name" => $email,
                    "email" => $email,
                    "sendNewStackUserEmail" => false
                    // "company_name" => $domain,
                    // "address" => implode("\n", array_filter([
                    //     $user_info["address1"],
                    //     $user_info["address2"],
                    // ])),
                    // "city" => $user_info["city"],
                    // "sp" => $user_info["state"],
                    // "pc" => $user_info["postcode"],
                    // "cc" => $user_info["country"],
                    // "voice" => @$user_info["phonenumberformatted"] ?: $user_info["phonenumber"],
                    // "notes" => $domain,
                    // "billing_ref" => null,
                    // "nominet_contact_type" => null,
                ],
            ]);

            if (empty($createResponse->result->ref)) {
                throw HTTPException::create('/reseller/*/susers', $createResponse, 409);
            }

            return $createResponse->result->ref;
        } catch (Throwable $e) {
            return $this->handleException($e, 'Could not create new stack user', ['email' => $email]);
        }
    }

    /**
     * Obtain a signed login url for the given stack user and domain name.
     *
     * @param string $stackUser Stack user reference E.g., stack-user:1235
     * @param string $domain Domain name
     *
     * @throws ProvisionFunctionError If auth request fails
     *
     * @return array Login URL and TTL e.g., ['foo.com/login', 86400]
     */
    public function getLoginUrl(string $stackUser, ?string $domain): array
    {
        try {
            /** @var object $tokenInfo */
            $tokenInfo = $this->auth->controlPanelTokenForUser($stackUser);
            $loginUrl = $this->services->singleSignOn($tokenInfo->access_token, $domain);

            return [$loginUrl, $tokenInfo->expires_in];
        } catch (Throwable $e) {
            return $this->handleException($e, 'Could not obtain login URL', [
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
     * @throws ProvisionFunctionError If change password request fails
     *
     * @return void
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
     * @throws ProvisionFunctionError If delete request fails
     *
     * @return void
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
     * @throws ProvisionFunctionError If plan cannot be found
     *
     * @return object Plan (package type) info
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
            return $this->handleException($e, 'Could not list plans (package types)', ['plan_id' => $planId]);
        }
    }

    /**
     * Create a new hosting package, returning the new hosting account id.
     *
     * @link https://jsapi.apiary.io/apis/20i/reference/reseller/package-add/add-a-hosting-package.html
     *
     * @param int|string $planId Stack cp plan id / reference
     * @param string $domain New hosting package domain name
     * @param string|null $stackUser Stack user reference E.g., stack-user:1235
     *
     * @throws ProvisionFunctionError If create request fails
     *
     * @return int New hosting package id
     */
    public function createPackage($planId, string $domain, ?string $stackUser = null): int
    {
        try {
            $params = [
                'type' => $planId,
                'domain_name' => $domain,
            ];

            if (isset($stackUser)) {
                $params['stackUser'] = $stackUser;
            }

            $createResponse = $this->services->postWithFields('/reseller/*/addWeb', $params);

            if (empty($createResponse->result)) {
                throw HTTPException::create('/reseller/*/addWeb', $createResponse, 409);
            }

            return $createResponse->result;
        } catch (Throwable $e) {
            return $this->handleException($e, 'Could not create new hosting package', [
                'plan_id' => $planId,
                'domain' => $domain,
                'stack_user' => $stackUser,
            ]);
        }
    }

    /**
     * Returns raw hosting package info for the given hosting id.
     *
     * @link https://jsapi.apiary.io/apis/20i/reference/packages/package/retrieve-package-information.html
     *
     * @param int|string $hostingId Hosting package id
     *
     * @throws ProvisionFunctionError If get info request fails
     *
     * @return object Raw api response data
     */
    public function getPackageInfo($hostingId): object
    {
        try {
            return $this->services->getWithFields(sprintf('/package/%s', $hostingId));
        } catch (Throwable $e) {
            return $this->handleException($e, 'Could not get hosting package info', [
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
     * @throws ProvisionFunctionError If change plan request fails
     *
     * @return void
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
     * @throws ProvisionFunctionError If disable request fails
     *
     * @return void
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
     * @throws ProvisionFunctionError If enable request fails
     *
     * @return void
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
     * @throws ProvisionFunctionError If delete request fails
     *
     * @return void
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
     * @throws ProvisionFunctionError|Throwable
     *
     * @return no-return
     */
    protected function handleException(Throwable $e, ?string $errorMessage = null, array $data = [], array $debug = [])
    {
        $errorMessage = $errorMessage ?? 'StackCP API request failed';

        if ($this->exceptionIs404($e)) {
            $errorMessage .= ' (not found)';
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
            || $this->exceptionIs404($e);
    }

    /**
     * Determine whether the given exception was thrown due to a 404 response
     * from the stack cp api
     */
    protected function exceptionIs404(Throwable $e): bool
    {
        return $e instanceof ErrorException
            && preg_match('/(^|[^\d])404([^\d]|$)/', $e->getMessage());
    }
}
