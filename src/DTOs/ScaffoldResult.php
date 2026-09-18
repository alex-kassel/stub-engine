<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\DTOs;

use Countable;

final readonly class ScaffoldResult implements Countable
{
    /** @var array<int, string> */
    public array $renderedFiles;

    public int $fileCount;

    /**
     * @param  ScaffoldRequest  $request  The request that initiated the scaffolding
     * @param  array<int, string>  $createdFiles  Relative paths of freshly created files
     * @param  array<int, string>  $overwrittenFiles  Relative paths of overwritten files
     * @param  array<int, string>  $skippedFiles  Relative paths of skipped files
     * @param  array<int, string>  $overrideFiles  Relative paths of files resolved from override directory
     * @param  array<int, string>  $rawCopiedFiles  Relative paths of raw/binary files copied without interpolation
     * @param  array<string, array<int, string>>  $unresolvedTokens  Map of relative file paths to any unreplaced token placeholders
     */
    public function __construct(
        public ScaffoldRequest $request,
        public array $createdFiles = [],
        public array $overwrittenFiles = [],
        public array $skippedFiles = [],
        public array $overrideFiles = [],
        public array $rawCopiedFiles = [],
        public array $unresolvedTokens = [],
    ) {
        $this->renderedFiles = array_values(array_unique(array_merge($this->createdFiles, $this->overwrittenFiles)));
        $this->fileCount = count($this->renderedFiles);
    }

    /**
     * Total number of rendered files.
     */
    public function count(): int
    {
        return $this->fileCount;
    }

    /**
     * Check if any files were resolved from host overrides.
     */
    public function hasOverrides(): bool
    {
        return $this->overrideFiles !== [];
    }

    /**
     * Check if any new files were created.
     */
    public function hasCreated(): bool
    {
        return $this->createdFiles !== [];
    }

    /**
     * Check if any files were skipped.
     */
    public function hasSkipped(): bool
    {
        return $this->skippedFiles !== [];
    }

    /**
     * Check if any existing files were overwritten.
     */
    public function hasOverwritten(): bool
    {
        return $this->overwrittenFiles !== [];
    }

    /**
     * Check if the operation was successful (created or overwritten at least one file).
     */
    public function successful(): bool
    {
        return $this->hasCreated() || $this->hasOverwritten();
    }

    /**
     * Check if any files have unresolved token placeholders.
     */
    public function hasUnresolvedTokens(): bool
    {
        return $this->unresolvedTokens !== [];
    }

    /**
     * Check if any raw/binary files were copied directly.
     */
    public function hasRawCopied(): bool
    {
        return $this->rawCopiedFiles !== [];
    }
}
