# StubEngine Comprehensive Refactoring Plan

This document outlines the architectural assessment, bottlenecks, anti-patterns, and step-by-step refactoring plan for `alex-kassel/stub-engine`, structured by priority.

---

## Priority 1: Critical Fixes & Token Engine Overhaul (Phase 1)

### 1.1. Single-Pass Regex Interpolation & Elimination of Exploding Dictionaries [x] COMPLETED
* **Problem**: 
  Currently, `resolveTokens()` expands every single token into ~30 distinct dictionary variations (base tokens + 9 case modifiers * 3 whitespace variants + custom modifiers). For a template using 40 tokens across 50 files, this yields over 1,200 search keys and executes >60,000 iterations of `str_replace()` across file paths and file bodies, where 99.9% of searches find nothing. This is an O(F * T * M) operation (F = files, T = tokens, M = modifiers) that degrades performance rapidly as templates scale.
* **Proposed Solution**:
  Replace dictionary-based `str_replace` with a single-pass `preg_replace_callback()` scanner. The engine scans template text exactly once (O(N) where N is content length), extracts only tokens and modifier directives that are *actually present* in the file, resolves them on-demand (JIT), and replaces them cleanly.

### 1.2. Whitespace-Resilient Modifier & Token Parsing [x] COMPLETED
* **Problem**:
  The current engine hardcodes three specific string combinations for whitespace around delimiters and pipes:
  1. `{{ token|modifier }}`
  2. `{{token|modifier}}`
  3. `{{ token | modifier }}`
  Any other valid variation (such as `{{ token | modifier}}`, `{{token | modifier }}`, or multiple spaces `{{  token  }}`) fails silently to match, leaving raw placeholders or throwing an exception in strict mode.
* **Proposed Solution**:
  Implement a flexible tokenizer regex pattern:
  `/{{\s*([a-zA-Z0-9_]+)(?:\s*\|\s*([a-zA-Z0-9_|:]+))?\s*}}/`
  This tolerates arbitrary whitespace around delimiters, token identifiers, and modifier separators without brittle permutations.

### 1.3. Modifier Chaining [x] COMPLETED
* **Problem**:
  Real-world code scaffolding frequently requires composing transformations, such as converting a model name into a table name: `{{ model | snake | plural }}` (e.g. `UserProfile` -> `user_profiles`) or generating URLs: `{{ name | lower | kebab }}`. The current implementation only allows a single modifier.
* **Proposed Solution**:
  Support pipe-delimited modifier chains (e.g. `{{ var | mod1 | mod2 | mod3 }}`). When evaluating the match in `preg_replace_callback`, iterate sequentially through each modifier in the chain, piping the output of one modifier as the input into the next.

### 1.4. Parameterized Modifiers [x] COMPLETED
* **Problem**:
  Modifiers cannot accept dynamic or static arguments (e.g. `{{ date | format:Y-m-d }}`, `{{ namespace | default:App\\Models }}`, or `{{ text | replace:foo,bar }}`).
* **Proposed Solution**:
  Support colon-separated modifier arguments syntax (`modifier:arg1,arg2`). Parse arguments in the modifier resolver and pass them to the registered modifier callable: `fn(string $value, ...$args): string`.

### 1.5. Fix Relative Path Traversal Bug in `scaffoldFile` [x] COMPLETED
* **Problem**:
  In `scaffoldFile()`, the security check calls `$this->ensureWithinTargetDirectory(dirname($targetFile), $targetFile)`. When `$targetFile` is a bare relative filename in the current directory (e.g. `'output.txt'`), `dirname('output.txt')` returns `'.'`. Inside `ensureWithinTargetDirectory()`, `'.'` canonicalizes to an empty string prefix `''`, which causes `str_starts_with('output.txt', '/')` to evaluate to `false`, falsely triggering an `InvalidArgumentException: Target path [output.txt] attempts directory traversal outside target directory [.]`.
* **Proposed Solution**:
  Normalize target directories and paths using realpath or absolute base resolution before traversal checks. If `$targetDir` is `.` or relative, anchor it to the current working directory or base path before comparing prefixes.

### 1.6. Fix Incomplete Regex in `findUnresolvedTokens` [x] COMPLETED
* **Problem**:
  The pattern in `findUnresolvedTokens()` uses `[^'.$escapedClose.'\s]+`, which explicitly forbids whitespace inside the token capture. If an unresolved placeholder contains inner spaces (such as `{{ missing_var | studly }}` with spaces around the pipe), the pattern breaks at the first space and fails to capture the token.
* **Proposed Solution**:
  Update the detection pattern to match arbitrary token expressions up to the closing delimiter:
  `/{{\s*([^{}\r\n]+?)\s*}}/` (or matching custom configured delimiters).

### 1.7. Blade Template Escape & Verbatim Syntax [x] COMPLETED
* **Problem**:
  Laravel applications frequently scaffold `.blade.php` views containing native Blade expressions like `{{ $user->name }}` or `{{ route('home') }}`. With default `{{ }}` delimiters, strict mode crashes when encountering Blade tags, and non-strict mode may corrupt Blade variables.
* **Proposed Solution**:
  1. Add support for escape syntax `@{{ ... }}` or `\{{ ... }}` which preserves native Blade tags verbatim during scaffolding while stripping the escape prefix.
  2. Provide a helper/preset for Blade scaffolding that automatically shifts token delimiters (e.g. `<% %>` or `[[ ]]`) when generating Blade files.

