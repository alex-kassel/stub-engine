<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\DTOs;

final readonly class ScaffoldResult
{
    /** @var array<int, string> */
    public array $renderedFiles;

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
    }
}
