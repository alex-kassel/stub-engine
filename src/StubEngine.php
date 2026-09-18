<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine;

use AlexKassel\StubEngine\Builders\ScaffoldBuilder;
use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Services\Interpolator;
use AlexKassel\StubEngine\Services\Scaffolder;
use Illuminate\Support\Traits\Macroable;

class StubEngine
{
    use Macroable;

    public function __construct(
        protected Interpolator $interpolator,
        protected Scaffolder $scaffolder,
    ) {}

    /**
     * Start scaffolding from a source file or directory.
     */
    public function from(string $source): ScaffoldBuilder
    {
        return app(ScaffoldBuilder::class)->from($source);
    }

    /**
     * Register a custom token modifier callback.
     *
     * @param  callable(string, mixed...): string  $callback
     */
    public function registerModifier(string $name, callable $callback): self
    {
        $this->interpolator->registerModifier($name, $callback);

        return $this;
    }

    /**
     * Render a stub file into a string with token replacements.
     */
    public function renderFile(ScaffoldRequest $request): string
    {
        return $this->scaffolder->renderFile($request);
    }

    /**
     * Execute scaffolding for either a single file or a complete directory tree.
     */
    public function scaffold(ScaffoldRequest $request): ScaffoldResult
    {
        return $this->scaffolder->scaffold($request);
    }

    /**
     * Scan content and return any unique token names found within delimiters.
     *
     * @return array<int, string>
     */
    public function extractTokens(string $content, ?string $open = null, ?string $close = null): array
    {
        return $this->interpolator->extractTokens($content, $open, $close);
    }
}
