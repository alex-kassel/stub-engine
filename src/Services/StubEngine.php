<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Services;

use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class StubEngine
{
    /**
     * Scaffold a complete directory tree from stubs with token replacements.
     *
     * @param  string  $sourceDir  Default fallback stubs directory
     * @param  string  $targetDir  Target directory where files will be created
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideDir  Optional host override directory (takes priority if it exists)
     * @param  string  $stubExtension  Extension to strip from output files (default: '.stub')
     *
     * @throws InvalidArgumentException
     */
    public function scaffoldTree(
        string $sourceDir,
        string $targetDir,
        array $tokens,
        ?string $overrideDir = null,
        string $stubExtension = '.stub',
    ): ScaffoldResult {
        $isOverride = $overrideDir !== null && File::isDirectory($overrideDir);
        $effectiveSource = $isOverride ? $overrideDir : $sourceDir;

        if (! File::isDirectory($effectiveSource)) {
            throw new InvalidArgumentException("Stubs source directory not found: [{$effectiveSource}].");
        }

        File::ensureDirectoryExists($targetDir);

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

            // Replace tokens in path
            $targetRelPath = str_replace($tokenKeys, $tokenValues, $relPath);
            if ($stubExtension !== '' && str_ends_with($targetRelPath, $stubExtension)) {
                $targetRelPath = substr($targetRelPath, 0, -$extLen);
            }

            $destPath = $targetDir.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetRelPath);

            if ($item->isDir()) {
                File::ensureDirectoryExists($destPath);
            } else {
                File::ensureDirectoryExists(dirname($destPath));
                $content = File::get($item->getPathname());
                $rendered = str_replace($tokenKeys, $tokenValues, $content);
                File::put($destPath, $rendered);
                $renderedFiles[] = $targetRelPath;
            }
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
