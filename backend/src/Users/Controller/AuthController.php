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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/api/auth/register', name: 'auth_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $request): JsonResponse
    {
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

    /** json_login (security.yaml) checks the credentials before this runs and starts a new session. */
    #[Route('/api/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            throw new ApiProblem(400, 'malformed_request', 'Send the credentials as JSON: {"email": "…", "password": "…"}');
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
