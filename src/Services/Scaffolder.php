<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Services;

use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

class Scaffolder
{
    public function __construct(
        protected Filesystem $files,
        protected Interpolator $interpolator,
    ) {}

    /**
     * Execute scaffolding for either a single file or a complete directory tree.
     *
     * @throws InvalidArgumentException
     */
    public function scaffold(ScaffoldRequest $request): ScaffoldResult
    {
        if ($request->target === null) {
            throw new InvalidArgumentException('Target destination must be specified for scaffolding.');
        }

        if (! $this->files->exists($request->source)) {
            throw new InvalidArgumentException("Source stub path not found: [{$request->source}].");
        }

        return $this->files->isFile($request->source)
            ? $this->scaffoldFile($request)
            : $this->scaffoldTree($request);
    }

    /**
     * Render a stub file into a string with token replacements.
     *
     * @throws InvalidArgumentException
     */
    public function renderFile(ScaffoldRequest $request): string
    {
        $sourceFile = $this->resolveSourceFile($request);
        $content = (string) $this->files->get($sourceFile);
        $unresolved = [];
        $rendered = $this->interpolator->interpolate($content, $request, unresolved: $unresolved);

        if ($request->strict && $unresolved !== []) {
            throw new InvalidArgumentException("Unresolved tokens in stub file [{$sourceFile}]: ".implode(', ', $unresolved));
        }

        return $rendered;
    }

    /**
     * Scaffold a single file from a stub.
     *
     * @throws InvalidArgumentException
     */
    protected function scaffoldFile(ScaffoldRequest $request): ScaffoldResult
    {
        if ($this->files->isDirectory($request->target)) {
            throw new InvalidArgumentException("Target path [{$request->target}] is an existing directory. File scaffolding requires a file destination.");
        }

        $sourceFile = $this->resolveSourceFile($request);
        $exists = $this->files->exists($request->target);
        $fileName = basename($request->target);

        $createdFiles = [];
        $overwrittenFiles = [];
        $skippedFiles = [];

        if ($exists && ! $request->force) {
            $skippedFiles[] = $fileName;
        } else {
            $rendered = $this->renderFile($request);

            if (! $request->dryRun) {
                $this->putFile($request->target, $rendered);
            }

            if ($exists) {
                $overwrittenFiles[] = $fileName;
            } else {
                $createdFiles[] = $fileName;
            }
        }

        return new ScaffoldResult(
            request: $request,
            createdFiles: $createdFiles,
            overwrittenFiles: $overwrittenFiles,
            skippedFiles: $skippedFiles,
            overrideFiles: $sourceFile === $request->override ? [$fileName] : [],
        );
    }

    /**
     * Scaffold a complete directory tree from stubs.
     */
    protected function scaffoldTree(ScaffoldRequest $request): ScaffoldResult
    {
        if ($this->files->isFile($request->target)) {
            throw new InvalidArgumentException("Target path [{$request->target}] is an existing file. Directory tree scaffolding requires a directory destination.");
        }

        $stubsMap = $this->resolveStubsMap($request);

        $open = $request->openDelimiter ?? $this->interpolator->open;
        $close = $request->closeDelimiter ?? $this->interpolator->close;
        $mergedTokens = $this->interpolator->getMergedTokens($request);

        $createdFiles = [];
        $overwrittenFiles = [];
        $skippedFiles = [];
        $overrideFiles = [];
        $rawCopiedFiles = [];
        $unresolvedTokensMap = [];
        $totalFiles = count($stubsMap);
        $currentIndex = 0;

        foreach ($stubsMap as $relPath => $stubInfo) {
            $currentIndex++;

            $isStub = $request->stubExtension !== '' && str_ends_with($relPath, $request->stubExtension);
            $targetRelPath = $this->interpolator->interpolateContent($relPath, $mergedTokens, $open, $close);
            if ($isStub) {
                $targetRelPath = substr($targetRelPath, 0, -strlen($request->stubExtension));
            }

            $destination = rtrim($request->target, '/\\').'/'.$targetRelPath;
            $exists = $this->files->exists($destination);

            if ($stubInfo['isOverride']) {
                $overrideFiles[] = $targetRelPath;
            }

            if ($exists && ! $request->force) {
                $skippedFiles[] = $targetRelPath;
            } else {
                if ($exists) {
                    $overwrittenFiles[] = $targetRelPath;
                } else {
                    $createdFiles[] = $targetRelPath;
                }

                if ($isStub) {
                    $rawContent = (string) $this->files->get($stubInfo['sourcePath']);
                    $unresolved = [];
                    $rendered = $this->interpolator->interpolateContent($rawContent, $mergedTokens, $open, $close, $unresolved);

                    if ($unresolved !== []) {
                        $unresolvedTokensMap[$targetRelPath] = $unresolved;
                        if ($request->strict) {
                            throw new InvalidArgumentException("Unresolved tokens in [{$targetRelPath}]: ".implode(', ', $unresolved));
                        }
                    }

                    if (! $request->dryRun) {
                        $this->putFile($destination, $rendered);
                    }
                } else {
                    $rawCopiedFiles[] = $targetRelPath;
                    if (! $request->dryRun) {
                        $this->copyFile($stubInfo['sourcePath'], $destination);
                    }
                }
            }

            if ($request->onProgress !== null) {
                ($request->onProgress)($targetRelPath, $currentIndex, $totalFiles);
            }
        }

        return new ScaffoldResult(
            request: $request,
            createdFiles: $createdFiles,
            overwrittenFiles: $overwrittenFiles,
            skippedFiles: $skippedFiles,
            overrideFiles: $overrideFiles,
            rawCopiedFiles: $rawCopiedFiles,
            unresolvedTokens: $unresolvedTokensMap,
        );
    }

