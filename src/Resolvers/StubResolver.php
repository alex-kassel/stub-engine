<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Resolvers;

use AlexKassel\StubEngine\Enums\OverrideStrategy;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

class StubResolver
{
    public const DEFAULT_IGNORED_FILES = [
        '.DS_Store',
        'Thumbs.db',
        '.gitkeep',
    ];

    public function __construct(
        protected Filesystem $files = new Filesystem,
    ) {}

    /**
     * Resolve a unified relative-to-source map of stubs adhering to the chosen strategy.
     *
     * @param  string  $sourceDir  Default fallback template directory
     * @param  string|null  $overrideDir  Optional host override directory
     * @param  OverrideStrategy  $strategy  Overlay (cascading merge) vs Replace (directory substitution)
     * @param  array<int, string>  $ignoredFiles  Files to ignore during crawling
     * @return array<string, array{sourcePath: string, isOverride: bool}>
     *
     * @throws InvalidArgumentException
     */
    public function resolveStubsMap(
        string $sourceDir,
        ?string $overrideDir = null,
        OverrideStrategy $strategy = OverrideStrategy::Overlay,
        array $ignoredFiles = self::DEFAULT_IGNORED_FILES,
    ): array {
        if (! $this->files->isDirectory($sourceDir)) {
            throw new InvalidArgumentException("Stubs source directory not found: [{$sourceDir}].");
        }

        $hasOverrideDir = $overrideDir !== null && $this->files->isDirectory($overrideDir);

        if ($hasOverrideDir && $strategy === OverrideStrategy::Replace) {
            return $this->crawlDirectory($overrideDir, isOverride: true, ignoredFiles: $ignoredFiles);
        }

        $stubsMap = $this->crawlDirectory($sourceDir, isOverride: false, ignoredFiles: $ignoredFiles);

        if ($hasOverrideDir) {
            $overrideMap = $this->crawlDirectory($overrideDir, isOverride: true, ignoredFiles: $ignoredFiles);
            $stubsMap = array_merge($stubsMap, $overrideMap);
        }

        return $stubsMap;
    }

    /**
     * Crawl a directory and build a relative-to-source file mapping.
     *
     * @param  array<int, string>  $ignoredFiles
     * @return array<string, array{sourcePath: string, isOverride: bool}>
     */
    protected function crawlDirectory(string $directory, bool $isOverride, array $ignoredFiles): array
    {
        $map = [];

        foreach ($this->files->allFiles($directory) as $file) {
            if (in_array($file->getFilename(), $ignoredFiles, true)) {
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
}
