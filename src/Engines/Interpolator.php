<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Engines;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Interpolator
{
    public const DEFAULT_TOKEN_OPEN_DELIMITER = '{{';

    public const DEFAULT_TOKEN_CLOSE_DELIMITER = '}}';

    public const DEFAULT_MODIFIER_SEPARATOR = '|';

    public const DEFAULT_DATE_FORMAT = 'Y-m-d';

    public const DEFAULT_LIMIT_LENGTH = 100;

    public const DEFAULT_LIMIT_END = '...';

    public const CONFIG_DELIMITERS_KEY = 'delimiters';

    public const CONFIG_OPEN_DELIMITER_KEY = 'delimiters.open';

    public const CONFIG_CLOSE_DELIMITER_KEY = 'delimiters.close';

    public const CONFIG_GLOBAL_TOKENS_KEY = 'global_tokens';

    /** @var array<string, callable(string, ...mixed): string> */
    protected array $customModifiers = [];

    /**
     * @param  array<string, mixed>  $config  Stub engine configuration array
     */
    public function __construct(
        protected array $config = [],
    ) {}

    /**
     * Register a custom token modifier callback.
     *
     * @param  callable(string, ...mixed): string  $callback
     */
    public function registerModifier(string $name, callable $callback): self
    {
        $this->customModifiers[$name] = $callback;

        return $this;
    }

    /**
     * Resolve the effective open and close delimiters just-in-time.
     *
     * @return array{0: string, 1: string}
     */
    public function resolveDelimiters(?string $open = null, ?string $close = null): array
    {
        $configOpen = (string) data_get($this->config, self::CONFIG_OPEN_DELIMITER_KEY, '');
        $configClose = (string) data_get($this->config, self::CONFIG_CLOSE_DELIMITER_KEY, '');

        $effectiveOpen = $open ?: ($configOpen ?: self::DEFAULT_TOKEN_OPEN_DELIMITER);
        $effectiveClose = $close ?: ($configClose ?: self::DEFAULT_TOKEN_CLOSE_DELIMITER);

        return [$effectiveOpen, $effectiveClose];
    }

    /**
     * Merge global config tokens with runtime tokens and normalize keys for interpolation.
     *
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    public function getMergedTokens(array $tokens, ?string $open = null, ?string $close = null): array
    {
        [$effectiveOpen, $effectiveClose] = $this->resolveDelimiters($open, $close);

        $globalTokens = (array) data_get($this->config, self::CONFIG_GLOBAL_TOKENS_KEY, []);

        $rawMerged = array_merge($globalTokens, $tokens);
        $normalized = [];

        foreach ($rawMerged as $key => $value) {
            $strValue = (string) $value;
            $cleanKey = trim(str_replace([$effectiveOpen, $effectiveClose], '', (string) $key));

            if ($cleanKey !== '') {
                $normalized[$cleanKey] = $strValue;
            }

            $normalized[(string) $key] = $strValue;
        }

        return $normalized;
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

        // Match tokens not preceded by Blade escape prefix (@)
        $pattern = '/(?<!@)'.$escapedOpen.'((?:(?!'.$escapedOpen.'|'.$escapedClose.').)+)'.$escapedClose.'/s';
        if (preg_match_all($pattern, $content, $matches)) {
            return array_values(array_unique($matches[0]));
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
            $parts = array_map('trim', explode(self::DEFAULT_MODIFIER_SEPARATOR, $rawInside));
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
     * Apply a single modifier directive (with optional parameters) to a string value.
     */
    public function applyModifier(string $value, string $modifierDirective): string
    {
        $parts = explode(':', $modifierDirective, 2);
        $name = trim($parts[0]);
        $argString = $parts[1] ?? null;
        $args = $argString !== null ? array_map('trim', explode(',', $argString)) : [];

        if (isset($this->customModifiers[$name])) {
            return (string) ($this->customModifiers[$name])($value, ...$args);
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
            'default' => $value === '' ? ($args[0] ?? '') : $value,
            'format', 'date' => $this->formatDate($value, $args[0] ?? self::DEFAULT_DATE_FORMAT),
            'replace' => isset($args[0]) && $args[0] !== '' ? str_replace($args[0], $args[1] ?? '', $value) : $value,
            'limit' => Str::limit($value, isset($args[0]) && is_numeric($args[0]) ? (int) $args[0] : self::DEFAULT_LIMIT_LENGTH, $args[1] ?? self::DEFAULT_LIMIT_END),
            'wrap' => ($args[0] ?? '').$value.($args[1] ?? ($args[0] ?? '')),
            'trim' => $value !== '' && isset($args[0]) ? trim($value, $args[0]) : trim($value),
            default => $value,
        };
    }

    /**
     * Format a date string or timestamp using Carbon.
     */
    protected function formatDate(string $value, string $format): string
    {
        if ($value === '') {
            return '';
        }

        $carbon = is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse($value);

        return $carbon->format($format);
    }

    /**
     * Interpolate token placeholders in a given string, supporting case modifiers,
     * custom/configurable delimiters, whitespace tolerance, modifier chaining,
     * and Blade verbatim escape syntax (@{{).
     *
     * @param  string  $content  Template content or file/directory path
     * @param  array<string, string>  $tokens  Key-value token replacements
     * @param  string|null  $openDelimiter  Optional runtime open delimiter override
     * @param  string|null  $closeDelimiter  Optional runtime close delimiter override
     * @param  array<int, string>|null  $unresolved  Optional output reference for unresolved placeholders
     */
    public function interpolate(
        string $content,
        array $tokens,
        ?string $openDelimiter = null,
        ?string $closeDelimiter = null,
        ?array &$unresolved = null,
    ): string {
        [$open, $close] = $this->resolveDelimiters($openDelimiter, $closeDelimiter);
        $mergedTokens = $this->getMergedTokens($tokens, $open, $close);

        return $this->interpolateContent($content, $mergedTokens, $open, $close, $unresolved);
    }
}
