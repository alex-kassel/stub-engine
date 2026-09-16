# Lifecycle Events Guide

`alex-kassel/stub-engine` dispatches native Laravel lifecycle events throughout the scaffolding pipeline. This allows commands, host applications, and third-party packages to monitor generation progress, render interactive progress bars, inspect generated files, or modify file content before it is committed to disk.

---

## 1. Available Events

| Event | Class | Timing | Key Capabilities |
| :--- | :--- | :--- | :--- |
| **`FileScaffolding`** | `AlexKassel\StubEngine\Events\FileScaffolding` | Dispatched immediately **before** an individual file is written | Inspect metadata, modify content before writing, or cancel creation of specific files |
| **`FileScaffolded`** | `AlexKassel\StubEngine\Events\FileScaffolded` | Dispatched immediately **after** an individual file has been written/copied | Update CLI progress bars, audit logging, trigger post-creation hooks |
| **`TreeScaffolded`** | `AlexKassel\StubEngine\Events\TreeScaffolded` | Dispatched when the **entire tree** scaffolding finishes | Read complete audit summary via `ScaffoldResult`, print final reports |

---

## 2. Event Payloads & Properties

### `FileScaffolding`
Dispatched before each stub file is rendered to disk or raw asset is copied:
* `string $destination`: Full target destination path.
* `string $relativePath`: Relative path within the scaffolding target directory.
* `string $content`: The interpolated content about to be written.
* `bool $isOverride`: Whether the stub originates from a host override directory.
* `bool $isRawCopy`: Whether this file is a raw binary/non-stub asset copied verbatim.
* `bool $dryRun`: Whether execution is in simulation mode.
* `bool $shouldSkip`: If set to `true`, the engine skips writing this file.

#### Methods:
* `skip(): self`: Cancels creation of this file. The file is recorded in `$result->skippedFiles`.
* `setContent(string $content): self`: Replaces the content before it is written to the filesystem.

---

### `FileScaffolded`
Dispatched after an individual file has been successfully written or copied:
* `string $destination`: Destination path of the generated file.
* `string $relativePath`: Relative path within the target tree.
* `bool $isOverride`: Whether the file originated from a host override.
* `bool $isRawCopy`: Whether the file was copied as a raw non-stub asset.
* `bool $dryRun`: Whether execution was simulated.

---

### `TreeScaffolded`
Dispatched when directory tree scaffolding completes:
* `ScaffoldResult $result`: Detailed DTO containing lists of `createdFiles`, `overwrittenFiles`, `skippedFiles`, `overrideFiles`, `rawCopiedFiles`, and `unresolvedTokens`.

---

## 3. Practical Use Cases & Examples

### Use Case A: CLI Progress Bars in Artisan Commands
When scaffolding a large directory tree, you can advance a Symfony/Laravel progress bar as each file is generated:

```php
use AlexKassel\StubEngine\Events\FileScaffolded;
use AlexKassel\StubEngine\Facades\StubEngine;
use Illuminate\Support\Facades\Event;

public function handle(): int
{
    $bar = $this->output->createProgressBar();

    Event::listen(FileScaffolded::class, function (FileScaffolded $event) use ($bar): void {
        $bar->advance();
        $this->output->write("  [+] Generated {$event->relativePath}");
    });

    StubEngine::from(__DIR__ . '/../stubs')
        ->to(app_path('Domain/Billing'))
        ->with(['name' => 'Invoice'])
        ->scaffold();

    $bar->finish();

    return self::SUCCESS;
}
```

---

### Use Case B: Dynamically Injecting Headers or Licensing
You can intercept files before they are written to prepend dynamic copyright headers:

```php
use AlexKassel\StubEngine\Events\FileScaffolding;
use Illuminate\Support\Facades\Event;

Event::listen(FileScaffolding::class, function (FileScaffolding $event): void {
    if (str_ends_with($event->relativePath, '.php')) {
        $header = "<?php\n\n/**\n * (c) " . date('Y') . " Acme Corp. All rights reserved.\n */\n";
        $modifiedContent = preg_replace('/^<\?php\s*/', $header, $event->content);
        $event->setContent($modifiedContent);
    }
});
```

---

### Use Case C: Conditional File Filtering (Skipping Files)
You can dynamically skip specific files based on runtime flags or environmental checks:

```php
use AlexKassel\StubEngine\Events\FileScaffolding;
use Illuminate\Support\Facades\Event;

Event::listen(FileScaffolding::class, function (FileScaffolding $event): void {
    if ($event->relativePath === 'tests/Feature/SmokeTest.php' && ! config('app.include_smoke_tests')) {
        $event->skip();
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
