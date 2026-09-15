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

Package generators, module creators, and CLI scaffolding tools repeatedly reinvent the exact same low-level filesystem logic:
1. Navigating nested directory structures.
2. Replacing placeholder tokens in file contents.
3. Replacing placeholder tokens in **directory and file names** (e.g. `src/{{ ServiceProvider }}.php.stub`).
4. Stripping template extensions (`.stub`).
5. Cascading and resolving consumer host overrides when templates are published.

**StubEngine** extracts this entire lifecycle into a standalone, lightweight, zero-bloat service. Whether you build microservice generators, DDD module makers, or package development toolkits, **StubEngine** gives you a standardized, battle-tested scaffolding engine in a single declarative method call.

---

## Key Features

* **Tree-Preserving Scaffolding:** Mirrors full directory structures from your stubs into destination paths with zero flattening.
* **Dual-Axis Token Interpolation:** Replaces tokens in both file contents AND file/directory paths simultaneously.
* **Seamless Host Overrides:** Automatically checks for published host overrides first (e.g. `stubs/my-generator/`) and falls back to package defaults.
* **Extension Stripping:** Automatically removes `.stub` extensions (or any custom extension) during generation.
* **Pure Decoupled Design:** Built directly on `Illuminate\Filesystem\Filesystem`. Usable in Laravel service providers, console commands, standalone CLI tools, or background jobs.
* **Comprehensive Results:** Returns a typed `ScaffoldResult` DTO with rendered file paths, total counts, and override detection.

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

Organize your stubs directory keeping the natural folder hierarchy:

```
my-package/stubs/
├── composer.json.stub
├── src/
│   └── {{ ClassName }}.php.stub
└── tests/
    └── {{ ClassName }}Test.php.stub
```

Execute scaffolding in your command or service:

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

### 2. Enabling Stubs Publishing in Service Providers

To allow host applications to customize your package's stubs, register a publishable tag:

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

The returned `ScaffoldResult` object provides detailed metadata for diagnostics and logging:

```php
$result = $engine->scaffoldTree(...);

// Full path of the template directory utilized
$sourceUsed = $result->sourceDir;

// True if host overrides were used; false if package defaults were used
$isCustomized = $result->isOverride;

// Array of relative file paths that were generated (e.g. ['src/MyTool.php', 'composer.json'])
$files = $result->renderedFiles;

// Total number of written files
$total = $result->fileCount;
```

---

## API Reference

### `StubEngine::scaffoldTree`

```php
public function scaffoldTree(
    string $sourceDir,
    string $targetDir,
    array $tokens,
    ?string $overrideDir = null,
    string $stubExtension = '.stub',
): ScaffoldResult
```

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$sourceDir` | `string` | *(required)* | Path to the default fallback stubs directory. |
| `$targetDir` | `string` | *(required)* | Target directory where rendered files will be generated. |
| `$tokens` | `array<string, string>` | *(required)* | Key-value dictionary of placeholder tokens and their replacements. |
| `$overrideDir` | `?string` | `null` | Optional host override directory. If it exists on disk, it takes full precedence. |
| `$stubExtension` | `string` | `'.stub'` | Extension stripped from output filenames. Set to `''` to keep original filenames. |

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
