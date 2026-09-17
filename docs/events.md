# Lifecycle Events Guide

`alex-kassel/stub-engine` dispatches native Laravel lifecycle events throughout the scaffolding pipeline. This allows commands, host applications, and third-party packages to monitor generation progress, render interactive progress bars, inspect generated files, or modify file content before it is committed to disk.

---

## 1. Available Events

`StubEngine` employs a clean, two-event lifecycle separating directory tree scaffolding from individual file operations. Tree scaffolding dispatches coarse-grained lifecycle events, avoiding the overhead of firing thousands of micro-events during bulk file generation.

| Event | Class | Scope | Timing | Key Capabilities |
| :--- | :--- | :--- | :--- | :--- |
| **`TreeScaffolding`** | `AlexKassel\StubEngine\Events\TreeScaffolding` | Tree Scaffolding | Dispatched **before** directory tree scaffolding begins | Pre-flight initialization, auditing paths and dry-run status |
| **`TreeScaffolded`** | `AlexKassel\StubEngine\Events\TreeScaffolded` | Tree Scaffolding | Dispatched **after** directory tree scaffolding finishes | Complete audit summary via `ScaffoldResult`, logging, notifications |
| **`FileScaffolding`** | `AlexKassel\StubEngine\Events\FileScaffolding` | Single File Scaffolding | Dispatched **before** a standalone file is written | Inspect metadata, modify content before writing, or cancel creation (`skip()`) |
| **`FileScaffolded`** | `AlexKassel\StubEngine\Events\FileScaffolded` | Single File Scaffolding | Dispatched **after** a standalone file has been written | Post-creation hooks, audit logging |

---

## 2. Event Payloads & Properties

### `TreeScaffolding`
Dispatched once before tree scaffolding starts:
* `string $sourceDir`: Source template directory.
* `string $targetDir`: Target destination directory.
* `?string $overrideDir`: Optional host override directory.
* `bool $dryRun`: Whether execution is running in simulation mode.

---

### `TreeScaffolded`
Dispatched once when directory tree scaffolding completes:
* `ScaffoldResult $result`: Detailed DTO containing lists of `createdFiles`, `overwrittenFiles`, `skippedFiles`, `overrideFiles`, `rawCopiedFiles`, and `unresolvedTokens`.

---

### `FileScaffolding`
Dispatched before an individual standalone stub file is written via `scaffoldFile()`:
* `string $destination`: Target destination path.
* `string $relativePath`: Relative or basename path.
* `string $content`: The interpolated content about to be written.
* `bool $isOverride`: Whether the stub originates from a host override.
* `bool $isRawCopy`: Whether this file is a raw non-stub asset.
* `bool $dryRun`: Whether execution is in simulation mode.
* `bool $shouldSkip`: If set to `true`, writing this file is cancelled.

#### Methods:
* `skip(): self`: Cancels creation of the file.
* `setContent(string $content): self`: Replaces content before writing to disk.

---

### `FileScaffolded`
Dispatched after an individual standalone file has been written via `scaffoldFile()`:
* `string $destination`: Final destination path of the generated file.
* `string $relativePath`: Relative or basename path.
* `bool $isOverride`: Whether the file originated from a host override.
* `bool $isRawCopy`: Whether the file was copied as a raw non-stub asset.
* `bool $dryRun`: Whether execution was simulated.

---

## 3. Practical Use Cases & Examples

### Use Case A: CLI Progress Bars via `onProgress()`
Because tree scaffolding does not flood the global event dispatcher on every file, use the native `->onProgress()` callback on `ScaffoldBuilder` for console progress bars:

```php
use AlexKassel\StubEngine\Facades\StubEngine;
use Illuminate\Console\Command;

class MakeModuleCommand extends Command
{
    public function handle(): int
    {
        $bar = $this->output->createProgressBar();

        StubEngine::from(__DIR__ . '/../stubs')
            ->to(app_path('Domain/Billing'))
            ->withTokens(['name' => 'Invoice'])
            ->onProgress(function (string $file, int $index, int $total) use ($bar): void {
                $bar->setMaxSteps($total);
                $bar->advance();
            })
            ->scaffold();

        $bar->finish();
        $this->newLine();

        return self::SUCCESS;
    }
}
```

---

### Use Case B: Pre-Scaffolding Initialization via `TreeScaffolding`
Execute pre-flight checks or log intent before any disk operations begin:

```php
use AlexKassel\StubEngine\Events\TreeScaffolding;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(TreeScaffolding::class, function (TreeScaffolding $event): void {
    Log::info("Starting scaffolding from {$event->sourceDir} to {$event->targetDir}");
});
```

---

### Use Case C: Dynamically Injecting Headers in Single-File Scaffolding
Intercept standalone files before they are written:

```php
use AlexKassel\StubEngine\Events\FileScaffolding;
use Illuminate\Support\Facades\Event;

Event::listen(FileScaffolding::class, function (FileScaffolding $event): void {
    if (str_ends_with($event->destination, '.php')) {
        $header = "<?php\n\n/**\n * (c) " . date('Y') . " Acme Corp.\n */\n";
        $event->setContent(preg_replace('/^<\?php\s*/', $header, $event->content));
    }
});
```

---

### Use Case D: Post-Scaffolding Audit & Notifications
React when the entire tree scaffolding has finished:

```php
use AlexKassel\StubEngine\Events\TreeScaffolded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(TreeScaffolded::class, function (TreeScaffolded $event): void {
    Log::info('Scaffolding completed', [
        'created_count' => count($event->result->createdFiles),
        'overridden'    => $event->result->overrideFiles,
    ]);
});
```

---

## 4. Standalone & Zero-Overhead Operation

If `StubEngine` is utilized outside of a full Laravel application:
* The engine safely checks if an event dispatcher is available.
* If no dispatcher is bound, event dispatching is skipped with zero performance overhead and without exceptions.
* In custom PHP applications, you can inject any PSR-14 or Laravel-compatible dispatcher via `$engine->setEventDispatcher($dispatcher)`.
