<?php

declare(strict_types=1);

namespace App\Users\Repository;

use App\Users\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** Used by the security provider to sign in; the email is matched case-insensitively. */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findOneBy(['email' => User::normalizeEmail($identifier)]);
    }
}
