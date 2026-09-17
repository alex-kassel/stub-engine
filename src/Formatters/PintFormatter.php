<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Formatters;

use AlexKassel\StubEngine\Exceptions\FormatterNotFoundException;
use Illuminate\Process\Factory as ProcessFactory;
use RuntimeException;

class PintFormatter
{
    public const DEFAULT_PINT_PATH = 'vendor/bin/pint';

    public const DEFAULT_STRICT = false;

    public const DEFAULT_TIMEOUT = 60;

    public const DEFAULT_CHUNK_SIZE = 50;

    public function __construct(
        protected ProcessFactory $process = new ProcessFactory,
    ) {}

    /**
     * Access the underlying process factory.
     */
    public function process(): ProcessFactory
    {
        return $this->process;
    }

    /**
     * Set a custom process factory instance.
     */
    public function setProcessFactory(ProcessFactory $process): self
    {
        $this->process = $process;

        return $this;
    }

    /**
     * Resolve the Laravel Pint executable path from custom input, base_path, or current directory.
     */
    public function resolveBinary(?string $customBinary = null): ?string
    {
        if ($customBinary !== null) {
            return file_exists($customBinary) ? $customBinary : null;
        }

        $basePath = base_path(self::DEFAULT_PINT_PATH);
        if (file_exists($basePath)) {
            return $basePath;
        }

        if (file_exists(self::DEFAULT_PINT_PATH)) {
            return self::DEFAULT_PINT_PATH;
        }

        return null;
    }

    /**
     * Format the specified list of files with Laravel Pint.
     *
     * @param  array<int, string>  $files  List of absolute or relative file paths
     * @param  bool  $strict  Whether to throw an exception when the binary is missing or formatting fails
     * @param  string|null  $customBinary  Optional custom binary path
     * @return array{formatted: bool, warnings: array<int, string>}
     *
     * @throws FormatterNotFoundException
     * @throws RuntimeException
     */
    public function format(
        array $files,
        bool $strict = self::DEFAULT_STRICT,
        ?string $customBinary = null,
    ): array {
        $chunks = collect($files)
            ->filter(static fn (string $path): bool => str_ends_with($path, '.php') && file_exists($path))
            ->chunk(self::DEFAULT_CHUNK_SIZE);

        if ($chunks->isEmpty()) {
            return [
                'formatted' => false,
                'warnings' => [],
            ];
        }

        $binary = $this->resolveBinary($customBinary);

        if ($binary === null) {
            $expectedPath = $customBinary ?? self::DEFAULT_PINT_PATH;

            if ($strict) {
                throw FormatterNotFoundException::forBinary($expectedPath);
            }

            return [
                'formatted' => false,
                'warnings' => [
                    "Laravel Pint binary not found at [{$expectedPath}]. Generated PHP files were saved without code formatting.",
                ],
            ];
        }

        $warnings = [];
        $allSuccessful = true;

        foreach ($chunks as $chunk) {
            $processResult = $this->process->timeout(self::DEFAULT_TIMEOUT)->run([$binary, ...$chunk->all()]);

            if (! $processResult->successful()) {
                $allSuccessful = false;
                $errorMsg = "Laravel Pint formatting exited with code {$processResult->exitCode()}: ".trim($processResult->errorOutput());

                if ($strict) {
                    throw new RuntimeException($errorMsg);
                }

                $warnings[] = $errorMsg;
                break;
            }
        }

        return [
            'formatted' => $allSuccessful,
            'warnings' => $warnings,
        ];
    }
}
