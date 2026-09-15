<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\DTOs;

use AlexKassel\StubEngine\Enums\OverrideStrategy;
use Countable;

final readonly class ScaffoldResult implements Countable
{
    /** @var array<int, string> */
    public array $renderedFiles;

    public int $fileCount;

    public bool $isOverride;

    /**
     * @param  string  $sourceDir  Default template source directory
     * @param  string  $targetDir  Target destination directory
     * @param  array<int, string>  $createdFiles  Relative paths of freshly created files
     * @param  array<int, string>  $overwrittenFiles  Relative paths of overwritten files
     * @param  array<int, string>  $skippedFiles  Relative paths of skipped files
     * @param  array<int, string>  $overrideFiles  Relative paths of files resolved from override directory
     * @param  array<int, string>  $rawCopiedFiles  Relative paths of raw/binary files copied without interpolation
     * @param  array<string, array<int, string>>  $unresolvedTokens  Map of relative file paths to any unreplaced token placeholders
     * @param  bool  $dryRun  Whether the operation was simulated without disk writes
     * @param  OverrideStrategy  $strategy  The override strategy used (Overlay or Replace)
     */
    public function __construct(
        public string $sourceDir,
        public string $targetDir,
        public array $createdFiles = [],
        public array $overwrittenFiles = [],
        public array $skippedFiles = [],
        public array $overrideFiles = [],
        public array $rawCopiedFiles = [],
        public array $unresolvedTokens = [],
        public bool $dryRun = false,
        public OverrideStrategy $strategy = OverrideStrategy::Overlay,
    ) {
        $this->renderedFiles = array_values(array_unique(array_merge($this->createdFiles, $this->overwrittenFiles)));
        $this->fileCount = count($this->renderedFiles);
        $this->isOverride = $this->overrideFiles !== [];
    }

    /**
     * Total number of rendered files.
     */
    public function count(): int
    {
        return $this->fileCount;
    }

    /**
     * Total number of rendered files.
     */
    public function totalFiles(): int
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

    /**
     * Check if the strategy was Replace.
     */
    public function isReplace(): bool
    {
        return $this->strategy === OverrideStrategy::Replace;
    }

    /**
     * Check if the strategy was Overlay.
     */
    public function isOverlay(): bool
    {
        return $this->strategy === OverrideStrategy::Overlay;
    }
}
