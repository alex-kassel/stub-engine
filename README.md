<h1 align="center">⚡ StubEngine</h1>

<p align="center">
  <strong>Hierarchical template and stub scaffolding engine with token interpolation and host overrides for PHP and Laravel.</strong>
</p>

<p align="center">
  <a href="#why-this-exists">Why This Exists</a> •
  <a href="#key-features">Key Features</a> •
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a> •
  <a href="#quickstart">Quickstart</a> •
  <a href="#usage--recipes">Usage & Recipes</a> •
  <a href="#api-reference">API Reference</a> •
  <a href="#testing">Testing</a> •
  <a href="LICENSE.md">License</a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/alex-kassel/stub-engine"><img src="https://img.shields.io/packagist/v/alex-kassel/stub-engine?color=f59e0b&logo=packagist&logoColor=white" alt="Latest Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?logo=laravel&logoColor=white" alt="Laravel Support"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2+-777bb4?logo=php&logoColor=white" alt="PHP Support"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square" alt="License"></a>
</p>

---

## Why This Exists

Generators, module builders, manifest installers, and CLI scaffolding tools repeatedly reinvent low-level filesystem operations:
1. Interpolating token placeholders in file contents.
2. Interpolating token placeholders in **directory and file names** (e.g. `src/{{ ClassName }}.php.stub`).
3. Stripping template extensions (`.stub`).
4. Enabling consumer applications to override default stubs without modifying vendor packages.
5. Handling single-file stubs (e.g. compiling a standalone script or configuration) alongside multi-file directory trees.

**StubEngine** extracts this entire lifecycle into a lightweight, zero-bloat service. Whether you need to render an in-memory string, scaffold an individual file with host overrides, or generate an entire directory hierarchy, **StubEngine** provides a clean, unified API.

---

## Key Features

* **Dual Override Strategies (`Overlay` vs `Replace`):**
  * **`OverrideStrategy::Overlay` (Default):** Cascading file-by-file overlay. If a host project customizes 1 file out of 10, the other 9 package defaults are preserved.
  * **`OverrideStrategy::Replace`:** Complete directory substitution ("all-or-nothing"). Perfect for document bundles, template suites, or thematic assets where a custom template completely replaces the default directory layout.
* **Configurable Delimiters & Zero-Trust Fallbacks:**
  * Globally customize placeholder delimiters (e.g. `<% %>` or `[[ ]]`) via `config/stub-engine.php` to eliminate syntax collisions with Blade (`{{ $var }}`), Vue, Jinja, or bash.
  * Override delimiters on a per-call basis at runtime.
  * Zero-trust resilience: falls back gracefully to `StubEngine::DEFAULT_TOKEN_OPEN_DELIMITER` (`{{`) and `StubEngine::DEFAULT_TOKEN_CLOSE_DELIMITER` (`}}`) even if config is absent or empty.
* **Global & Dynamic Tokens:**
  * Define application-wide global tokens (e.g. `company_name`, `year`, `author`) in `config/stub-engine.php`.
  * Runtime tokens seamlessly merge and take precedence over global tokens.
* **Dual-Axis Token Interpolation:** Replace tokens in both file contents AND file/directory pathnames simultaneously (e.g. `src/<% Module|studly %>.php.stub`).
* **Built-in Token Modifiers & Collision Safety:**
  * Automatic string casing: `studly`, `camel`, `kebab`, `snake`, `lower`, `upper`, `title`, `plural`, and `singular`.
  * Substring collision prevention: longest token keys are replaced first (`{{ item_id }}` before `{{ item }}`).
* **Safe Overwrite & Dry-Run Modes:** Prevent accidental file overwrites (`force: false`) and simulate execution non-destructively for CLI commands (`dryRun: true`).
* **Decoupled Architecture:** Built on `Illuminate\Filesystem\Filesystem`. Usable across console commands, service providers, background jobs, or standalone CLI tools.
* **Rich, Countable DTOs:** Returns a typed `ScaffoldResult` object implementing `\Countable` with granular file status arrays (`createdFiles`, `overwrittenFiles`, `skippedFiles`, `overrideFiles`) and helper inspection methods.

