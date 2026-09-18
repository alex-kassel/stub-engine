# StubEngine Architecture & Guide

`AlexKassel\StubEngine\StubEngine` is the root orchestrator and primary public entry point for the `alex-kassel/stub-engine` package. It coordinates the template interpolation, host overlay discovery, and file scaffolding operations behind a lightweight, expressive API.

---

## 1. Architecture Overview

`StubEngine` delegates specialized tasks to focused single-responsibility services:

```
                          StubEngine (Facade / Root Orchestrator)
                                    |
            +-----------------------+-----------------------+
            |                                               |
            v                                               v
    ScaffoldBuilder                                    Scaffolder
 (Fluent Pipeline Chaining)                     (Filesystem & Generation)
            |                                               |
            |                               +---------------+---------------+
            |                               |                               |
            v                               v                               v
     ScaffoldRequest                   (Stubs Map)                    Interpolator
 (Universal Operation DTO)      (Host Overrides & Discovery)      (Tokens & Modifiers JIT)
```

- **`StubEngine`**: High-level coordinator, fluent builder factory (`from()`), string renderer (`renderFile()`), tree and file scaffold executor (`scaffold()`), and modifier gateway (`registerModifier()`).
- **`Scaffolder`**: Handles directory scanning, destination path resolution, host overrides with `Overlay` or `Replace` strategies, strict checks, unresolved token detection, dry runs, and disk writes.
- **`Interpolator`**: JIT single-pass token replacer, custom and built-in case modifiers, parameterized filters, and Blade escape syntax handler.
- **`ScaffoldBuilder`**: Provides a fluid, chainable interface to configure and execute scaffolding operations.

---

## 2. Public API Methods

### `StubEngine::from`

```php
public function from(string $source): ScaffoldBuilder
```

Starts a fluent scaffolding pipeline for a source stub file or directory. Resolves a `ScaffoldBuilder` instance through the Laravel container:

```php
use AlexKassel\StubEngine\Facades\StubEngine;

StubEngine::from(__DIR__ . '/../stubs/Module')
    ->to(app_path('Modules/Billing'))
    ->withTokens(['module' => 'Billing'])
    ->scaffold();
```

---

### `StubEngine::renderFile`

```php
public function renderFile(ScaffoldRequest $request): string
```

Renders a single stub file directly into an in-memory string without modifying files on disk (throws `InvalidArgumentException` if passed a directory):

```php
use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\Facades\StubEngine;

$contents = StubEngine::renderFile(new ScaffoldRequest(
    source: __DIR__ . '/../stubs/config.stub',
    tokens: ['appName' => 'BillingApp'],
));
```

---

### `StubEngine::scaffold`

```php
public function scaffold(ScaffoldRequest $request): ScaffoldResult
```

Executes single-file or directory tree scaffolding according to the provided `ScaffoldRequest` DTO. Automatically determines whether the source is a single file or a directory:

```php
use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\Facades\StubEngine;

$result = StubEngine::scaffold(new ScaffoldRequest(
    source: __DIR__ . '/../stubs',
    target: app_path('Modules/Invoicing'),
    tokens: ['invoice_number' => 'INV-001'],
    force: true,
));
```

---

### `StubEngine::registerModifier`

```php
public function registerModifier(string $name, callable $callback): self
```

Registers a custom runtime token modifier on the underlying `Interpolator` service. See [Token Modifiers Guide](modifiers.md) for full syntax and details:

```php
use AlexKassel\StubEngine\Facades\StubEngine;

StubEngine::registerModifier('shout', fn (string $val): string => strtoupper($val) . '!!!');

// Inside stubs: {{ title | shout }}
```

---

### `StubEngine::extractTokens`

```php
public function extractTokens(string $content, ?string $open = null, ?string $close = null): array
```

Scans raw template content and returns an array of unique unescaped token names (stripped of any modifier directives) found within delimiters. Ignores escaped `@{{ ... }}` expressions:

```php
use AlexKassel\StubEngine\Facades\StubEngine;

$tokens = StubEngine::extractTokens('Hello {{ name | studly }} and {{ role }} but ignore @{{ blade }}');
// Returns: ['name', 'role']
```

---

## 3. Extending with Macros (`use Macroable;`)

`StubEngine` implements Laravel's `Illuminate\Support\Traits\Macroable` trait. This enables host applications and third-party packages to dynamically add custom methods without inheritance or class wrappers.

### `StubEngine::macro()` vs `ScaffoldBuilder::macro()`

| Type | Target | Purpose | Example |
| :--- | :--- | :--- | :--- |
| **Engine Macro** | `StubEngine::macro()` | Defines high-level, one-line generators or custom scaffold actions. | `StubEngine::scaffoldAction(...)` |
| **Builder Macro** | `ScaffoldBuilder::macro()` | Adds intermediate chain steps to the fluent pipeline. | `->forModule('Orders')` |

### Practical Examples

#### Example 1: High-Level Action Generator
Register domain-specific shortcuts inside your application's `AppServiceProvider::boot()`:

```php
namespace App\Providers;

use AlexKassel\StubEngine\Facades\StubEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        StubEngine::macro('scaffoldAction', function (string $name, ?string $namespace = null): bool {
            /** @var \AlexKassel\StubEngine\StubEngine $this */
            $targetDir = app_path('Actions');
            $ns = $namespace ?? 'App\\Actions';

            $result = $this->from(resource_path('stubs/action.stub'))
                ->to("{$targetDir}/{$name}.php")
                ->withTokens([
                    'namespace' => $ns,
                    'class'     => $name,
                ])
                ->scaffold();

            return $result->renderedFiles !== [];
        });
    }
}
```

Now you can invoke this custom macro throughout your codebase or console commands:

```php
use AlexKassel\StubEngine\Facades\StubEngine;

StubEngine::scaffoldAction('ProcessPaymentAction');
```

#### Example 2: Scaffolding a DDD Domain Module
Bundle multiple files into a single reusable macro:

```php
use AlexKassel\StubEngine\Facades\StubEngine;

StubEngine::macro('scaffoldDomainModule', function (string $moduleName) {
    /** @var \AlexKassel\StubEngine\StubEngine $this */
    return $this->from(base_path('stubs/domain-module'))
        ->to(app_path("Domain/{$moduleName}"))
        ->withTokens([
            'module'       => $moduleName,
            'module_snake' => str($moduleName)->snake()->toString(),
            'module_slug'  => str($moduleName)->kebab()->toString(),
        ])
        ->scaffold();
});

// One-line module scaffold:
$result = StubEngine::scaffoldDomainModule('Billing');
```

---

## 4. Laravel Container & Dependency Injection

`StubEngine` and its core dependencies are automatically registered as **singletons** via `StubEngineServiceProvider`:

```php
use AlexKassel\StubEngine\StubEngine;

class MakeModuleCommand extends Command
{
    public function __construct(
        protected StubEngine $engine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->engine->from(...)->to(...)->scaffold();

        return self::SUCCESS;
    }
}
```

Because `Interpolator` and `Scaffolder` are also singletons in the Laravel container, modifiers registered via `StubEngine::registerModifier()` or through `AppServiceProvider` persist across all scaffolding and rendering calls during the request lifecycle.
