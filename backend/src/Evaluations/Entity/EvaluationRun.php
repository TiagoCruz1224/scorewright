<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

use App\Datasets\Entity\Run;
use Doctrine\ORM\Mapping as ORM;

/** A run in an evaluation, with its letter (A, B, C… in the order of the request). */
#[ORM\Entity]
#[ORM\Table(name: 'evaluation_run')]
#[ORM\UniqueConstraint(name: 'evaluation_run_letter_key', columns: ['evaluation_id', 'letter'])]
class EvaluationRun
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Evaluation $evaluation,

        #[ORM\Id]
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private Run $run,

        #[ORM\Column(length: 1)]
        private string $letter,
    ) {
    }

    public function getEvaluation(): Evaluation
    {
        return $this->evaluation;
    }

    public function getRun(): Run
    {
        return $this->run;
    }

    public function getLetter(): string
    {
        return $this->letter;
    }
}
