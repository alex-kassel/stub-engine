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

* **Single-File Compilation & Scaffolding:**
  * `renderFile()`: Compile an individual stub into a string with token replacements and host override support.
  * `scaffoldFile()`: Write a compiled stub directly to a destination with safe skip/overwrite controls.
* **Tree-Preserving Scaffolding:**
  * `scaffoldTree()`: Mirror nested directory hierarchies from templates into target directories without structure loss.
* **Dual-Axis Token Interpolation:** Replace tokens in both file contents AND file/directory pathnames simultaneously.
* **Seamless Host Overrides:** Automatically checks for host project overrides (e.g. in `stubs/`) before falling back to package defaults.
* **Configurable Extension Stripping:** Automatically removes `.stub` (default: `StubEngine::DEFAULT_STUB_EXTENSION`) or any custom extension from output filenames.
* **Pure Decoupled Design:** Built on `Illuminate\Filesystem\Filesystem`. Usable across console commands, service providers, background jobs, or standalone CLI tools.
* **Typed DTOs:** Returns a typed `ScaffoldResult` object with rendered relative paths, file counts, and override detection.

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

### 2. Allowing Host Stub Overrides via `vendor:publish`

To allow host applications to customize your package's stubs, register publishable assets in your service provider:

```php
namespace Acme\Generator;

use Illuminate\Support\ServiceProvider;

class GeneratorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../stubs' => base_path('stubs/modules'),
            ], 'module-stubs');
        }
    }
}
```

When users run `php artisan vendor:publish --tag=module-stubs`, they can modify the templates in `stubs/modules/`. **StubEngine** will automatically detect those files and prioritize them over package defaults.

### 3. Inspecting Scaffold Results

The `ScaffoldResult` object returned by `scaffoldTree()` provides structured metadata:

```php
$result = $engine->scaffoldTree(...);

// Full path of the template directory utilized
$sourceUsed = $result->sourceDir;

// Target directory where files were scaffolded
$targetDir = $result->targetDir;

// True if host overrides were used; false if package defaults were used
$isCustomized = $result->isOverride;

// Array of relative file paths that were generated (e.g. ['src/MyTool.php', 'composer.json'])
$files = $result->renderedFiles;

// Total number of written files
$total = $result->fileCount;
```

---

## API Reference

### `StubEngine::renderFile`

```php
public function renderFile(
    string $sourceFile,
    array $tokens,
    ?string $overrideFile = null,
): string
```

Renders a single stub file into a string with token replacements.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$sourceFile` | `string` | *(required)* | Path to the default fallback stub file. |
| `$tokens` | `array<string, string>` | *(required)* | Key-value dictionary of placeholder tokens and their replacements. |
| `$overrideFile` | `?string` | `null` | Optional host override file. If present on disk, it takes precedence. |

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

*Returns `true` if the file was written, or `false` if skipped because it already existed.*

---

### `StubEngine::scaffoldTree`

```php
public function scaffoldTree(
    string $sourceDir,
    string $targetDir,
    array $tokens,
    ?string $overrideDir = null,
    string $stubExtension = StubEngine::DEFAULT_STUB_EXTENSION,
): ScaffoldResult
```

Scaffolds a complete directory tree from stubs with dual-axis token replacements and host override resolution.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$sourceDir` | `string` | *(required)* | Path to the default fallback stubs directory. |
| `$targetDir` | `string` | *(required)* | Target directory where rendered files will be generated. |
| `$tokens` | `array<string, string>` | *(required)* | Key-value dictionary of placeholder tokens and their replacements. |
| `$overrideDir` | `?string` | `null` | Optional host override directory. If present on disk, it takes precedence. |
| `$stubExtension` | `string` | `StubEngine::DEFAULT_STUB_EXTENSION` (`'.stub'`) | Extension stripped from output filenames. Pass `''` to preserve extensions. |

*Throws `InvalidArgumentException` if neither the override directory nor the source directory exists.*

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