---

## Priority 2: Architecture, Modularity & SOLID Principles (Phase 2)

### 2.1. Deconstruct the God Class (`StubEngine`) into Focused Collaborators (SRP) [x] COMPLETED
* **Problem**:
  The 488-line `StubEngine` class currently handles path traversal validation, string parsing and compilation, delimiter resolution, diagnostic scans, overlay/replace directory crawling, and filesystem writes. This violates the Single Responsibility Principle and complicates unit testing.
* **Proposed Solution**:
  Extract specialized collaborator classes:
  1. `AlexKassel\StubEngine\Support\PathGuard`: Dedicated directory containment and traversal validation.
  2. `AlexKassel\StubEngine\Engines\Interpolator`: Pure string token substitution, modifier chaining, and delimiter management.
  3. `AlexKassel\StubEngine\Resolvers\StubResolver`: Directory crawler that merges package stubs and host overrides under `Overlay` or `Replace` strategies.
  4. `AlexKassel\StubEngine\Services\StubEngine`: Thin coordinator service acting as the public facade and orchestrator.

### 2.2. Enforce Strict Fallback Encapsulation (Project Standard) [x] COMPLETED
* **Problem**:
  Several methods contain raw string literals in their bodies for config keys and defaults (e.g. line 137: `'stub-engine.delimiters.open'`, line 139: `'stub-engine.delimiters.close'`, line 193: `'stub-engine.global_tokens'`), violating the project's zero-raw-literals rule.
* **Proposed Solution**:
  Define explicit, typed class constants at the top of the relevant classes (e.g., `public const CONFIG_LEGACY_OPEN_KEY = 'stub-engine.delimiters.open';`, `public const EMPTY_STRING_FALLBACK = '';`, `public const EMPTY_ARRAY_FALLBACK = [];`), resolving them just-in-time.

---

## Priority 3: Laravel-First Ergonomics & Developer Experience (Phase 3)

### 3.1. Convention-Based Host Overrides Auto-Discovery
* **Problem**:
  Currently, callers must manually calculate and pass `overrideDir: base_path('stubs/my-package')` on every call to `scaffoldTree()`.
* **Proposed Solution**:
  Introduce convention-based auto-discovery:
  ```php
  StubEngine::forPackage('alex-kassel/my-package')
      ->from(__DIR__ . '/../stubs')
      ->to(app_path('Domain/Billing'))
      ->with(['name' => 'Invoice'])
      ->scaffold();
  ```
  `StubEngine` automatically checks whether `base_path('stubs/vendor/alex-kassel/my-package')` exists in the host application and applies it as the `overrideDir`.

### 3.2. Fluent Builder Interface (`ScaffoldBuilder`)
* **Problem**:
  `scaffoldTree()` currently takes 11 parameters (`$sourceDir`, `$targetDir`, `$tokens`, `$overrideDir`, `$strategy`, `$stubExtension`, `$force`, `$dryRun`, `$openDelimiter`, `$closeDelimiter`, `$strict`). This parameter list is unwieldy and prone to ordering errors when not using named arguments.
* **Proposed Solution**:
  Introduce a fluent `ScaffoldBuilder`:
  ```php
  StubEngine::from($stubsDir)
      ->to($targetDir)
      ->withTokens(['module' => 'Order'])
      ->overlay($hostOverridesDir)
      ->force()
      ->dryRun(false)
      ->strict()
      ->scaffold();
  ```

### 3.3. `Macroable` Trait Integration [x] COMPLETED
* **Problem**:
  Consumers cannot augment `StubEngine` with domain-specific shortcuts or macros without forking or wrapping the class.
* **Proposed Solution**:
  Add `Illuminate\Support\Traits\Macroable` to `StubEngine` to allow third-party packages to extend it cleanly (e.g. `StubEngine::macro('scaffoldModule', ...)`).

---

## Priority 4: Ecosystem Tooling, Events & Code Formatting (Phase 4)

### 4.1. Lifecycle Events
* **Problem**:
  External packages or console commands cannot react to file generation progress (e.g. logging, UI progress bars, or post-generation hooks).
* **Proposed Solution**:
  Dispatch Laravel events throughout the scaffolding lifecycle:
  - `FileScaffolding`: Dispatched before writing a file (allows altering content or cancelling).
  - `FileScaffolded`: Dispatched after a file is generated.
  - `TreeScaffolded`: Dispatched when the tree generation completes with `ScaffoldResult`.

### 4.2. Code Formatting Pipeline Hook (Laravel Pint Integration)
* **Problem**:
  Generated PHP files often have minor indentation or whitespace artifacts caused by token interpolation.
* **Proposed Solution**:
  Add an optional post-scaffold formatting pipeline hook that can invoke Laravel Pint or PHP CS Fixer on generated PHP files when configured (`formatWithPint: true`).

### 4.3. Artisan Command for Stub Publishing
* **Problem**:
  Host application developers who want to customize package stubs must manually create directory structures in `stubs/vendor/...`.
* **Proposed Solution**:
  Provide a dedicated Artisan command:
  `php artisan stub-engine:publish {package}`
  This discovers registered packages and publishes their default stubs into `stubs/vendor/{package}` for host customization.
