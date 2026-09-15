<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Services;

use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class StubEngine
{
    public const DEFAULT_STUB_EXTENSION = '.stub';

    public function __construct(
        protected Filesystem $files = new Filesystem,
    ) {}

    /**
     * Render a single stub file into a string with token replacements.
     *
     * @param  string  $sourceFile  Default fallback stub file path
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideFile  Optional host override file (takes priority if it exists)
     *
     * @throws InvalidArgumentException
     */
    public function renderFile(
        string $sourceFile,
        array $tokens,
        ?string $overrideFile = null,
    ): string {
        $isOverride = $overrideFile !== null && $this->files->isFile($overrideFile);
        $effectiveSource = $isOverride ? $overrideFile : $sourceFile;

        if (! $this->files->isFile($effectiveSource)) {
            throw new InvalidArgumentException("Stub file not found: [{$effectiveSource}].");
        }

        $content = (string) $this->files->get($effectiveSource);

        return str_replace(array_keys($tokens), array_values($tokens), $content);
    }

    /**
     * Scaffold a single stub file to a target destination with token replacements.
     *
     * @param  string  $sourceFile  Default fallback stub file path
     * @param  string  $targetFile  Target file path to generate
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideFile  Optional host override file (takes priority if it exists)
     * @param  bool  $force  Whether to overwrite an existing target file
     * @return bool True if created/overwritten, false if skipped because it already exists
     *
     * @throws InvalidArgumentException
     */
    public function scaffoldFile(
        string $sourceFile,
        string $targetFile,
        array $tokens,
        ?string $overrideFile = null,
        bool $force = false,
    ): bool {
        if ($this->files->exists($targetFile) && ! $force) {
            return false;
        }

        $rendered = $this->renderFile($sourceFile, $tokens, $overrideFile);

        $this->files->ensureDirectoryExists(dirname($targetFile));
        $this->files->put($targetFile, $rendered);

        return true;
    }

    /**
     * Scaffold a complete directory tree from stubs with token replacements.
     *
     * @param  string  $sourceDir  Default fallback stubs directory
     * @param  string  $targetDir  Target directory where files will be created
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideDir  Optional host override directory (takes priority if it exists)
     * @param  string  $stubExtension  Extension to strip from output files
     *
     * @throws InvalidArgumentException
     */
    public function scaffoldTree(
        string $sourceDir,
        string $targetDir,
        array $tokens,
        ?string $overrideDir = null,
        string $stubExtension = self::DEFAULT_STUB_EXTENSION,
    ): ScaffoldResult {
        $isOverride = $overrideDir !== null && $this->files->isDirectory($overrideDir);
        $effectiveSource = $isOverride ? $overrideDir : $sourceDir;

        if (! $this->files->isDirectory($effectiveSource)) {
            throw new InvalidArgumentException("Stubs source directory not found: [{$effectiveSource}].");
        }

        $this->files->ensureDirectoryExists($targetDir);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($effectiveSource, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $tokenKeys = array_keys($tokens);
        $tokenValues = array_values($tokens);
        $extLen = strlen($stubExtension);
        $renderedFiles = [];

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $relPath = substr($item->getPathname(), strlen($effectiveSource) + 1);

            if ($item->isDir()) {
                $dirName = str_replace($tokenKeys, $tokenValues, $relPath);
                $this->files->ensureDirectoryExists($targetDir.'/'.$dirName);

                continue;
            }

            $targetRelPath = str_replace($tokenKeys, $tokenValues, $relPath);
            if (str_ends_with($targetRelPath, $stubExtension)) {
                $targetRelPath = substr($targetRelPath, 0, -$extLen);
            }

            $content = (string) $this->files->get($item->getPathname());
            $renderedContent = str_replace($tokenKeys, $tokenValues, $content);

            $destination = $targetDir.'/'.$targetRelPath;
            $this->files->ensureDirectoryExists(dirname($destination));
            $this->files->put($destination, $renderedContent);

            $renderedFiles[] = $targetRelPath;
        }

        return new ScaffoldResult(
            sourceDir: $effectiveSource,
            targetDir: $targetDir,
            isOverride: $isOverride,
            renderedFiles: $renderedFiles,
            fileCount: count($renderedFiles),
        );
    }
}
