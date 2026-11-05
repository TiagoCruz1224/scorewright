<?php

declare(strict_types=1);

namespace App\Datasets\Import;

use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Entity\Run;
use App\Datasets\Repository\AnswerRepository;
use App\Datasets\Repository\TestCaseRepository;
use App\Http\ApiProblem;
use App\Projects\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * The upload side of the dataset and run imports (ADR 0004): the checks before parsing, the parse, and the insert.
 * The file is read in memory and never stored; the version or run keeps only its SHA-256 and its cleaned-up name.
 */
final class DatasetImporter
{
    public function __construct(
        private readonly CaseFileReader $caseReader,
        private readonly RunFileReader $runReader,
        private readonly EntityManagerInterface $entityManager,
        private readonly TestCaseRepository $cases,
        private readonly AnswerRepository $answers,
        #[Autowire(param: 'dataset_import.max_file_bytes')]
        private readonly int $maxFileBytes,
    ) {
    }

    /** The "file" field of a multipart request, checked before anything parses it. */
    public function uploadedFile(Request $request): UploadedFile
    {
        if (!str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'multipart/form-data')) {
            throw new ApiProblem(415, 'unsupported_media_type', 'Send the file as multipart/form-data, in a field named "file"');
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw ApiProblem::validation([['field' => 'file', 'code' => 'required', 'message' => 'Attach the file (.csv or .json) in the "file" field.']]);
        }
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw $this->tooLarge();
        }
        if (!$file->isValid()) {
            throw new ApiProblem(400, 'malformed_request', 'The file upload did not complete: try again');
        }
        if ($file->getSize() > $this->maxFileBytes) {
            throw $this->tooLarge();
        }

        return $file;
    }

    /**
     * @return list<ImportedCase>|ImportErrors
     */
    public function readCases(UploadedFile $file): array|ImportErrors
    {
        $format = self::formatOf($file);

        return $format instanceof ImportErrors ? $format : $this->caseReader->read($file->getContent(), $format);
    }

    /**
     * @return list<ImportedAnswer>|ImportErrors
     */
    public function readAnswers(UploadedFile $file, DatasetVersion $version): array|ImportErrors
    {
        $format = self::formatOf($file);

        return $format instanceof ImportErrors ? $format : $this->runReader->read($file->getContent(), $format, $this->cases->idsByKey($version));
    }

    /**
     * Inserts the version and its cases. Call it inside the import transaction.
     *
     * @param list<ImportedCase> $cases
     */
    public function saveVersion(Dataset $dataset, int $number, UploadedFile $file, array $cases): DatasetVersion
    {
        $version = new DatasetVersion($dataset, $number, \count($cases), self::sourceFilename($file), self::sha256($file));
        $this->entityManager->persist($version);
        $this->entityManager->flush();
        $this->cases->insertBatch($version, $cases);

        return $version;
    }

    /**
     * Inserts the run and its answers. Call it inside the import transaction.
     *
     * @param list<ImportedAnswer> $answers
     */
    public function saveRun(Project $project, DatasetVersion $version, string $label, string $modelDescription, UploadedFile $file, array $answers): Run
    {
        $run = new Run($project, $version, $label, $modelDescription, \count($answers), self::sourceFilename($file), self::sha256($file));
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->answers->insertBatch($run, $answers);

        return $run;
    }

    /**
     * @return 'csv'|'json'|ImportErrors
     */
    private static function formatOf(UploadedFile $file): string|ImportErrors
    {
        $format = strtolower(pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION));
        if ('csv' === $format || 'json' === $format) {
            return $format;
        }
        $errors = new ImportErrors('line');
        $errors->add(null, null, 'unsupported_file_type', 'Upload a .csv or a .json file, in the import format.');

        return $errors;
    }

    /** The name only: no path, valid UTF-8, no control characters, at most 255 characters. */
    private static function sourceFilename(UploadedFile $file): string
    {
        $name = (string) preg_replace(['~^.*[/\\\\]~s', '/\p{Cc}/u'], '', mb_scrub($file->getClientOriginalName(), 'UTF-8'));

        return mb_substr('' !== trim($name) ? trim($name) : 'upload', 0, 255);
    }

    private static function sha256(UploadedFile $file): string
    {
        return (string) hash_file('sha256', $file->getPathname());
    }

    private function tooLarge(): ApiProblem
    {
        return new ApiProblem(413, 'payload_too_large', \sprintf('The file is larger than the limit of %s bytes', number_format($this->maxFileBytes)));
    }
}
