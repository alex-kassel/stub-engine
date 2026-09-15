# 🗺️ StubEngine Roadmap & RFC Proposals

This document outlines architectural proposals and future feature specifications for **StubEngine**. Each proposal is formatted as an isolated, self-contained RFC (Request for Comments) with strict boundaries, enabling independent implementation.

---

## Table of Contents

* [RFC-001: Smart Code Injection & Anchor Hooking](#rfc-001-smart-code-injection--anchor-hooking)
* [RFC-002: Lightweight Conditional & Loop Directives](#rfc-002-lightweight-conditional--loop-directives)
* [RFC-003: Interactive Conflict Resolution & Unified Diffing](#rfc-003-interactive-conflict-resolution--unified-diffing)
* [RFC-004: Post-Processor & Code Cleanup Pipeline](#rfc-004-post-processor--code-cleanup-pipeline)
* [RFC-005: Stub Schema & Interactive Token Prompter](#rfc-005-stub-schema--interactive-token-prompter)
* [RFC-006: First-Class Testing Kit & Snapshot Assertions](#rfc-006-first-class-testing-kit--snapshot-assertions)

---

## RFC-001: Smart Code Injection & Anchor Hooking

### 1. Motivation & Problem Statement

Scaffolding often involves modifying existing application files alongside generating new ones. For example, when generating a domain module, developers must register:
* Service providers in `bootstrap/providers.php`
* Route definitions in `routes/api.php`
* Policy mappings in auth providers

Currently, `StubEngine` can only create new files or overwrite existing files. Developers must manually copy-paste registration snippets after running the generator.

### 2. Proposed Public API & DX Example

```php
use AlexKassel\StubEngine\Facades\StubEngine;

// 1. Direct snippet injection into an existing file
$injected = StubEngine::inject(
    targetFile: base_path('routes/api.php'),
    snippet: "Route::apiResource('{{ route|kebab }}', {{ name|studly }}Controller::class);",
    anchor: '// [STUB-ENGINE:ROUTES]',
    tokens: ['route' => 'billing-orders', 'name' => 'billing order'],
    position: 'after', // 'after' | 'before' | 'append' | 'prepend'
    skipIfPresent: true, // Prevents duplicate registrations
);

// 2. Scaffolding hook files with '.inject' or '.append' extension during tree scaffolding
// File on disk: stubs/routes/api.php.inject
// Contains:
// ---
// anchor: "// [STUB-ENGINE:ROUTES]"
// position: "after"
// skipIfPresent: true
// ---
// Route::apiResource('{{ route|kebab }}', {{ name|studly }}Controller::class);
```

### 3. Architecture & Impacted Components

* **New Service:** `AlexKassel\StubEngine\Services\StubInjector`
* **New Methods on `StubEngine`:**
  * `inject(string $targetFile, string $snippet, string $anchor, array $tokens = [], string $position = 'after', bool $skipIfPresent = true): bool`
* **Extension to `scaffoldTree`:** Detect `.inject` file suffixes, parse front-matter metadata headers, and delegate to `StubInjector` without writing standalone files.

### 4. Implementation Boundaries & Non-Goals

* **In Scope:**
  * Anchor marker search (`strpos`, exact string, or regex pattern).
  * Positions: `before`, `after`, `append` (end of file), `prepend` (start of file).
  * Duplicate avoidance (`skipIfPresent = true` checks if snippet already exists in the file).
  * Safe indentation preservation (matches whitespace of the anchor line).
* **Non-Goals (Out of Scope):**
  * Full AST parsing via `nikic/php-parser` (keep the engine lightweight with zero heavy dependencies).
  * Arbitrary syntax rewriting or AST transformation.

### 5. Acceptance Criteria & Test Scenarios

1. Injecting a snippet after an anchor comment correctly inserts the content with matched indentation.
2. If `skipIfPresent = true` and the snippet already exists in the target file, return `false` without modifying the file.
3. If the anchor marker is missing, throw a descriptive `InvalidArgumentException` indicating the missing anchor.
4. Dry-run mode (`dryRun: true`) validates presence and placement without modifying the target file.

---

## RFC-002: Lightweight Conditional & Loop Directives

### 1. Motivation & Problem Statement

Template stubs often need variable structural sections based on generation flags:
* Optional soft deletes: `{{#if with_soft_deletes}}use SoftDeletes;{{/if}}`
* Optional authentication methods: `{{#if with_auth}}public function authorize(): bool { ... }{{/if}}`
* Array iteration for model fields or DTO properties: `{{#each fields}}'{{ this }}',{{/each}}`

Currently, developers must create multiple variant stub files (e.g. `model.stub`, `model.softdeletes.stub`) or pre-compile entire multi-line code blocks into individual string tokens before calling `StubEngine`.

### 2. Proposed Public API & DX Example

In template files:

```php
namespace {{ namespace }};

use Illuminate\Database\Eloquent\Model;
{{#if with_soft_deletes}}
use Illuminate\Database\Eloquent\SoftDeletes;
{{/if}}

class {{ entity|studly }} extends Model
{
    {{#if with_soft_deletes}}
    use SoftDeletes;
    {{/if}}

    protected $fillable = [
        {{#each fields}}
        '{{ this }}',
        {{/each}}
    ];
}
```

Usage in PHP:

```php
$rendered = StubEngine::interpolate($template, [
    'entity' => 'user account',
    'namespace' => 'App\Models',
    'with_soft_deletes' => true,
    'fields' => ['name', 'email', 'avatar'],
]);
```

### 3. Architecture & Impacted Components

* **New Component:** `AlexKassel\StubEngine\Services\DirectiveParser`
* **Impacted Method:** `StubEngine::interpolate()` passes content through `DirectiveParser::parse()` before standard token substitution.
* **Syntax Support:**
  * `{{#if condition}}...{{/if}}` and optional `{{#else}}`
  * `{{#unless condition}}...{{/unless}}`
  * `{{#each list}}...{{ this }}...{{/each}}`

### 4. Implementation Boundaries & Non-Goals

* **In Scope:**
  * Simple truthy/falsy evaluation on variables in the `$tokens` array.
  * Array iteration with `{{ this }}` and `{{ @key }}` placeholders.
  * Clean line-trimming so omitted `#if` blocks do not leave redundant empty lines.
* **Non-Goals (Out of Scope):**
  * Do NOT pull in the full Blade compiler engine or external template runtimes like Twig.
  * No arbitrary PHP code execution (e.g. `{{#if count($fields) > 5}}` is forbidden; expressions must be boolean flags provided in `$tokens`).

### 5. Acceptance Criteria & Test Scenarios

1. `#if` block renders content when the flag is `true` and strips content completely when `false` or null.
2. `#if ... #else ... #endif` correctly toggles alternative blocks.
3. `#each` iterates flat and associative arrays, interpolating `{{ this }}` and `{{ @key }}` correctly.
4. Stripping omitted blocks cleanly removes associated newlines without leaving gap lines.

---

## RFC-003: Interactive Conflict Resolution & Unified Diffing

### 1. Motivation & Problem Statement

Currently, when target destination files already exist on disk, `StubEngine` provides binary choices:
* `force: false` skips existing files silently.
* `force: true` overwrites existing files blindly.

If a developer has customized previously generated code, `force: true` destroys their work, while `force: false` prevents updates. A modern CLI scaffolding tool should detect content differences, display colorized unified diffs, and provide interactive conflict resolution prompts.

### 2. Proposed Public API & DX Example

Programmatic Diff Inspection:

```php
use AlexKassel\StubEngine\Enums\ConflictAction;
use AlexKassel\StubEngine\Facades\StubEngine;

$result = StubEngine::scaffoldTree(
    sourceDir: __DIR__ . '/../stubs',
    targetDir: base_path('app/Modules/Billing'),
    tokens: ['name' => 'Billing'],
    conflictHandler: function (string $targetPath, string $existingContent, string $newContent): ConflictAction {
        // Output interactive diff in console
        return ConflictAction::Diff; // Overwrite, Skip, Diff, Backup
    },
);
```

Console Output Example:

```
Conflict in [app/Modules/Billing/BillingController.php]:
  - public function index() { return []; }
  + public function index(): JsonResponse { return response()->json([]); }

[y] Overwrite
[n] Skip
[d] View full diff
[b] Backup existing and overwrite
Choose action [y/n/d/b] (default: n):
```

### 3. Architecture & Impacted Components

* **New Enum:** `AlexKassel\StubEngine\Enums\ConflictAction` (`Overwrite`, `Skip`, `Backup`, `Diff`)
* **New Service:** `AlexKassel\StubEngine\Services\DiffGenerator` (generates unified diff strings between existing and proposed content).
* **DTO Updates:** `ScaffoldResult` tracks `conflictedFiles`, `backedUpFiles`, and `diffs`.

### 4. Implementation Boundaries & Non-Goals

* **In Scope:**
  * Content comparison (`hash_equals(md5($current), md5($new))` check before triggering conflict).
  * Standard Unified 2-way Diff generation without external binary dependencies.
  * Backup option (`filename.ext.bak.timestamp`).
  * Pluggable closure handler for CLI command orchestration.
* **Non-Goals (Out of Scope):**
  * Automated 3-way git merge resolution (no merge conflict markers `<<<<<<<`).
  * Visual GUI tools.

### 5. Acceptance Criteria & Test Scenarios

1. Identical existing files are not flagged as conflicted.
2. When content diverges and a conflict handler is provided, the closure is invoked with path, existing content, and new content.
3. `ConflictAction::Backup` creates a `.bak` backup file of the existing file and writes the new file.
4. Unified diff generator outputs standard `+` and `-` diff chunks accurately.

---

## RFC-004: Post-Processor & Code Cleanup Pipeline

*Inspiration: Angular Schematics (Rule transform pipes), Plop.js (transform actions), and Laravel Pint.*

### 1. Motivation & Problem Statement

Generated code frequently suffers from formatting degradation:
* Extraneous blank lines left behind after stripped template comments or conditional blocks.
* Unsorted or duplicated `use` import statements.
* Inconsistent indentation or style violations that fail project linters (e.g. PSR-12, Laravel Pint).
* JSON, YAML, or XML files rendered with messy indentation.

Currently, developers must manually run linter commands after executing scaffolding tools, or accept unformatted artifacts in source control.

### 2. Proposed Public API & DX Example

```php
use AlexKassel\StubEngine\Facades\StubEngine;

// 1. Register custom in-memory string transformers per file pattern
StubEngine::addPostProcessor('*.php', function (string $content, string $relativePath): string {
    return ImportSorter::sort($content);
});

// 2. Execute scaffolding with automated Laravel Pint formatting
$result = StubEngine::scaffoldTree(
    sourceDir: base_path('stubs/module'),
    targetDir: app_path('Modules/Billing'),
    tokens: ['name' => 'Billing'],
    formatWithPint: true, // Automatically formats freshly generated files via Laravel Pint
);
```

### 3. Architecture & Impacted Components

* **New Service:** `AlexKassel\StubEngine\Services\PostProcessorPipeline` (maintains an ordered chain of transformers matched by file pattern).
* **New Service:** `AlexKassel\StubEngine\Services\PintFormatter` (safely invokes `vendor/bin/pint` on `$result->createdFiles` using `Symfony\Component\Process\Process`).
* **DTO Updates:** `ScaffoldResult` tracks `formattedFiles`.

### 4. Implementation Boundaries & Non-Goals

* **In Scope:**
  * In-memory string post-processors registered by glob pattern (e.g. `*.php`, `*.json`).
  * Seamless opt-in formatting via Laravel Pint for generated PHP files.
  * Pipeline error containment (formatting issues report warnings without aborting scaffolding).
* **Non-Goals (Out of Scope):**
  * Full AST parsing or automatic syntax refactoring.
  * Hard dependency on external binaries (Pint is invoked only if present in vendor or system).

### 5. Acceptance Criteria & Test Scenarios

1. Registered post-processors transform matching files before disk write.
2. Non-matching files bypass post-processors untouched.
3. Post-processors receive relative path and rendered content.
4. Pint formatting correctly applies to created files when `formatWithPint: true`.

---

## RFC-005: Stub Schema & Interactive Token Prompter

*Inspiration: Mason CLI (`brick.yaml` manifests in Dart/Flutter) and Cookiecutter template schemas.*

### 1. Motivation & Problem Statement

Template directories often have implicit token expectations. Consumers, developers, and AI agents have no standardized way to discover:
* Which placeholder tokens a template requires.
* Which tokens have default fallbacks or computed expressions (e.g. `table = {{ name|snake|plural }}`).
* How to validate user inputs before generating files.

Consequently, CLI command authors repeatedly write boilerplate Symfony Console prompts (`$this->ask()`, `$this->confirm()`) to collect token values.

### 2. Proposed Public API & DX Example

Template manifest file located at `stubs/stub.schema.json`:

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "name": "Domain Module",
  "description": "Standard domain module skeleton with action, model, and tests",
  "tokens": {
    "module": {
      "type": "string",
      "prompt": "What is the domain module name?",
      "example": "Billing",
      "required": true
    },
    "table": {
      "type": "string",
      "prompt": "Database table name?",
      "default": "{{ module|snake|plural }}"
    },
    "with_policy": {
      "type": "boolean",
      "prompt": "Generate authorization policy?",
      "default": true
    }
  }
}
```

CLI Command orchestration:

```php
use AlexKassel\StubEngine\Facades\StubEngine;

// Automatically inspects schema, prompts user in console for missing tokens, and scaffolds
$result = StubEngine::promptAndScaffold(
    sourceDir: base_path('stubs/domain-module'),
    targetDir: app_path('Modules'),
    command: $this, // Illuminate\Console\Command
);
```

### 3. Architecture & Impacted Components

* **New Service:** `AlexKassel\StubEngine\Services\SchemaParser` (loads and validates `stub.schema.json` or `stub.schema.yaml`).
* **New Service:** `AlexKassel\StubEngine\Services\InteractivePrompter` (orchestrates console questions using Laravel Prompts / Symfony Console).
* **Token Dependency Resolver:** Evaluates default token expressions dependent on previously entered tokens.

### 4. Implementation Boundaries & Non-Goals

* **In Scope:**
  * Standard JSON/YAML schema discovery in template source directories.
  * Token type validation (string, boolean, integer, choice).
  * Auto-computed token defaults based on prior inputs.
  * Seamless interactive console prompting via Laravel Prompts.
* **Non-Goals (Out of Scope):**
  * Dynamic script execution or remote schema fetching.

### 5. Acceptance Criteria & Test Scenarios

1. `SchemaParser` correctly extracts required and optional tokens from `stub.schema.json`.
2. Computed default values interpolate earlier tokens (e.g. `table` computes from `module`).
3. Non-interactive CLI runs validate input tokens against schema requirements and fail fast if required tokens are absent.
4. Interactive prompts only ask for tokens not already supplied via CLI options.

---

## RFC-006: First-Class Testing Kit & Snapshot Assertions

*Inspiration: Jest Snapshot Testing and `yeoman-test` filesystem assertions.*

### 1. Motivation & Problem Statement

Testing code generators and scaffolding packages is notoriously tedious. Developers must:
1. Manually set up and tear down temporary directories.
2. Assert individual files with dozens of `$this->assertFileExists()` lines.
3. Compare file contents with brittle, repetitive string comparisons.

As a result, generator test suites are either poorly maintained or omitted entirely.

### 2. Proposed Public API & DX Example

```php
namespace Tests\Feature;

use AlexKassel\StubEngine\Testing\InteractsWithStubs;
use Tests\TestCase;

class MakeModuleCommandTest extends TestCase
{
    use InteractsWithStubs;

    public function test_it_scaffolds_expected_module_structure(): void
    {
        $result = $this->stubEngine()->scaffoldTree(
            sourceDir: base_path('stubs/module'),
            targetDir: $this->sandboxPath('Modules/Billing'),
            tokens: ['name' => 'Billing'],
        );

        // Fluent filesystem & snapshot assertions
        $this->assertScaffold($result)
            ->hasCreated('src/BillingService.php')
            ->fileContains('src/BillingService.php', 'class BillingService')
            ->hasSkippedNothing()
            ->matchesDirectorySnapshot('module_billing_default');
    }
}
```

### 3. Architecture & Impacted Components

* **New Trait:** `AlexKassel\StubEngine\Testing\InteractsWithStubs` (sandboxed directory lifecycle and mock setup).
* **New Assertion Class:** `AlexKassel\StubEngine\Testing\ScaffoldAssertion` (fluent chaining of tree assertions).
* **New Service:** `AlexKassel\StubEngine\Testing\SnapshotManager` (persists and compares directory fixtures in `tests/__snapshots__/`).

### 4. Implementation Boundaries & Non-Goals

* **In Scope:**
  * Automatic sandboxed temp directory provisioning and teardown.
  * Fluent assertions for `ScaffoldResult` (`hasCreated`, `hasOverwritten`, `hasSkipped`, `fileContains`).
  * Text-based directory tree snapshot matching with update flags (`UPDATE_SNAPSHOTS=true`).
* **Non-Goals (Out of Scope):**
  * Binary file visual diffing.

### 5. Acceptance Criteria & Test Scenarios

1. `assertScaffold()` accurately passes when created files match expectations and fails with descriptive messages when files are missing.
2. `matchesDirectorySnapshot()` stores snapshot fixture on first run and validates exact textual identity on subsequent runs.
3. Sandbox directories are automatically deleted upon test completion without leaving stray artifacts.