    /**
     * Resolve a unified relative-to-source map of stubs adhering to the chosen strategy.
     *
     * @return array<string, array{sourcePath: string, isOverride: bool}>
     *
     * @throws InvalidArgumentException
     */
    protected function resolveStubsMap(ScaffoldRequest $request): array
    {
        if (! $this->files->isDirectory($request->source)) {
            throw new InvalidArgumentException("Stubs source directory not found: [{$request->source}].");
        }

        if ($request->override !== null && $this->files->isFile($request->override)) {
            throw new InvalidArgumentException("Override path [{$request->override}] is a file. Directory scaffolding requires a directory override.");
        }

        $hasOverride = $request->override !== null && $this->files->isDirectory($request->override);

        if ($hasOverride && $request->strategy === OverrideStrategy::Replace) {
            return $this->crawlDirectory($request->override, isOverride: true, ignoredFiles: $request->ignoredFiles);
        }

        $map = $this->crawlDirectory($request->source, isOverride: false, ignoredFiles: $request->ignoredFiles);

        if ($hasOverride) {
            $map = array_merge($map, $this->crawlDirectory($request->override, isOverride: true, ignoredFiles: $request->ignoredFiles));
        }

        return $map;
    }

    /**
     * Resolve the effective single stub file to process, validating that paths are not directories.
     *
     * @throws InvalidArgumentException
     */
    protected function resolveSourceFile(ScaffoldRequest $request): string
    {
        if ($this->files->isDirectory($request->source)) {
            throw new InvalidArgumentException("Cannot render directory [{$request->source}] as a file. The renderFile() method only supports single stub files.");
        }

        if ($request->override !== null) {
            if ($this->files->isDirectory($request->override)) {
                throw new InvalidArgumentException("Override path [{$request->override}] is a directory. File operations require a file override.");
            }

            if ($this->files->isFile($request->override)) {
                return $request->override;
            }
        }

        if (! $this->files->isFile($request->source)) {
            throw new InvalidArgumentException("Stub file not found: [{$request->source}].");
        }

        return $request->source;
    }

    /**
     * Crawl a directory and build a relative-to-source file mapping.
     *
     * @param  array<int, string>  $ignoredFiles
     * @return array<string, array{sourcePath: string, isOverride: bool}>
     */
    protected function crawlDirectory(string $directory, bool $isOverride, array $ignoredFiles = []): array
    {
        $map = [];

        foreach ($this->files->allFiles($directory) as $file) {
            if ($ignoredFiles !== [] && in_array($file->getFilename(), $ignoredFiles, true)) {
                continue;
            }

            $relPath = str_replace('\\', '/', $file->getRelativePathname());
            $map[$relPath] = [
                'sourcePath' => $file->getPathname(),
                'isOverride' => $isOverride,
            ];
        }

        return $map;
    }

    /**
     * Write file contents ensuring target directory exists via Laravel Filesystem.
     */
    protected function putFile(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists($this->files->dirname($path));
        $this->files->put($path, $content);
    }

    /**
     * Copy file from source to target ensuring target directory exists via Laravel Filesystem.
     */
    protected function copyFile(string $source, string $target): void
    {
        $this->files->ensureDirectoryExists($this->files->dirname($target));
        $this->files->copy($source, $target);
    }
}
