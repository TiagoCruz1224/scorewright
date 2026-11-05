<?php

declare(strict_types=1);

namespace App\Users\Controller;

use App\Http\ApiProblem;
use App\Users\Dto\RegisterRequest;
use App\Users\Entity\User;
use App\Users\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RateLimiterFactoryInterface $registrationLimiter,
    ) {
    }

    #[Route('/api/auth/register', name: 'auth_register', methods: ['POST'])]
    public function register(Request $httpRequest, #[MapRequestPayload(acceptFormat: 'json')] RegisterRequest $request): JsonResponse
    {
        // email_taken reveals that an account exists (§9): limit attempts per client IP.
        $limit = $this->registrationLimiter->create($httpRequest->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            throw ApiProblem::tooManyRequests('too_many_registrations', 'Too many registrations from this address: try again later', $limit->getRetryAfter());
        }

        if (null !== $this->users->loadUserByIdentifier($request->email)) {
            throw self::emailTaken();
        }

        $user = new User($request->email, trim($request->displayName));
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $request->password));
        $this->entityManager->persist($user);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw self::emailTaken(); // registered by a concurrent request
        }

        return new JsonResponse(self::userJson($user), Response::HTTP_CREATED);
    }

    /**
     * json_login (security.yaml) checks the credentials of a JSON request before this runs and starts a new session.
     * It ignores any other request, which reaches this method with no user or with the user already signed in.
     */
    #[Route('/api/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat() || null === $user) {
            throw new ApiProblem(415, 'unsupported_media_type', 'Send the credentials as application/json: {"email": "…", "password": "…"}');
        }

        return new JsonResponse(self::userJson($user));
    }

    #[Route('/api/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall logout listener handles this route.');
    }

    /** The logout listener invalidates the session; answer 204 instead of its default redirect. */
    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $event->setResponse(new Response(null, Response::HTTP_NO_CONTENT));
    }

    #[Route('/api/me', name: 'me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(self::userJson($user));
    }

    /**
     * @return array{id: string, email: string, displayName: string, createdAt: string}
     */
    private static function userJson(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'createdAt' => $user->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
        ];
    }

    private static function emailTaken(): ApiProblem
    {
        return ApiProblem::validation([['field' => 'email', 'code' => 'email_taken', 'message' => 'An account with this email already exists.']]);
    }
}
