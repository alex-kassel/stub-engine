<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\DTOs;

use Countable;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class ScaffoldResult implements Arrayable, Countable
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