---

## Requirements

* **PHP:** `^8.2` | `^8.3` | `^8.4`
* **Laravel Framework (or Components):** `^11.0` | `^12.0` | `^13.0`
  * `illuminate/filesystem`
  * `illuminate/support`

---

## Installation

Install via Composer:

```bash
composer require alex-kassel/stub-engine
```

If you are using Laravel, the service provider and `StubEngine` facade are automatically registered via package discovery.

---

## Quickstart

### 1. Scaffold a Single File (e.g. CLI Runner or Config)

```php
use AlexKassel\StubEngine\Facades\StubEngine;

$created = StubEngine::scaffoldFile(
    sourceFile: __DIR__ . '/../stubs/runner.stub',
    targetFile: base_path('bin/my-tool'),
    tokens: [
        '{{ runnerName }}' => 'my-tool',
        '{{ manifestPath }}' => 'tool.json',
    ],
    overrideFile: base_path('stubs/runner.stub'), // Optional host override
    force: false, // Skip if target file already exists
);

if ($created) {
    echo "Standalone runner created at bin/my-tool!";
}
```

### 2. Render In-Memory Content from a Stub

```php
use AlexKassel\StubEngine\Facades\StubEngine;

$compiled = StubEngine::renderFile(
    sourceFile: __DIR__ . '/../stubs/config.stub',
    tokens: [
        '{{ appName }}' => 'My Application',
    ],
    overrideFile: base_path('stubs/config.stub'),
);
```

### 3. Scaffold a Complete Directory Tree

Organize your stubs directory keeping the natural folder hierarchy:

```
my-package/stubs/
├── composer.json.stub
├── src/
│   └── {{ ClassName }}.php.stub
└── tests/
    └── {{ ClassName }}Test.php.stub
```

Execute scaffolding:

```php
use AlexKassel\StubEngine\Facades\StubEngine;

$result = StubEngine::scaffoldTree(
    sourceDir: __DIR__ . '/../stubs',
    targetDir: base_path('packages/acme/my-tool'),
    tokens: [
        '{{ vendor }}' => 'acme',
        '{{ package }}' => 'my-tool',
        '{{ ClassName }}' => 'MyTool',
    ],
    overrideDir: base_path('stubs/my-generator'),
);

echo "Rendered {$result->fileCount} files into {$result->targetDir}!";
```

---

## Usage & Recipes

### 1. Dependency Injection in Console Commands

In clean architecture, inject `AlexKassel\StubEngine\Services\StubEngine` directly into your console commands:

```php
namespace Acme\Generator\Console;

use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Console\Command;

class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name}';

    public function __construct(
        protected readonly StubEngine $engine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        $result = $this->engine->scaffoldTree(
            sourceDir: dirname(__DIR__, 2) . '/stubs',
            targetDir: app_path("Modules/{$name}"),
            tokens: [
                '{{ moduleName }}' => $name,
                '{{ namespace }}' => "App\\Modules\\{$name}",
            ],
            overrideDir: base_path('stubs/modules'),
        );

        $source = $result->isOverride ? 'custom host stubs' : 'default stubs';
        $this->info("Module [{$name}] scaffolded successfully using {$source} ({$result->fileCount} files).");

        return self::SUCCESS;
    }
}
```

### 2. Configuration & Global Tokens

Publish the package configuration file to customize delimiters and register application-wide tokens:

```bash
php artisan vendor:publish --tag=stub-engine-config
```

