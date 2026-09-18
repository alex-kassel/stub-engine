<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Builders;

use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\StubEngine;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;

class ScaffoldBuilder
{
    use Conditionable;
    use Macroable;

    /**
     * @var array<string, mixed>
     */
    protected array $options = [];

    public function __construct(
        protected StubEngine $engine,
    ) {
        $this->options = (new ScaffoldRequest(''))->toArray();
    }

    /**
     * Set files to ignore during directory crawling.
     *
     * @param  array<int, string>  $files
     */
    public function ignore(array $files): self
    {
        $this->options['ignoredFiles'] = array_values(
            array_unique(array_merge($this->options['ignoredFiles'], $files))
        );

        return $this;
    }

    /**
     * Set the source stub file or directory.
     */
    public function from(string $source): self
    {
        $this->options['source'] = $source;

        return $this;
    }

    /**
     * Set the target destination file or directory.
     */
    public function to(string $target): self
    {
        $this->options['target'] = $target;

        return $this;
    }

    /**
     * Set the host override file or directory and optional override strategy.
     */
    public function override(?string $override, OverrideStrategy $strategy = OverrideStrategy::Overlay): self
    {
        $this->options['override'] = $override;
        $this->options['strategy'] = $strategy;

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
            $this->options['tokens'][(string) $key] = (string) $value;
        }

        return $this;
    }

    /**
     * Set the stub file extension to strip upon generation (default: .stub).
     */
    public function stubExtension(string $extension): self
    {
        $this->options['stubExtension'] = $extension;

        return $this;
    }

    /**
     * Set custom open and close token delimiters.
     */
    public function delimiters(string $open, string $close): self
    {
        $this->options['openDelimiter'] = $open;
        $this->options['closeDelimiter'] = $close;

        return $this;
    }

    /**
     * Enable or disable overwriting existing files.
     */
    public function force(bool $force = true): self
    {
        $this->options['force'] = $force;

        return $this;
    }

    /**
     * Enable or disable dry-run simulation mode without writing to disk.
     */
    public function dryRun(bool $dryRun = true): self
    {
        $this->options['dryRun'] = $dryRun;

        return $this;
    }

    /**
     * Enable or disable strict mode (throws exception on unresolved tokens).
     */
    public function strict(bool $strict = true): self
    {
        $this->options['strict'] = $strict;

        return $this;
    }

    /**
     * Register a progress callback invoked during tree scaffolding.
     *
     * @param  (callable(string $relativePath, int $currentIndex, int $totalFiles): void)|null  $callback
     */
    public function onProgress(?callable $callback): self
    {
        $this->options['onProgress'] = $callback !== null ? $callback(...) : null;

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
     */
    public function toRequest(): ScaffoldRequest
    {
        return new ScaffoldRequest(...$this->options);
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
