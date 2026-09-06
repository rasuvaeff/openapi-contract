<?php

declare(strict_types=1);

namespace Rasuvaeff\OpenApiContract\Internal\Reference;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\InvalidContract;

/**
 * Loads and caches the files of a multi-file OpenAPI document under one
 * canonical root with shared byte and file-count budgets. External $refs may
 * target only regular files inside the entry file's directory tree; absolute
 * paths, URI schemes, percent-encoded forms, traversal and symlink escapes
 * are rejected before any read. Error messages expose paths relative to the
 * document root, never arbitrary host paths.
 *
 * @internal
 */
final class DocumentGraph
{
    /** @var array<string, array<string, mixed>> */
    private array $documents = [];

    private function __construct(private readonly string $root, private readonly string $entryPath, private readonly int $maximumFiles, private int $remainingBytes) {}

    public static function open(string $path, int $maximumFiles = 64, int $maximumBytes = Contract::MAX_DOCUMENT_BYTES): self
    {
        if ($maximumFiles < 1) {
            throw new \InvalidArgumentException('Maximum file count must be positive');
        }
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('Maximum byte budget must be positive');
        }
        $canonical = realpath($path);
        if ($canonical === false || !is_file($canonical)) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" is not readable', $path));
        }

        $graph = new self(\dirname($canonical), $canonical, $maximumFiles, $maximumBytes);
        $graph->document($canonical);

        return $graph;
    }

    public function entryPath(): string
    {
        return $this->entryPath;
    }

    /** @return array<string, mixed> */
    public function entryDocument(): array
    {
        return $this->document($this->entryPath);
    }

    /** @return array<string, mixed> */
    public function document(string $canonicalPath): array
    {
        if (isset($this->documents[$canonicalPath])) {
            return $this->documents[$canonicalPath];
        }
        if (count($this->documents) >= $this->maximumFiles) {
            throw new InvalidContract(sprintf('OpenAPI document graph exceeds the budget of %d files', $this->maximumFiles));
        }
        $display = $this->displayPath($canonicalPath);
        $size = @filesize($canonicalPath);
        if (is_int($size) && $size > $this->remainingBytes) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" exceeds the shared byte budget', $display));
        }
        $contents = @file_get_contents($canonicalPath);
        if ($contents === false) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" is not readable', $display));
        }
        if (strlen($contents) > $this->remainingBytes) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" exceeds the shared byte budget', $display));
        }
        $this->remainingBytes -= strlen($contents);

        return $this->documents[$canonicalPath] = $this->parse($canonicalPath, $display, $contents);
    }

    /**
     * Canonicalizes the file part of one external $ref against the file that
     * declares it, failing closed before any filesystem read outside the root.
     */
    public function resolveTarget(string $fromFile, string $filePart, string $reference): string
    {
        $source = $this->displayPath($fromFile);
        if (str_contains($filePart, '\\') || str_contains($filePart, '%')) {
            throw new InvalidContract(sprintf('$ref "%s" in OpenAPI document "%s" uses an unsupported file path form', $reference, $source));
        }
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*+:~', $filePart) === 1 || str_starts_with($filePart, '//')) {
            throw new InvalidContract(sprintf('$ref "%s" in OpenAPI document "%s" targets a remote or non-file URI', $reference, $source));
        }
        if (str_starts_with($filePart, '/')) {
            throw new InvalidContract(sprintf('$ref "%s" in OpenAPI document "%s" must use a relative path', $reference, $source));
        }
        $target = realpath(\dirname($fromFile) . '/' . $filePart);
        if ($target !== false && !str_starts_with($target, $this->root . '/')) {
            throw new InvalidContract(sprintf('$ref "%s" in OpenAPI document "%s" escapes the document root', $reference, $source));
        }
        if ($target === false || !is_file($target)) {
            throw new InvalidContract(sprintf('$ref "%s" in OpenAPI document "%s" references a missing file', $reference, $source));
        }

        return $target;
    }

    public function displayPath(string $canonicalPath): string
    {
        return substr($canonicalPath, strlen($this->root) + 1);
    }

    /** @return array<string, mixed> */
    private function parse(string $canonicalPath, string $display, string $contents): array
    {
        $lower = strtolower($canonicalPath);
        if (str_ends_with($lower, '.yaml') || str_ends_with($lower, '.yml')) {
            $yamlClass = 'Symfony\\Component\\Yaml\\' . 'Yaml';
            if (!class_exists($yamlClass)) {
                throw new InvalidContract('YAML loading requires symfony/yaml');
            }

            try {
                /** @var mixed $document */
                $document = call_user_func([$yamlClass, 'parse'], $contents);
            } catch (\RuntimeException $exception) {
                // symfony/yaml raises `ParseException`, which extends
                // `RuntimeException`. Naming the class here would make an
                // optional dependency a required one, and letting it out would
                // put a third-party type on a public exit the JSON branch
                // below already keeps inside this package.
                throw new InvalidContract(sprintf('OpenAPI YAML document "%s" is not valid YAML', $display), (int) $exception->getCode(), previous: $exception);
            }
            if (!is_array($document) || array_is_list($document)) {
                throw new InvalidContract(sprintf('OpenAPI YAML document "%s" must decode to an object', $display));
            }

            /** @var array<string, mixed> $document */
            return $document;
        }

        try {
            /** @var mixed $document */
            $document = json_decode($contents, associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" is not valid JSON', $display), $exception->getCode(), previous: $exception);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidContract(sprintf('OpenAPI document "%s" must decode to an object', $display));
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}