The published `config/stub-engine.php` file:

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Token Delimiters
    |--------------------------------------------------------------------------
    | Customize delimiters to avoid syntax collisions with Blade ({{ $var }}),
    | Vue, Jinja, or bash scripts.
    */
    'delimiters' => [
        'open' => env('STUB_ENGINE_OPEN_DELIMITER', '{{'),
        'close' => env('STUB_ENGINE_CLOSE_DELIMITER', '}}'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Tokens
    |--------------------------------------------------------------------------
    | Shared tokens merged automatically into every scaffolding operation.
    */
    'global_tokens' => [
        'company' => env('STUB_ENGINE_COMPANY_NAME', 'Acme Corp'),
        'year' => date('Y'),
    ],
];
```

### 3. Dual Override Strategies: `Overlay` vs `Replace`

When consumer applications provide custom stubs, choose between two distinct strategies using `OverrideStrategy`:

```php
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Facades\StubEngine;

// Strategy A: Overlay (Default cascading merge)
// If the override directory contains 1 file out of 10, the other 9 package defaults are preserved.
$result = StubEngine::scaffoldTree(
    sourceDir: __DIR__ . '/../stubs',
    targetDir: base_path('app/Modules/Billing'),
    tokens: ['name' => 'Billing'],
    overrideDir: base_path('stubs/modules'),
    strategy: OverrideStrategy::Overlay,
);

// Strategy B: Replace ("All-or-Nothing" complete substitution)
// Ideal for document packages or custom suites where the consumer directory completely replaces the default layout.
$docResult = StubEngine::scaffoldTree(
    sourceDir: __DIR__ . '/../sample_docs',
    targetDir: storage_path('app/client_docs'),
    tokens: ['client' => 'Globex'],
    overrideDir: base_path('stubs/client_docs'),
    strategy: OverrideStrategy::Replace,
);
```

### 4. Custom Delimiters (Preventing Syntax Collisions)

When scaffolding templates that already contain Blade, Vue, or bash syntax, specify custom delimiters at runtime or via config:

```php
$result = StubEngine::scaffoldTree(
    sourceDir: __DIR__ . '/../blade_stubs',
    targetDir: resource_path('views/modules/billing'),
    tokens: ['entity' => 'user profile'],
    openDelimiter: '<%',
    closeDelimiter: '%>',
);
```

In your stubs and file paths, use `<% entity|studly %>` or `<% entity|kebab %>`, while preserving native Blade syntax like `{{ $user->name }}` without interference.

### 5. Built-in Token Case Modifiers

Tokens can be automatically transformed using built-in pipe modifiers in both file contents and file paths:

```php
$result = StubEngine::scaffoldTree(
    sourceDir: __DIR__ . '/../stubs',
    targetDir: base_path('app/Modules/Billing'),
    tokens: [
        'entity' => 'user profile',
    ],
);
```

In any stub file or file path, you can use:
* `{{ entity|studly }}` → `UserProfile`
* `{{ entity|camel }}` → `userProfile`
* `{{ entity|kebab }}` → `user-profile`
* `{{ entity|snake }}` → `user_profile`
* `{{ entity|lower }}` → `user profile`
* `{{ entity|upper }}` → `USER PROFILE`
* `{{ entity|title }}` → `User Profile`
* `{{ entity|plural }}` → `user profiles`
* `{{ entity|singular }}` → `user profile`

### 6. Inspecting Scaffold Results & Dry-Run Mode

The `ScaffoldResult` object implements `\Countable` and provides fine-grained visibility into file operations:

```php
$result = StubEngine::scaffoldTree(
    sourceDir: __DIR__ . '/../stubs',
    targetDir: base_path('packages/acme/my-tool'),
    tokens: ['name' => 'MyTool'],
    overrideDir: base_path('stubs/custom'),
    force: false,   // Skip existing files
    dryRun: true,   // Preview changes without writing to disk
);

// Count of rendered files
echo count($result); // or $result->totalFiles()

// Detailed file categorizations
$created     = $result->createdFiles;     // ['src/MyTool.php']
$overwritten = $result->overwrittenFiles; // []
$skipped     = $result->skippedFiles;     // ['composer.json']
$overridden  = $result->overrideFiles;    // ['src/MyTool.php']

// Status helpers
if ($result->hasOverrides()) {
    echo "Custom host stubs were utilized!";
}

if ($result->hasSkipped()) {
    echo "Some files already existed and were protected from overwriting.";
}

if ($result->isReplace()) {
    echo "Directory was generated using complete Replace strategy.";
}
```

---

## API Reference

### `StubEngine::renderFile`

```php
public function renderFile(
    string $sourceFile,
    array $tokens,
    ?string $overrideFile = null,
    ?string $openDelimiter = null,
    ?string $closeDelimiter = null,
): string
```

Renders a single stub file into a string with token replacements.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$sourceFile` | `string` | *(required)* | Path to the default fallback stub file. |
| `$tokens` | `array<string, string>` | *(required)* | Key-value dictionary of placeholder tokens and their replacements. |
| `$overrideFile` | `?string` | `null` | Optional host override file. If present on disk, it takes precedence. |
| `$openDelimiter` | `?string` | `null` | Optional runtime opening delimiter (falls back to config or `{{`). |
| `$closeDelimiter` | `?string` | `null` | Optional runtime closing delimiter (falls back to config or `}}`). |

*Throws `InvalidArgumentException` if neither the override file nor the source file exists.*

---

### `StubEngine::scaffoldFile`

```php
public function scaffoldFile(
    string $sourceFile,
    string $targetFile,
    array $tokens,
    ?string $overrideFile = null,
    bool $force = false,
    bool $dryRun = false,
    ?string $openDelimiter = null,
    ?string $closeDelimiter = null,
): bool
```

Scaffolds a single stub file to a target destination with token replacements.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$sourceFile` | `string` | *(required)* | Path to the default fallback stub file. |
| `$targetFile` | `string` | *(required)* | Destination path for the rendered file. |
| `$tokens` | `array<string, string>` | *(required)* | Key-value dictionary of placeholder tokens and their replacements. |
| `$overrideFile` | `?string` | `null` | Optional host override file. If present on disk, it takes precedence. |
| `$force` | `bool` | `false` | When `false`, skips existing files. When `true`, overwrites them. |
| `$dryRun` | `bool` | `false` | When `true`, simulates execution without touching disk. |
| `$openDelimiter` | `?string` | `null` | Optional runtime opening delimiter. |
| `$closeDelimiter` | `?string` | `null` | Optional runtime closing delimiter. |

*Returns `true` if the file was written (or simulated), or `false` if skipped because it already existed.*

---

### `StubEngine::scaffoldTree`

```php
public function scaffoldTree(
    string $sourceDir,
    string $targetDir,
    array $tokens,
    ?string $overrideDir = null,
    OverrideStrategy $strategy = OverrideStrategy::Overlay,
    string $stubExtension = StubEngine::DEFAULT_STUB_EXTENSION,
    bool $force = false,
    bool $dryRun = false,
    ?string $openDelimiter = null,
    ?string $closeDelimiter = null,
): ScaffoldResult
```

Scaffolds a complete directory tree from stubs with configurable strategy (`Overlay` vs `Replace`), dual-axis token replacements, and safe overwrite controls.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$sourceDir` | `string` | *(required)* | Path to the default fallback stubs directory. |
| `$targetDir` | `string` | *(required)* | Target directory where rendered files will be generated. |
| `$tokens` | `array<string, string>` | *(required)* | Key-value dictionary of placeholder tokens and their replacements. |
| `$overrideDir` | `?string` | `null` | Optional host override directory. |
| `$strategy` | `OverrideStrategy` | `OverrideStrategy::Overlay` | Override strategy (`Overlay` for cascading merge, `Replace` for total substitution). |
| `$stubExtension` | `string` | `StubEngine::DEFAULT_STUB_EXTENSION` (`'.stub'`) | Extension stripped from output filenames. Pass `''` to preserve extensions. |
| `$force` | `bool` | `false` | When `false`, skips existing files. When `true`, overwrites them. |
| `$dryRun` | `bool` | `false` | When `true`, simulates execution without touching disk. |
| `$openDelimiter` | `?string` | `null` | Optional runtime opening delimiter. |
| `$closeDelimiter` | `?string` | `null` | Optional runtime closing delimiter. |

*Throws `InvalidArgumentException` if the source directory does not exist.*

---

## Testing

Run the test suite using PHPUnit:

```bash
composer test
```

Or via direct PHPUnit binary:

```bash
vendor/bin/phpunit
```

---

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on recent changes.

## Contributing

Contributions are welcome! Please review [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
