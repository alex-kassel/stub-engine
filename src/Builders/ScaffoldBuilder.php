<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Builders;

use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;

class ScaffoldBuilder
{
    use Conditionable;
    use Macroable;

    public const DEFAULT_VENDOR_STUBS_DIR = 'stubs/vendor';

    public const DEFAULT_STUB_EXTENSION = StubEngine::DEFAULT_STUB_EXTENSION;

    public const DEFAULT_STRATEGY = OverrideStrategy::Overlay;

    public const DEFAULT_FORCE = false;

    public const DEFAULT_DRY_RUN = false;

    public const DEFAULT_STRICT = false;

    protected ?string $sourceDir = null;

    protected ?string $targetDir = null;

    protected ?string $sourceFile = null;

    protected ?string $targetFile = null;

    /**
     * @var array<string, string>
     */
    protected array $tokens = [];

    protected ?string $overrideDir = null;

    protected ?string $overrideFile = null;

    protected OverrideStrategy $strategy = self::DEFAULT_STRATEGY;

    protected string $stubExtension = self::DEFAULT_STUB_EXTENSION;

    protected bool $force = self::DEFAULT_FORCE;

    protected bool $dryRun = self::DEFAULT_DRY_RUN;

    protected bool $strict = self::DEFAULT_STRICT;

    protected ?string $openDelimiter = null;

    protected ?string $closeDelimiter = null;

    public function __construct(
        protected StubEngine $engine,
    ) {}

    /**
     * Set the source directory containing default stubs.
     */
    public function from(string $sourceDir): self
    {
        $this->sourceDir = $sourceDir;

        return $this;
    }

    /**
     * Set the target directory where scaffolded files will be generated.
     */
    public function to(string $targetDir): self
    {
        $this->targetDir = $targetDir;

        return $this;
    }

    /**
     * Set the source stub file for single-file scaffolding.
     */
    public function fromFile(string $sourceFile): self
    {
        $this->sourceFile = $sourceFile;

        return $this;
    }

    /**
     * Set the target destination file for single-file scaffolding.
     */
    public function toFile(string $targetFile): self
    {
        $this->targetFile = $targetFile;

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
     * Alias for withTokens.
     *
     * @param  array<string, mixed>  $tokens
     */
    public function with(array $tokens): self
    {
        return $this->withTokens($tokens);
    }

    /**
     * Set a single token replacement.
     */
    public function token(string $key, mixed $value): self
    {
        $this->tokens[$key] = (string) $value;

        return $this;
    }

    /**
     * Automatically discover and activate host overrides according to package conventions:
     * stubs/vendor/{package}/{subpath?}
     *
     * If the host directory exists, it is automatically configured with the Overlay strategy.
     */
    public function forPackage(string $package, ?string $subpath = null): self
    {
        $relativePath = self::DEFAULT_VENDOR_STUBS_DIR.'/'.trim($package, '/');
        if ($subpath !== null) {
            $relativePath .= '/'.trim($subpath, '/');
        }

        $overrideDir = (function_exists('base_path') && function_exists('app') && method_exists(app(), 'basePath'))
            ? base_path($relativePath)
            : $relativePath;

        if (is_dir($overrideDir)) {
            $this->overlay($overrideDir);
        }

        return $this;
    }

    /**
     * Set host overrides directory and activate the Overlay (cascading merge) strategy.
     */
    public function overlay(?string $overrideDir = null): self
    {
        if ($overrideDir !== null) {
            $this->overrideDir = $overrideDir;
        }
        $this->strategy = OverrideStrategy::Overlay;

        return $this;
    }

    /**
     * Set host overrides directory and activate the Replace (all-or-nothing) strategy.
     */
    public function replace(?string $overrideDir = null): self
    {
        if ($overrideDir !== null) {
            $this->overrideDir = $overrideDir;
        }
        $this->strategy = OverrideStrategy::Replace;

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
     * Set the host override directory.
     */
    public function overrideDir(?string $overrideDir): self
    {
        $this->overrideDir = $overrideDir;

        return $this;
    }

    /**
     * Set the host override file for single-file scaffolding.
     */
    public function overrideFile(?string $overrideFile): self
    {
        $this->overrideFile = $overrideFile;

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
     * Access the underlying StubEngine coordinator.
     */
    public function engine(): StubEngine
    {
        return $this->engine;
    }

    /**
     * Render the single stub file into a string without writing to disk.
     *
     * @throws InvalidArgumentException
     */
    public function render(): string
    {
        if ($this->sourceFile === null) {
            throw new InvalidArgumentException('Source file must be specified via fromFile() to render.');
        }

        return $this->engine->renderFile(
            sourceFile: $this->sourceFile,
            tokens: $this->tokens,
            overrideFile: $this->overrideFile,
            openDelimiter: $this->openDelimiter,
            closeDelimiter: $this->closeDelimiter,
            strict: $this->strict,
        );
    }

    /**
     * Scaffold a single file.
     *
     * @throws InvalidArgumentException
     */
    public function scaffoldFile(?string $sourceFile = null, ?string $targetFile = null): bool
    {
        $source = $sourceFile ?? $this->sourceFile;
        $target = $targetFile ?? $this->targetFile;

        if ($source === null || $target === null) {
            throw new InvalidArgumentException('Both source and target files must be specified via fromFile()/toFile() or passed directly.');
        }

        return $this->engine->scaffoldFile(
            sourceFile: $source,
            targetFile: $target,
            tokens: $this->tokens,
            overrideFile: $this->overrideFile,
            force: $this->force,
            dryRun: $this->dryRun,
            openDelimiter: $this->openDelimiter,
            closeDelimiter: $this->closeDelimiter,
            strict: $this->strict,
        );
    }

    /**
     * Scaffold a complete directory tree.
     *
     * @throws InvalidArgumentException
     */
    public function scaffoldTree(): ScaffoldResult
    {
        if ($this->sourceDir === null || $this->targetDir === null) {
            throw new InvalidArgumentException('Both source directory (from) and target directory (to) must be specified for tree scaffolding.');
        }

        return $this->engine->scaffoldTree(
            sourceDir: $this->sourceDir,
            targetDir: $this->targetDir,
            tokens: $this->tokens,
            overrideDir: $this->overrideDir,
            strategy: $this->strategy,
            stubExtension: $this->stubExtension,
            force: $this->force,
            dryRun: $this->dryRun,
            openDelimiter: $this->openDelimiter,
            closeDelimiter: $this->closeDelimiter,
            strict: $this->strict,
        );
    }

    /**
     * Execute scaffolding: automatically delegates to scaffoldFile() or scaffoldTree()
     * based on configured source targets.
     *
     * @throws InvalidArgumentException
     */
    public function scaffold(): ScaffoldResult|bool
    {
        if ($this->sourceFile !== null && $this->targetFile !== null) {
            return $this->scaffoldFile();
        }

        return $this->scaffoldTree();
    }
}
