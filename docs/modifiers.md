# Token Modifiers in StubEngine

This document provides a comprehensive guide to using and extending **Token Modifiers** within `alex-kassel/stub-engine`.

---

## 1. Overview & Core Syntax

Token modifiers allow you to transform placeholder values directly within template files and file path names without needing to pre-compute and pass dozens of separate token keys.

A modifier is specified using the pipe separator (`|`) inside the opening and closing delimiters:

```text
{{ token_name | modifier }}
```

### Whitespace Resilience
The interpolation engine is fully resilient to arbitrary whitespace around delimiters, token identifiers, and modifier separators:
* `{{ name | studly }}`
* `{{ name|studly }}`
* `{{name|studly}}`
* `{{ name | studly}}`
* `{{name | studly }}`
* `{{   name   |   studly   }}`

All of the above produce identical output.

---

## 2. Built-in String Modifiers

`StubEngine` comes with 10 built-in string transformers powered by Laravel's `Illuminate\Support\Str`:

| Modifier | Input Example (`"user profile"`) | Output Example | Typical Use Case |
| :--- | :--- | :--- | :--- |
| `studly` | `"user profile"` | `UserProfile` | PHP Class names, Interfaces, Enums |
| `camel` | `"user profile"` | `userProfile` | Method names, camelCase variables |
| `kebab` | `"user profile"` | `user-profile` | Route slugs, CLI command names, CSS classes |
| `snake` | `"user profile"` | `user_profile` | Database columns, config keys |
| `lower` | `"User Profile"` | `user profile` | Lowercased text |
| `upper` | `"user profile"` | `USER PROFILE` | Uppercased text, constants |
| `title` | `"user profile"` | `User Profile` | Human-readable headers, titles |
| `plural` | `"user profile"` | `user profiles` | Pluralized words |
| `singular` | `"user profiles"` | `user profile` | Singularized words |
| `trim` | `"  user profile  "` | `user profile` | Stripping surrounding whitespace |

> [!NOTE]
> Modifiers are simple string transformations. If an unrecognized modifier is encountered, `StubEngine` immediately throws an `\InvalidArgumentException` to prevent silent bugs from typos.

---

## 3. Modifier Chaining

You can chain multiple modifiers sequentially using multiple pipes (`|`). Transformations are executed from left to right, where the output of each modifier becomes the input to the next.

### Examples

```text
{{ entity | snake | plural }}
```
* Input: `'UserProfile'`
* Step 1 (`snake`): `'user_profile'`
* Step 2 (`plural`): `'user_profiles'` (ideal for database table names!)

```text
{{ entity | snake | upper }}
```
* Input: `'UserProfile'`
* Step 1 (`snake`): `'user_profile'`
* Step 2 (`upper`): `'USER_PROFILE'` (ideal for PHP constants or environment variables!)

```text
{{ entity | camel | studly }}
```
* Input: `'user_profile'`
* Step 1 (`camel`): `'userProfile'`
* Step 2 (`studly`): `'UserProfile'`

---

## 4. Registering Custom Modifiers

You can register custom modifier callbacks at runtime using `StubEngine::registerModifier()`:

```php
use AlexKassel\StubEngine\Facades\StubEngine;

// Register custom string transformation
StubEngine::registerModifier('reverse', function (string $value): string {
    return strrev($value);
});

// Template: {{ name | reverse }}
// Input: 'John' -> Output: 'nhoJ'
```

### Chaining Custom and Built-in Modifiers
Custom modifiers integrate seamlessly into chains alongside built-in modifiers:

```php
StubEngine::registerModifier('slug', fn (string $val): string => strtolower(str_replace(' ', '-', $val)));
```

Template:
```text
{{ title | slug | upper }}
```
Input: `'Hello World'` -> Step 1: `'hello-world'` -> Step 2: `'HELLO-WORLD'`.

---

## 5. Token Modifiers in File and Directory Paths

Modifiers work identically in file and directory paths during tree scaffolding:

```text
stubs/
├── src/
│   ├── Actions/
│   │   └── Create{{ model|studly }}Action.php.stub
│   └── Models/
│       └── {{ model|studly }}.php.stub
└── database/
    └── migrations/
        └── create_{{ model|snake|plural }}_table.php.stub
```

When scaffolded with `tokens: ['model' => 'order item']`, this generates:
* `src/Actions/CreateOrderItemAction.php`
* `src/Models/OrderItem.php`
* `database/migrations/create_order_items_table.php`

---

## 6. Performance & Architecture Note

`StubEngine` uses a single-pass `preg_replace_callback` parser. Unlike naive replacement systems that build permutation dictionaries, `StubEngine`:
1. Scans template content exactly once ($O(N)$).
2. Detects only tokens and modifier directives that are *actually present* in the file.
3. Resolves and applies modifiers just-in-time (JIT).
4. Executes with zero combinatorial dictionary explosion.

---

## 7. Escaping & Blade Template Compatibility

When scaffolding Laravel applications, stub files often contain native Blade expressions:

```blade
<h1>{{ ClassName }}</h1>
<p>{{ $user->name }}</p>
```

Because Blade uses the same default delimiters (`{{` and `}}`), `StubEngine` provides an `@` escape prefix, matching Laravel Blade's own verbatim escape convention:

```blade
<h1>{{ ClassName }}</h1>
<p>@{{ $user->name }}</p>
<a href="@{{ route('profile', ['id' => $user->id]) }}">View</a>
```

### How Escaping Works
1. **Stripping `@`**: During interpolation, `@{{ ... }}` is converted directly to `{{ ... }}` on disk.
2. **Bypassing Modifiers**: No token lookups or modifiers are evaluated inside escaped expressions.
3. **Strict Mode Safety**: Escaped expressions are ignored by `extractTokens()`, preventing false-positive exceptions when scaffolding in `strict: true` mode.
4. **Custom Delimiters**: If you configure custom delimiters (such as `<% %>` or `[[ ]]`), native Blade tags `{{ $user->name }}` do not collide at all and require no escaping. However, `@` escaping is supported for any configured open delimiter (e.g. `@<% verbatim %>` -> `<% verbatim %>`).
