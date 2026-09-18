# Changelog

All notable changes to `alex-kassel/stub-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
## [v1.0.0] - 2026-09-18

### Added
- Path traversal security validation in `Scaffolder::validateRelativePath` preventing destination directory escape via malicious token paths.
- Standalone package testing support with `tests/bootstrap.php` and explicit `require-dev` dependencies (`orchestra/testbench`, `phpunit/phpunit`).
- GitHub Actions CI workflow matrix (`.github/workflows/run-tests.yml`) covering PHP 8.2–8.4 and stability matrix.

### Fixed
- Resolved all PHPStan static analysis errors across `Interpolator`, `Scaffolder`, `ScaffoldRequest`, and `ScaffoldBuilder` at `--level=max`.
- Cross-platform Windows filesystem compatibility for custom token delimiters in test suite.
- Removed explicit `"version"` from `composer.json` for Packagist compliance.
- Added `.audit/` to `.gitignore`.

### Changed
- Refactored `ScaffoldResult` into a pure, lightweight readonly DTO with direct public typed properties (`$renderedFiles`, `$createdFiles`, `$overwrittenFiles`, `$skippedFiles`, `$overrideFiles`, `$rawCopiedFiles`, `$unresolvedTokens`).
- Removed redundant helper methods from `ScaffoldResult` (`count()`, `toArray()`, `fileCount`, `hasCreated()`, `hasOverwritten()`, `hasSkipped()`, `hasOverrides()`, `hasRawCopied()`, `hasUnresolvedTokens()`, `successful()`, `isSuccessful()`).
- Refactored `ScaffoldBuilder` internal state encapsulation to use strongly typed properties.
- Unified `override(string $override, OverrideStrategy $strategy = OverrideStrategy::Merge)` in `ScaffoldBuilder`, eliminating redundant `strategy()` method and enforcing non-nullable override paths.

### Documentation
- Updated `README.md` and all guides in `docs/` to reflect current public API truth (`from()`, `to()`, `withTokens()`, `override()`, direct `ScaffoldResult` property inspection).
- Added `StubEngine::extractTokens()` API reference in `docs/stub-engine.md`.
- Added comprehensive `ScaffoldResult` inspection section in `docs/fluent-builder.md`.

## [v0.1.0] - 2026-09-15

### Added
- Pure constructor dependency injection for `StubEngine` (`Filesystem $files`, `array $config = []`), enabling 100% standalone PHP usage.
- Custom token modifier registry: `registerModifier(string $name, callable $callback)` adhering to the Open-Closed Principle.
- Single-pass pre-compiled token replacement via `interpolateWithMap(string $content, array $compiledTokens)`.
- Path traversal security validation preventing relative escape from target scaffolding directories.
- Unresolved token detection with `findUnresolvedTokens()` and optional `strict: true` enforcement in `renderFile`, `scaffoldFile`, and `scaffoldTree`.
- Raw non-stub asset copying (safely copies binary and non-template files without string replacement).
- Automatic filtering for system and VCS files (`.DS_Store`, `Thumbs.db`, `.gitkeep`).
- `ScaffoldResult` tracking for `rawCopiedFiles`, `unresolvedTokens`, and helpers `hasRawCopied()` / `hasUnresolvedTokens()`.
- `.gitattributes` to exclude tests and CI configs from Packagist distribution archives.
- GitHub Actions CI workflow matrix covering PHP 8.2-8.4, stability, and OS (`ubuntu-latest`, `windows-latest`).

### Changed
- Eliminated internal `getConfig()` and global `Container::getInstance()` coupling in favor of container-provided DI via `StubEngineServiceProvider`.
- Optimized `scaffoldTree()` performance from $O(N \times M)$ token recalculation to upfront single-pass dictionary compilation.

## [v0.0.3] - 2026-09-15

### Added
- Complete `renderFile` and `scaffoldFile` documentation and recipes in `README.md`.
- PHPDoc `@method` annotations on `StubEngine` facade for `renderFile`, `scaffoldFile`, and `scaffoldTree`.
- Replaced hardcoded literal extension in facade PHPDoc with `StubEngineService::DEFAULT_STUB_EXTENSION`.

### Changed
- Simplified `StubEngineServiceProvider` singleton registration to `$this->app->singleton(StubEngine::class)`.

## [v0.0.2] - 2026-09-15

### Added
- `renderFile(string $sourceFile, array $tokens, ?string $overrideFile = null): string` for single-file in-memory string compilation with host overrides.
- `scaffoldFile(string $sourceFile, string $targetFile, array $tokens, ?string $overrideFile = null, bool $force = false): bool` for single-file scaffolding.
- Class constant `DEFAULT_STUB_EXTENSION = '.stub'`.

### Fixed
- Passed missing `sourceDir` and `targetDir` parameters to `ScaffoldResult` instantiation in `scaffoldTree`.

## [v0.0.1] - 2026-09-15

### Added
- Initial release of `StubEngine` scaffolding service.
- Dual-axis token interpolation (file contents and file/directory paths).
- Automatic `.stub` extension stripping.
- Cascading host override directory resolution.
- Typed `ScaffoldResult` DTO.
- Laravel ServiceProvider and Facade.
