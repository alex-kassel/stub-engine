<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Builders;

use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\StubEngine;
use Closure;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;

class ScaffoldBuilder
{
    use Conditionable;
    use Macroable;

    protected ?string $source = null;

    protected ?string $target = null;

    protected ?string $override = null;

    /**
     * @var array<string, string>
     */
    protected array $tokens = [];

    protected OverrideStrategy $strategy = OverrideStrategy::Overlay;

    protected string $stubExtension = '.stub';

    protected bool $force = false;

    protected bool $dryRun = false;

    protected bool $strict = false;

    protected ?string $openDelimiter = null;

    protected ?string $closeDelimiter = null;

    /**
     * @var array<int, string>
     */
    protected array $ignoredFiles = [];

    protected ?Closure $onProgress = null;

    public function __construct(
        protected StubEngine $engine,
    ) {}

    /**
     * Set files to ignore during directory crawling.
     *
     * @param  array<int, string>  $files
     */
    public function ignore(array $files): self
    {
        $this->ignoredFiles = array_values(array_unique(array_merge($this->ignoredFiles, $files)));

        return $this;
    }

    /**
     * Set the source stub file or directory.
     */
    public function from(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Set the target destination file or directory.
     */
    public function to(string $target): self
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Set the host override file or directory and optional override strategy.
     */
    public function override(?string $override, OverrideStrategy $strategy = OverrideStrategy::Overlay): self
    {
        $this->override = $override;
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Merge multiple token replacements.
     *
     * @param  array<string, mixed>  $tokens
     */
    public function withTokens(array $tokens): self
    {
        foreach ($tokens as $key => $value) {
            $this->tokens[(string) $key] = (string) $value;
        }

        return $this;
    }

    /**
     * Explicitly set the override strategy.
     */
    public function strategy(OverrideStrategy $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Set the stub file extension to strip upon generation (default: .stub).
     */
    public function stubExtension(string $extension): self
    {
        $this->stubExtension = $extension;

        return $this;
    }

    /**
     * Set custom open and close token delimiters.
     */
    public function delimiters(string $open, string $close): self
    {
        $this->openDelimiter = $open;
        $this->closeDelimiter = $close;

        return $this;
    }

    /**
     * Enable or disable overwriting existing files.
     */
    public function force(bool $force = true): self
    {
        $this->force = $force;

        return $this;
    }

    /**
     * Enable or disable dry-run simulation mode without writing to disk.
     */
    public function dryRun(bool $dryRun = true): self
    {
        $this->dryRun = $dryRun;

        return $this;
    }

    /**
     * Enable or disable strict mode (throws exception on unresolved tokens).
     */
    public function strict(bool $strict = true): self
    {
        $this->strict = $strict;

        return $this;
    }

    /**
     * Register a progress callback invoked during tree scaffolding.
     *
     * @param  (callable(string $relativePath, int $currentIndex, int $totalFiles): void)|null  $callback
     */
    public function onProgress(?callable $callback): self
    {
        $this->onProgress = $callback !== null ? $callback(...) : null;

        return $this;
    }

    /**
     * Render the source stub file into a string without writing to disk.
     *
     * @throws InvalidArgumentException
     */
    public function renderFile(): string
    {
        return $this->engine->renderFile($this->toRequest());
    }

    /**
     * Build the immutable ScaffoldRequest DTO from configured builder state.
     *
     * @throws InvalidArgumentException
     */
    public function toRequest(): ScaffoldRequest
    {
        if ($this->source === null) {
            throw new InvalidArgumentException('Source must be specified via from().');
        }

        return new ScaffoldRequest(
            source: $this->source,
            target: $this->target,
            tokens: $this->tokens,
            override: $this->override,
            strategy: $this->strategy,
            stubExtension: $this->stubExtension,
            force: $this->force,
            dryRun: $this->dryRun,
            strict: $this->strict,
            openDelimiter: $this->openDelimiter,
            closeDelimiter: $this->closeDelimiter,
            ignoredFiles: $this->ignoredFiles,
            onProgress: $this->onProgress,
        );
    }

    /**
     * Execute scaffolding and return the detailed ScaffoldResult DTO.
     *
     * @throws InvalidArgumentException
     */
    public function scaffold(): ScaffoldResult
    {
        return $this->engine->scaffold($this->toRequest());
    }
}
