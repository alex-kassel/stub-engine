# Changelog

All notable changes to `alex-kassel/stub-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
