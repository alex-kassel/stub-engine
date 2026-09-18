<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\DTOs;

use AlexKassel\StubEngine\Enums\OverrideStrategy;
use Closure;

final readonly class ScaffoldRequest
{
    /**
     * @param  string  $source  Source stub file or directory
     * @param  string  $target  Target file destination or directory
     * @param  array<string, string>  $tokens  Key-value token replacements
     * @param  string|null  $override  Optional host override file or directory
     * @param  OverrideStrategy  $strategy  Override strategy (Overlay or Replace)
     * @param  string  $stubExtension  Extension stripped from output filenames
     * @param  bool  $force  Whether to overwrite existing files
     * @param  bool  $dryRun  Whether to simulate without writing to disk
     * @param  bool  $strict  Whether to throw on unresolved tokens
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     * @param  (Closure(string $relativePath, int $currentIndex, int $totalFiles): void)|null  $onProgress  Progress callback
     */
    public function __construct(
        public string $source,
        public ?string $target = null,
        public array $tokens = [],
        public ?string $override = null,
        public OverrideStrategy $strategy = OverrideStrategy::Overlay,
        public string $stubExtension = '.stub',
        public bool $force = false,
        public bool $dryRun = false,
        public bool $strict = false,
        public ?string $openDelimiter = null,
        public ?string $closeDelimiter = null,
        public array $ignoredFiles = [],
        public ?Closure $onProgress = null,
    ) {}
}
