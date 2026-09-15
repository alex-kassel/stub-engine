<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Services;

use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StubEngine
{
    public const DEFAULT_STUB_EXTENSION = '.stub';

    public const DEFAULT_TOKEN_OPEN_DELIMITER = '{{';

    public const DEFAULT_TOKEN_CLOSE_DELIMITER = '}}';

    public const DEFAULT_MODIFIER_SEPARATOR = '|';

    public const CONFIG_DELIMITERS_KEY = 'delimiters';

    public const CONFIG_OPEN_DELIMITER_KEY = 'delimiters.open';

    public const CONFIG_CLOSE_DELIMITER_KEY = 'delimiters.close';

    public const CONFIG_GLOBAL_TOKENS_KEY = 'global_tokens';

    public const DEFAULT_IGNORED_FILES = [
        '.DS_Store',
        'Thumbs.db',
        '.gitkeep',
    ];

    /** @var array<string, callable(string): string> */
    protected array $customModifiers = [];

    /**
     * @param  Filesystem  $files  Filesystem repository
     * @param  array<string, mixed>  $config  Stub engine configuration array
     */
    public function __construct(
        protected Filesystem $files = new Filesystem,
        protected array $config = [],
    ) {}

    /**
     * Register a custom token modifier callback.
     *
     * @param  callable(string): string  $callback
     */
    public function registerModifier(string $name, callable $callback): self
    {
        $this->customModifiers[$name] = $callback;

        return $this;
    }

    /**
     * Validate that a destination path stays strictly within the target directory.
     *
     * @throws InvalidArgumentException
     */
    public function ensureWithinTargetDirectory(string $targetDir, string $destination): void
    {
        $normalizedTarget = rtrim(str_replace('\\', '/', $targetDir), '/');
        $normalizedDest = str_replace('\\', '/', $destination);

        $destParts = explode('/', $normalizedDest);
        $canonicalDest = [];

        foreach ($destParts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (empty($canonicalDest)) {
                    throw new InvalidArgumentException("Target path [{$destination}] attempts directory traversal outside target directory [{$targetDir}].");
                }
                array_pop($canonicalDest);
            } else {
                $canonicalDest[] = $part;
            }
        }

        $targetParts = array_values(array_filter(explode('/', $normalizedTarget), fn (string $p): bool => $p !== ''));
        $canonicalTarget = [];

        foreach ($targetParts as $part) {
            if ($part === '..') {
                array_pop($canonicalTarget);
            } elseif ($part !== '.') {
                $canonicalTarget[] = $part;
            }
        }

        $targetPrefix = (str_starts_with($normalizedTarget, '/') ? '/' : '').implode('/', $canonicalTarget);
        $destResolved = (str_starts_with($normalizedDest, '/') ? '/' : '').implode('/', $canonicalDest);

        if (! str_starts_with($destResolved, $targetPrefix.'/') && $destResolved !== $targetPrefix) {
            throw new InvalidArgumentException("Target path [{$destination}] attempts directory traversal outside target directory [{$targetDir}].");
        }
    }

    /**
     * Scan content and return any unresolved token placeholders.
     *
     * @return array<int, string>
     */
    public function findUnresolvedTokens(
        string $content,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
    ): array {
        [$open, $close] = $this->resolveDelimiters($openDelimiter, $closeDelimiter);
        $escapedOpen = preg_quote($open, '/');
        $escapedClose = preg_quote($close, '/');

        $pattern = '/'.$escapedOpen.'\s*([^'.$escapedClose.'\s]+(?:\s*\|\s*[^'.$escapedClose.'\s]+)?)\s*'.$escapedClose.'/';
        if (preg_match_all($pattern, $content, $matches)) {
            return array_values(array_unique($matches[0]));
        }

        return [];
    }

    /**
     * Resolve the effective open and close delimiters just-in-time.
     *
     * @return array{0: string, 1: string}
     */
    public function resolveDelimiters(?string $open = null, ?string $close = null): array
    {
        $configOpen = (string) (data_get($this->config, self::CONFIG_OPEN_DELIMITER_KEY)
            ?: data_get($this->config, 'stub-engine.delimiters.open', ''));
        $configClose = (string) (data_get($this->config, self::CONFIG_CLOSE_DELIMITER_KEY)
            ?: data_get($this->config, 'stub-engine.delimiters.close', ''));

        $effectiveOpen = $open ?: ($configOpen ?: self::DEFAULT_TOKEN_OPEN_DELIMITER);
        $effectiveClose = $close ?: ($configClose ?: self::DEFAULT_TOKEN_CLOSE_DELIMITER);

        return [$effectiveOpen, $effectiveClose];
    }

    /**
     * Interpolate token placeholders using a pre-compiled replacement dictionary.
     *
     * @param  string  $content  Template content or file/directory path
     * @param  array<string, string>  $compiledTokens  Pre-compiled replacements sorted by key length
     */
    public function interpolateWithMap(string $content, array $compiledTokens): string
    {
        return str_replace(array_keys($compiledTokens), array_values($compiledTokens), $content);
    }

    /**
     * Interpolate token placeholders in a given string, supporting case modifiers,
     * custom/configurable delimiters, and collision safety by key length ordering.
     *
     * @param  string  $content  Template content or file/directory path
     * @param  array<string, string>  $tokens  Key-value token replacements
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     */
    public function interpolate(
        string $content,
        array $tokens,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
    ): string {
        $resolved = $this->resolveTokens($tokens, $openDelimiter, $closeDelimiter);

        return $this->interpolateWithMap($content, $resolved);
    }

    /**
     * Resolve and expand token placeholders with case and formatting modifiers,
     * merging global config tokens and sorting by key length in descending order.
     *
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    public function resolveTokens(
        array $tokens,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
    ): array {
        [$open, $close] = $this->resolveDelimiters($openDelimiter, $closeDelimiter);

        $globalTokens = (array) (data_get($this->config, self::CONFIG_GLOBAL_TOKENS_KEY)
            ?: data_get($this->config, 'stub-engine.global_tokens', []));
        $mergedTokens = array_merge($globalTokens, $tokens);

        $expanded = [];

        foreach ($mergedTokens as $key => $value) {
            $strValue = (string) $value;

            $cleanKey = trim(str_replace([$open, $close], '', $key));
            if ($cleanKey === '') {
                continue;
            }

            // If the key originally contained delimiters or non-alphanumeric wrapper, preserve direct replacement
            if ($key !== $cleanKey || ! preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                $expanded[$key] = $strValue;
            }

            $expanded[$open.' '.$cleanKey.' '.$close] = $strValue;
            $expanded[$open.$cleanKey.$close] = $strValue;

            // Standard case & string formatting modifiers
            $modifiers = [
                'studly' => Str::studly($strValue),
                'camel' => Str::camel($strValue),
                'kebab' => Str::kebab($strValue),
                'snake' => Str::snake($strValue),
                'lower' => Str::lower($strValue),
                'upper' => Str::upper($strValue),
                'title' => Str::title($strValue),
                'plural' => Str::plural($strValue),
                'singular' => Str::singular($strValue),
            ];

            foreach ($this->customModifiers as $customModName => $customCallback) {
                $modifiers[$customModName] = (string) $customCallback($strValue);
            }

            foreach ($modifiers as $mod => $modVal) {
                $expanded[$open.' '.$cleanKey.self::DEFAULT_MODIFIER_SEPARATOR.$mod.' '.$close] = $modVal;
                $expanded[$open.$cleanKey.self::DEFAULT_MODIFIER_SEPARATOR.$mod.$close] = $modVal;
                $expanded[$open.' '.$cleanKey.' '.self::DEFAULT_MODIFIER_SEPARATOR.' '.$mod.' '.$close] = $modVal;
            }
        }

        uksort($expanded, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $expanded;
    }

    /**
     * Render a single stub file into a string with token replacements.
     *
     * @param  string  $sourceFile  Default fallback stub file path
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideFile  Optional host override file (takes priority if it exists)
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     * @param  bool  $strict  Whether to throw an exception if unresolved tokens remain
     *
     * @throws InvalidArgumentException
     */
    public function renderFile(
        string $sourceFile,
        array $tokens,
        ?string $overrideFile = null,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
        bool $strict = false,
    ): string {
        $isOverride = $overrideFile !== null && $this->files->isFile($overrideFile);
        $effectiveSource = $isOverride ? $overrideFile : $sourceFile;

        if (! $this->files->isFile($effectiveSource)) {
            throw new InvalidArgumentException("Stub file not found: [{$effectiveSource}].");
        }

        $content = (string) $this->files->get($effectiveSource);
        $rendered = $this->interpolate($content, $tokens, $openDelimiter, $closeDelimiter);

        if ($strict) {
            $unresolved = $this->findUnresolvedTokens($rendered, $openDelimiter, $closeDelimiter);
            if ($unresolved !== []) {
                throw new InvalidArgumentException("Unresolved tokens in stub file [{$effectiveSource}]: ".implode(', ', $unresolved));
            }
        }

        return $rendered;
    }

    /**
     * Scaffold a single stub file to a target destination with token replacements.
     *
     * @param  string  $sourceFile  Default fallback stub file path
     * @param  string  $targetFile  Target file path to generate
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideFile  Optional host override file (takes priority if it exists)
     * @param  bool  $force  Whether to overwrite an existing target file
     * @param  bool  $dryRun  Whether to simulate without writing to disk
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     * @param  bool  $strict  Whether to throw an exception if unresolved tokens remain
     * @return bool True if created/overwritten, false if skipped because it already exists
     *
     * @throws InvalidArgumentException
     */
    public function scaffoldFile(
        string $sourceFile,
        string $targetFile,
        array $tokens,
        ?string $overrideFile = null,
        bool $force = false,
        bool $dryRun = false,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
        bool $strict = false,
    ): bool {
        $this->ensureWithinTargetDirectory(dirname($targetFile), $targetFile);

        if ($this->files->exists($targetFile) && ! $force) {
            return false;
        }

        $rendered = $this->renderFile($sourceFile, $tokens, $overrideFile, $openDelimiter, $closeDelimiter, $strict);

        if (! $dryRun) {
            $this->files->ensureDirectoryExists(dirname($targetFile));
            $this->files->put($targetFile, $rendered);
        }

        return true;
    }

    /**
     * Scaffold a complete directory tree from stubs with configurable strategy
     * (Overlay cascading merge vs Replace all-or-nothing), dual-axis token replacements,
     * safe overwrite/simulation controls, and protection for raw non-stub assets.
     *
     * @param  string  $sourceDir  Default fallback stubs directory
     * @param  string  $targetDir  Target directory where files will be created
     * @param  array<string, string>  $tokens  Token replacements (e.g. ['{{ name }}' => 'Value'])
     * @param  string|null  $overrideDir  Optional host override directory
     * @param  OverrideStrategy  $strategy  Override strategy (Overlay for cascading merge, Replace for complete directory substitution)
     * @param  string  $stubExtension  Extension to strip from output files
     * @param  bool  $force  Whether to overwrite existing target files
     * @param  bool  $dryRun  Whether to simulate without writing to disk
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     * @param  bool  $strict  Whether to throw an exception if unresolved tokens remain
     *
     * @throws InvalidArgumentException
     */
    public function scaffoldTree(
        string $sourceDir,
        string $targetDir,
        array $tokens,
        ?string $overrideDir = null,
        OverrideStrategy $strategy = OverrideStrategy::Overlay,
        string $stubExtension = self::DEFAULT_STUB_EXTENSION,
        bool $force = false,
        bool $dryRun = false,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
        bool $strict = false,
    ): ScaffoldResult {
        if (! $this->files->isDirectory($sourceDir)) {
            throw new InvalidArgumentException("Stubs source directory not found: [{$sourceDir}].");
        }

        $hasOverrideDir = $overrideDir !== null && $this->files->isDirectory($overrideDir);

        /** @var array<string, array{sourcePath: string, isOverride: bool}> $stubsMap */
        $stubsMap = [];

        if ($hasOverrideDir && $strategy === OverrideStrategy::Replace) {
            foreach ($this->files->allFiles($overrideDir) as $file) {
                if (in_array($file->getFilename(), self::DEFAULT_IGNORED_FILES, true)) {
                    continue;
                }
                $relPath = str_replace('\\', '/', $file->getRelativePathname());
                $stubsMap[$relPath] = [
                    'sourcePath' => $file->getPathname(),
                    'isOverride' => true,
                ];
            }
        } else {
            foreach ($this->files->allFiles($sourceDir) as $file) {
                if (in_array($file->getFilename(), self::DEFAULT_IGNORED_FILES, true)) {
                    continue;
                }
                $relPath = str_replace('\\', '/', $file->getRelativePathname());
                $stubsMap[$relPath] = [
                    'sourcePath' => $file->getPathname(),
                    'isOverride' => false,
                ];
            }

            if ($hasOverrideDir) {
                foreach ($this->files->allFiles($overrideDir) as $file) {
                    if (in_array($file->getFilename(), self::DEFAULT_IGNORED_FILES, true)) {
                        continue;
                    }
                    $relPath = str_replace('\\', '/', $file->getRelativePathname());
                    $stubsMap[$relPath] = [
                        'sourcePath' => $file->getPathname(),
                        'isOverride' => true,
                    ];
                }
            }
        }

        // Pre-compile token replacement map ONCE for the entire tree
        $compiledTokens = $this->resolveTokens($tokens, $openDelimiter, $closeDelimiter);

        $createdFiles = [];
        $overwrittenFiles = [];
        $skippedFiles = [];
        $overrideFiles = [];
        $rawCopiedFiles = [];
        $unresolvedTokensMap = [];
        $extLen = strlen($stubExtension);

        foreach ($stubsMap as $relPath => $stubInfo) {
            $isStub = $stubExtension !== '' && str_ends_with($relPath, $stubExtension);
            $targetRelPath = $this->interpolateWithMap($relPath, $compiledTokens);

            if ($isStub) {
                $targetRelPath = substr($targetRelPath, 0, -$extLen);
            }

            $destination = rtrim($targetDir, '/\\').'/'.$targetRelPath;
            $this->ensureWithinTargetDirectory($targetDir, $destination);

            $exists = $this->files->exists($destination);

            if ($exists && ! $force) {
                $skippedFiles[] = $targetRelPath;
                if ($stubInfo['isOverride']) {
                    $overrideFiles[] = $targetRelPath;
                }

                continue;
            }

            if ($exists) {
                $overwrittenFiles[] = $targetRelPath;
            } else {
                $createdFiles[] = $targetRelPath;
            }

            if ($stubInfo['isOverride']) {
                $overrideFiles[] = $targetRelPath;
            }

            if ($isStub) {
                $rawContent = (string) $this->files->get($stubInfo['sourcePath']);
                $renderedContent = $this->interpolateWithMap($rawContent, $compiledTokens);

                $unresolved = $this->findUnresolvedTokens($renderedContent, $openDelimiter, $closeDelimiter);
                if ($unresolved !== []) {
                    $unresolvedTokensMap[$targetRelPath] = $unresolved;
                    if ($strict) {
                        throw new InvalidArgumentException("Unresolved tokens in [{$targetRelPath}]: ".implode(', ', $unresolved));
                    }
                }

                if (! $dryRun) {
                    $this->files->ensureDirectoryExists(dirname($destination));
                    $this->files->put($destination, $renderedContent);
                }
            } else {
                // Raw asset: copy directly without text replacement
                $rawCopiedFiles[] = $targetRelPath;

                if (! $dryRun) {
                    $this->files->ensureDirectoryExists(dirname($destination));
                    $this->files->copy($stubInfo['sourcePath'], $destination);
                }
            }
        }

        return new ScaffoldResult(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            createdFiles: $createdFiles,
            overwrittenFiles: $overwrittenFiles,
            skippedFiles: $skippedFiles,
            overrideFiles: $overrideFiles,
            rawCopiedFiles: $rawCopiedFiles,
            unresolvedTokens: $unresolvedTokensMap,
            dryRun: $dryRun,
            strategy: $strategy,
        );
    }
}
