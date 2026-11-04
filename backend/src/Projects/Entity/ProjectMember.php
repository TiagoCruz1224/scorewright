<?php

declare(strict_types=1);

namespace App\Projects\Entity;

use App\Projects\Repository\ProjectMemberRepository;
use App\Users\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectMemberRepository::class)]
#[ORM\Table(name: 'project_member')]
#[ORM\Index(name: 'project_member_user_idx', columns: ['user_id'])]
class ProjectMember
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Project $project,

        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,

        #[ORM\Column(length: 20, enumType: ProjectRole::class)]
        private ProjectRole $role,
    ) {
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): ProjectRole
    {
        return $this->role;
    }

    public function changeRole(ProjectRole $role): void
    {
        $this->role = $role;
    }
}
