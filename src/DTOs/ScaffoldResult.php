<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\DTOs;

final readonly class ScaffoldResult
{
    /**
     * @param  array<int, string>  $renderedFiles  Relative paths of rendered files
     */
    public function __construct(
        public string $sourceDir,
        public string $targetDir,
        public bool $isOverride,
        public array $renderedFiles,
        public int $fileCount,
    ) {}
}
