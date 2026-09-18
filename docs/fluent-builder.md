# Fluent ScaffoldBuilder & Conventions Guide

This document provides an exhaustive reference for using the **Fluent ScaffoldBuilder** and convention-based host overrides in `alex-kassel/stub-engine`.

---

## 1. Overview & Motivation

Earlier versions of `StubEngine` relied heavily on `scaffoldTree()` accepting up to 11 positional parameters:
```php
// Legacy 11-argument invocation
$engine->scaffoldTree(
    $sourceDir,
    $targetDir,
    $tokens,
    $overrideDir,
    $strategy,
    $stubExtension,
    $force,
    $dryRun,
    $openDelimiter,
    $closeDelimiter,
    $strict
);
```
While functional, this signature was error-prone, unwieldy, and brittle.

`ScaffoldBuilder` replaces this with a fluent, declarative, and extensible pipeline adhering to native Laravel idioms:
```php
StubEngine::from($stubsDir)
    ->to($targetDir)
    ->withTokens(['module' => 'Billing'])
    ->when($withAuth, fn (ScaffoldBuilder $b) => $b->withTokens(['has_auth' => true]))
    ->override($hostOverridesDir)
    ->force()
    ->scaffold();
```

---

## 2. Instantiation & Entry Points

`ScaffoldBuilder` can be created through the Laravel Facade or via Dependency Injection:

### Via Laravel Facade
```php
use AlexKassel\StubEngine\Facades\StubEngine;

// Start directory tree scaffolding
$builder = StubEngine::from('/path/to/stubs');

// Start single file scaffolding
$builder = StubEngine::fromFile('/path/to/Class.php.stub');

// Start blank builder
$builder = StubEngine::newBuilder();
```

### Via Dependency Injection
```php
use AlexKassel\StubEngine\StubEngine;

class MakeModuleCommand extends Command
{
    public function handle(StubEngine $engine): int
    {
        $engine->from(__DIR__ . '/../stubs')
            ->to(app_path('Modules/Billing'))
            ->withTokens(['name' => $this->argument('name')])
            ->scaffold();

        return self::SUCCESS;
    }
}
```

---

## 3. Configuring Sources and Destinations

### Directory Tree Scaffolding
* `from(string $sourceDir)`: Set the source directory containing default stubs and static assets.
* `to(string $targetDir)`: Set the target destination directory where files will be created.

```php
StubEngine::from(__DIR__ . '/../stubs')
    ->to(base_path('packages/acme/crm'))
    ->scaffold();
```

### Single File Scaffolding & Rendering
* `fromFile(string $sourceFile)`: Set the source template stub.
* `toFile(string $targetFile)`: Set the target destination path.

```php
StubEngine::fromFile(__DIR__ . '/../stubs/config.php.stub')
    ->toFile(config_path('my-package.php'))
    ->withTokens(['prefix' => 'api/v1'])
    ->scaffold();
```

---

## 4. Passing Tokens

Tokens are passed as an associative array of key-value replacements using `withTokens(array $tokens)`. Repeated calls merge tokens:

```php
StubEngine::from($sourceDir)
    ->to($targetDir)
    ->withTokens([
        'module'     => 'Order',
        'table'      => 'orders',
        'created_at' => now()->toIso8601String(),
    ])
    ->scaffold();
```

---

## 5. Conditional Scaffolding (`Conditionable`)

`ScaffoldBuilder` incorporates Laravel's `Illuminate\Support\Traits\Conditionable`, enabling dynamic step chaining without breaking fluent expressions:

### `when(value, callback, defaultCallback)`
```php
$builder->when($this->option('with-tests'), function (ScaffoldBuilder $b): void {
    $b->withTokens(['include_tests' => 'true']);
});
```

### `unless(value, callback, defaultCallback)`
```php
$builder->unless($this->option('dry-run'), function (ScaffoldBuilder $b): void {
    $b->force(true);
});
```

---

## 6. Extending with Custom Macros (`Macroable`)

Like core Laravel components, `ScaffoldBuilder` implements `Illuminate\Support\Traits\Macroable`. You can register domain-specific helpers during package boot:

```php
use AlexKassel\StubEngine\Builders\ScaffoldBuilder;

ScaffoldBuilder::macro('forModule', function (string $moduleName): ScaffoldBuilder {
    /** @var ScaffoldBuilder $this */
    return $this->withTokens([
        'module'       => $moduleName,
        'module_snake' => str($moduleName)->snake()->toString(),
        'module_slug'  => str($moduleName)->kebab()->toString(),
    ]);
});

// Usage anywhere in your codebase:
StubEngine::from($stubs)
    ->to(app_path("Modules/{$name}"))
    ->forModule($name)
    ->scaffold();
```

---

## 7. Overrides & Cascading Strategies

### 1. Overlay (Default Cascading Merge)
Default and custom stubs are merged. Files in the host override directory take priority and replace matching default stubs, while stubs unique to the source package remain untouched:
```php
use AlexKassel\StubEngine\Enums\OverrideStrategy;

// Default is Overlay:
$builder->override('/path/to/host/stubs');

// Explicit:
$builder->override('/path/to/host/stubs', OverrideStrategy::Overlay);
```

### 2. Replace (All-or-Nothing Substitution)
Completely substitutes the source directory with the override directory if it exists:
```php
use AlexKassel\StubEngine\Enums\OverrideStrategy;

$builder->override('/path/to/host/stubs', OverrideStrategy::Replace);

// Or via explicit strategy method:
$builder->override('/path/to/host/stubs')->strategy(OverrideStrategy::Replace);
```

---

## 8. Execution Endpoints

| Method | Return Type | Description |
| :--- | :--- | :--- |
| `scaffold()` | `ScaffoldResult` | Executes file or directory tree scaffolding according to the source type. |
| `renderFile()` | `string` | Renders a single stub file into a string in memory without writing to disk. |
| `toRequest()` | `ScaffoldRequest` | Compiles current builder state into an immutable `ScaffoldRequest` DTO. |

### Additional Operational Controls
* `force(bool $force = true)`: Allow overwriting existing files (default: `false`).
* `dryRun(bool $dryRun = true)`: Run simulation mode without touching the filesystem (default: `false`).
* `strict(bool $strict = true)`: Throw an `InvalidArgumentException` if any unresolved placeholder remains (default: `false`).
* `stubExtension(string $extension)`: Change the file extension stripped upon output (default: `'.stub'`).
* `delimiters(string $open, string $close)`: Override token delimiters at runtime (e.g. `<%` and `%>`).
* `ignore(array $files)`: Filter out specific filenames during directory crawling.
* `onProgress(?callable $callback)`: Register a progress hook `fn (string $path, int $index, int $total)` for CLI progress bars.

