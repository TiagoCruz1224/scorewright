<?php

declare(strict_types=1);

namespace App\Users\Security;

use App\Http\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/** Failed json_login as Problem Details: 429 when throttled, otherwise one 401 that does not say which part was wrong. */
final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $problem = $exception instanceof TooManyLoginAttemptsAuthenticationException
            ? new ApiProblem(429, 'too_many_login_attempts', 'Too many failed login attempts: wait a minute and try again')
            : new ApiProblem(401, 'invalid_credentials', 'The email or password is not correct');

        return $problem->toResponse();
    }
}
