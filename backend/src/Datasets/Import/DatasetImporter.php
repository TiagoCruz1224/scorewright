<?php

declare(strict_types=1);

namespace App\Datasets\Import;

use App\Datasets\Entity\Dataset;
use App\Datasets\Entity\DatasetVersion;
use App\Datasets\Repository\TestCaseRepository;
use App\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * The upload side of an import (ADR 0004): the checks before parsing, the parse, and the insert. The file is read in
 * memory and never stored; the version keeps only its SHA-256 and its cleaned-up original name.
 */
final class DatasetImporter
{
    public function __construct(
        private readonly CaseFileReader $reader,
        private readonly EntityManagerInterface $entityManager,
        private readonly TestCaseRepository $cases,
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
            throw ApiProblem::validation([['field' => 'file', 'code' => 'required', 'message' => 'Attach the dataset file (.csv or .json) in the "file" field.']]);
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
    public function read(UploadedFile $file): array|ImportErrors
    {
        $format = strtolower(pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION));
        if ('csv' !== $format && 'json' !== $format) {
            $errors = new ImportErrors('line');
            $errors->add(null, null, 'unsupported_file_type', 'Upload a .csv or a .json file, in the import format.');

            return $errors;
        }

        return $this->reader->read($file->getContent(), $format);
    }

    /**
     * Inserts the version and its cases. Call it inside the import transaction.
     *
     * @param list<ImportedCase> $cases
     */
    public function saveVersion(Dataset $dataset, int $number, UploadedFile $file, array $cases): DatasetVersion
    {
        $version = new DatasetVersion($dataset, $number, \count($cases), self::sourceFilename($file->getClientOriginalName()), (string) hash_file('sha256', $file->getPathname()));
        $this->entityManager->persist($version);
        $this->entityManager->flush();
        $this->cases->insertBatch($version, $cases);

        return $version;
    }

    /** The name only: no path, valid UTF-8, no control characters, at most 255 characters. */
    private static function sourceFilename(string $clientName): string
    {
        $name = (string) preg_replace(['~^.*[/\\\\]~s', '/\p{Cc}/u'], '', mb_scrub($clientName, 'UTF-8'));

        return mb_substr('' !== trim($name) ? trim($name) : 'upload', 0, 255);
    }

    private function tooLarge(): ApiProblem
    {
        return new ApiProblem(413, 'payload_too_large', \sprintf('The file is larger than the limit of %s bytes', number_format($this->maxFileBytes)));
    }
}
