# Code Formatting (Laravel Pint Integration)

`alex-kassel/stub-engine` includes built-in, Laravel-first post-scaffolding code formatting powered by **Laravel Pint**.

---

## 1. Overview & Motivation

Token interpolation in templates often leaves minor formatting artifacts such as uneven indentation, inconsistent spacing, or redundant blank lines.

Instead of writing custom regular expressions or manual whitespace cleanup, `StubEngine` allows automatically running Laravel Pint over freshly created and overwritten `.php` files before returning the final result.

---

## 2. Usage in `ScaffoldBuilder`

You can activate Pint formatting through `formatWithPint()` or its alias `format()`:

```php
use AlexKassel\StubEngine\Facades\StubEngine;

$result = StubEngine::from(__DIR__ . '/../stubs')
    ->to(app_path('Domain/Billing'))
    ->with(['module' => 'Invoice'])
    ->formatWithPint()
    ->scaffold();

if ($result->isFormatted()) {
    // All PHP files were cleaned and formatted according to project Pint rules
}
```

---

## 3. Strict vs. Lenient Modes

When requesting formatting, the engine needs to know how to react if Laravel Pint is not installed:

```php
->formatWithPint(
    bool $enabled = true,
    bool $strict = false,
    ?string $binary = null
)
```

### Lenient Mode (`strict: false`, Default)
* Files are successfully created on disk.
* If Pint binary is not found, the operation completes without fatal errors.
* A warning message is recorded in `ScaffoldResult::$warnings`:
  ```php
  if ($result->hasWarnings()) {
      foreach ($result->warnings as $warning) {
          $this->warn($warning);
      }
  }
  ```

### Strict Mode (`strict: true`)
* Ideal for automated tests and CI/CD quality gates.
* If Pint binary cannot be resolved, the engine immediately throws an `AlexKassel\StubEngine\Exceptions\FormatterNotFoundException`:
  ```text
  Laravel Pint binary not found at [vendor/bin/pint].

  How to fix:
  1. Install Laravel Pint: composer require laravel/pint --dev
  2. Or configure a custom binary: ->formatWithPint(binary: '/path/to/pint')
  3. Or disable strict formatting checks: ->formatWithPint(strict: false)
  ```

---

## 4. Custom Binary Resolution

By default, the engine searches for the Pint executable in:
1. An explicitly configured `$binary` path.
2. Host application root: `base_path('vendor/bin/pint')`.
3. Working directory: `vendor/bin/pint`.

If Pint is installed in an alternate path or globally in the system:
```php
StubEngine::from($stubs)
    ->to($destination)
    ->formatWithPint(binary: '/usr/local/bin/pint')
    ->scaffold();
```

---

## 5. Result Inspection

`ScaffoldResult` provides dedicated helpers to inspect formatting status:

```php
$result = StubEngine::from($source)->to($target)->format()->scaffold();

// Check if Pint was executed
$isClean = $result->isFormatted(); // bool

// Check if any warnings occurred during formatting
if ($result->hasWarnings()) {
    $warnings = $result->warnings; // array<int, string>
}
```

Only `.php` files are sent to Pint. Non-PHP templates (e.g. `.json`, `.yaml`, `.stub`, raw static images) are safely filtered out.
