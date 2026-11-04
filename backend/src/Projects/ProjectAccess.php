<?php

declare(strict_types=1);

namespace App\Projects;

use App\Projects\Entity\Project;
use App\Projects\Entity\ProjectRole;
use App\Users\Entity\User;

/**
 * The signed-in member of the project named by the {projectId} route parameter. ProjectAccessListener sets it as the
 * "projectAccess" request attribute, so controllers receive it as an argument with that name.
 */
final readonly class ProjectAccess
{
    public function __construct(
        public Project $project,
        public User $user,
        public ProjectRole $role,
    ) {
    }
}
