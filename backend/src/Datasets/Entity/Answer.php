<?php

declare(strict_types=1);

namespace App\Datasets\Entity;

use App\Datasets\Repository\AnswerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A run's answer to one case. Imports insert answers in batches through DBAL (AnswerRepository). No endpoint lists
 * them (architecture §5, "Run data and blind mode"): only scoring and results will, from slice 9 on.
 */
#[ORM\Entity(repositoryClass: AnswerRepository::class)]
#[ORM\Table(name: 'answer')]
#[ORM\UniqueConstraint(name: 'answer_run_case_key', columns: ['run_id', 'test_case_id'])]
#[ORM\Index(name: 'answer_test_case_idx', columns: ['test_case_id'])]
class Answer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Run $run,

        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private TestCase $testCase,

        #[ORM\Column(type: Types::TEXT)]
        private string $output,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRun(): Run
    {
        return $this->run;
    }

    public function getTestCase(): TestCase
    {
        return $this->testCase;
    }

    public function getOutput(): string
    {
        return $this->output;
    }
}
