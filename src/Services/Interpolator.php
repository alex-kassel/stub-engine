<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Services;

use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stringable;

class Interpolator
{
    /** @var array<string, callable(string): string> */
    protected array $customModifiers = [];

    public function __construct(
        public ?string $open = null,
        public ?string $close = null,
        public ?array $tokens = null,
    ) {
        $this->open ??= (string) config('stub-engine.delimiters.open', '{{');
        $this->close ??= (string) config('stub-engine.delimiters.close', '}}');
        $this->tokens ??= (array) config('stub-engine.global_tokens', []);
    }

    /**
     * Register a custom token modifier callback.
     *
     * @param  callable(string): string  $callback
     */
    public function registerModifier(string $name, callable $callback): self
    {
        $this->customModifiers[trim($name)] = $callback;

        return $this;
    }

    /**
     * Merge global tokens with request tokens and normalize keys for interpolation.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException
     */
    public function getMergedTokens(ScaffoldRequest $request): array
    {
        $open = $request->openDelimiter ?? $this->open;
        $close = $request->closeDelimiter ?? $this->close;

        $rawMerged = array_merge($this->tokens, $request->tokens);
        $normalized = [];

        foreach ($rawMerged as $key => $value) {
            $cleanKey = trim(str_replace([$open, $close], '', (string) $key));

            if ($cleanKey === '') {
                continue;
            }

            if (str_contains($cleanKey, '|')) {
                throw new InvalidArgumentException("Token key [{$key}] cannot contain modifier pipe '|'. Define the base token name and apply modifiers in stubs.");
            }

            if (is_array($value) || (! is_scalar($value) && ! $value instanceof Stringable && $value !== null)) {
                $type = is_object($value) ? get_class($value) : gettype($value);
                throw new InvalidArgumentException("Token [{$key}] must be a string or scalar, [{$type}] given.");
            }

            $normalized[$cleanKey] = (string) $value;
        }

        return $normalized;
    }

    /**
     * Scan content and return any unique token names found within delimiters.
     *
     * @return array<int, string>
     */
    public function extractTokens(
        string $content,
        ?string $open = null,
        ?string $close = null,
    ): array {
        $open ??= $this->open;
        $close ??= $this->close;

        $escapedOpen = preg_quote($open, '/');
        $escapedClose = preg_quote($close, '/');

        // Match tokens not preceded by Blade escape prefix (@)
        $pattern = '/(?<!@)'.$escapedOpen.'((?:(?!'.$escapedOpen.'|'.$escapedClose.').)+)'.$escapedClose.'/s';
        if (preg_match_all($pattern, $content, $matches)) {
            $tokens = [];

            foreach ($matches[1] as $match) {
                $parts = explode('|', trim($match));
                $tokenName = trim($parts[0]);

                if ($tokenName !== '') {
                    $tokens[] = $tokenName;
                }
            }

            return array_values(array_unique($tokens));
        }

        return [];
    }

    /**
     * Single-pass regex interpolation supporting flexible whitespace, modifier chaining,
     * parameterized modifier directives, and Blade verbatim escape prefixes (@{{).
     *
     * @param  string  $content  Template content or file/directory path
     * @param  array<string, string>  $mergedTokens  Normalized token replacements
     * @param  string  $open  Effective open delimiter
     * @param  string  $close  Effective close delimiter
     * @param  array<int, string>|null  $unresolved  Optional output reference for unresolved placeholders
     */
    public function interpolateContent(
        string $content,
        array $mergedTokens,
        string $open,
        string $close,
        ?array &$unresolved = null,
    ): string {
        $escapedOpen = preg_quote($open, '/');
        $escapedClose = preg_quote($close, '/');

        // Pattern matching optional Blade escape prefix (@) followed by delimiters
        $pattern = '/(@)?'.$escapedOpen.'((?:(?!'.$escapedOpen.'|'.$escapedClose.').)+)'.$escapedClose.'/s';

        $unresolvedList = [];

        $result = preg_replace_callback($pattern, function (array $matches) use ($mergedTokens, $open, $close, &$unresolvedList): string {
            $escapePrefix = $matches[1] ?? '';
            $rawInside = trim($matches[2]);

            // If escaped with @, strip the escape character and preserve {{ ... }} verbatim
            if ($escapePrefix === '@') {
                return $open.$matches[2].$close;
            }

            if ($rawInside === '') {
                return $matches[0];
            }

            // Split token identifier from modifier chain by '|'
            $parts = array_map('trim', explode('|', $rawInside));
            $tokenKey = array_shift($parts);

            if (! array_key_exists($tokenKey, $mergedTokens)) {
                $unresolvedList[] = $matches[0];

                return $matches[0];
            }

            $value = $mergedTokens[$tokenKey];

            foreach ($parts as $modifierDirective) {
                if ($modifierDirective === '') {
                    continue;
                }
                $value = $this->applyModifier($value, $modifierDirective);
            }

            return $value;
        }, $content) ?? $content;

        if ($unresolved !== null) {
            $unresolved = array_values(array_unique($unresolvedList));
        }

        return $result;
    }

    /**
     * Apply a single string modifier to a value.
     *
     * @throws InvalidArgumentException
     */
    public function applyModifier(string $value, string $modifier): string
    {
        $name = trim($modifier);

        if (isset($this->customModifiers[$name])) {
            return (string) ($this->customModifiers[$name])($value);
        }

        return match ($name) {
            'studly' => Str::studly($value),
            'camel' => Str::camel($value),
            'kebab' => Str::kebab($value),
            'snake' => Str::snake($value),
            'lower' => Str::lower($value),
            'upper' => Str::upper($value),
            'title' => Str::title($value),
            'plural' => Str::plural($value),
            'singular' => Str::singular($value),
            'trim' => trim($value),
            default => throw new InvalidArgumentException("Unknown token modifier [{$name}]."),
        };
    }

    /**
     * Interpolate token placeholders in a given string.
     *
     * @param  string  $content  Template content or string with placeholders
     * @param  ScaffoldRequest  $request  ScaffoldRequest containing tokens and delimiters
     * @param  array<int, string>|null  $unresolved  Optional output reference for unresolved placeholders
     */
    public function interpolate(
        string $content,
        ScaffoldRequest $request,
        ?array &$unresolved = null,
    ): string {
        $open = $request->openDelimiter ?? $this->open;
        $close = $request->closeDelimiter ?? $this->close;
        $mergedTokens = $this->getMergedTokens($request);

        return $this->interpolateContent($content, $mergedTokens, $open, $close, $unresolved);
    }
}
