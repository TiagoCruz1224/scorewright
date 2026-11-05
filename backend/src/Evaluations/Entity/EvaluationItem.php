<?php

declare(strict_types=1);

namespace App\Evaluations\Entity;

use App\Datasets\Entity\Answer;
use App\Evaluations\Repository\EvaluationItemRepository;
use App\Users\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One answer to score in an evaluation (ADR 0005). Its id is a random v4 UUID, so it reveals neither the run nor when
 * it was created; scoring addresses items only. The position groups items by case, with the runs shuffled within each
 * case in blind mode. The comment is one per item, independent of the scores.
 *
 * EvaluationItemRepository::createFor makes them in batches with SQL, the same as this constructor would.
 */
#[ORM\Entity(repositoryClass: EvaluationItemRepository::class)]
#[ORM\Table(name: 'evaluation_item')]
#[ORM\UniqueConstraint(name: 'evaluation_item_answer_key', columns: ['evaluation_id', 'answer_id'])]
#[ORM\Index(name: 'evaluation_item_position_idx', columns: ['evaluation_id', 'position'])]
class EvaluationItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Evaluation $evaluation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Answer $answer;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $commentBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $commentUpdatedAt = null;

    public function __construct(Evaluation $evaluation, Answer $answer, int $position)
    {
        $this->id = Uuid::v4();
        $this->evaluation = $evaluation;
        $this->answer = $answer;
        $this->position = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEvaluation(): Evaluation
    {
        return $this->evaluation;
    }

    public function getAnswer(): Answer
    {
        return $this->answer;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCommentBy(): ?User
    {
        return $this->commentBy;
    }

    public function getCommentUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->commentUpdatedAt;
    }

    /** An empty comment clears it; who and when are kept either way. */
    public function comment(string $comment, User $by): void
    {
        $this->comment = '' === trim($comment) ? null : $comment;
        $this->commentBy = $by;
        $this->commentUpdatedAt = new \DateTimeImmutable();
    }
}
