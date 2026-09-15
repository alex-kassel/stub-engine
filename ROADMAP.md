# 🗺️ StubEngine Roadmap & RFC Proposals

This document outlines architectural proposals and future feature specifications for **StubEngine**. Each proposal is formatted as an isolated, self-contained RFC (Request for Comments) with strict boundaries, enabling independent implementation.

---

## Table of Contents

* [RFC-001: Smart Code Injection & Anchor Hooking](#rfc-001-smart-code-injection--anchor-hooking)
* [RFC-002: Lightweight Conditional & Loop Directives](#rfc-002-lightweight-conditional--loop-directives)
* [RFC-003: Interactive Conflict Resolution & Unified Diffing](#rfc-003-interactive-conflict-resolution--unified-diffing)

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
