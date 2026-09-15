# 💡 StubEngine Real-World Use Cases

This document outlines battle-tested architectural scenarios and production use cases where **StubEngine** provides significant developer experience (DX), speed, and architectural consistency.

---

## Table of Contents

1. [Use Case 1: Domain-Driven Design (DDD) & Modular Monolith Scaffolding](#1-domain-driven-design-ddd--modular-monolith-scaffolding)
2. [Use Case 2: White-Label & Multi-Tenant Infrastructure Provisioning](#2-white-label--multi-tenant-infrastructure-provisioning)

---

## 1. Domain-Driven Design (DDD) & Modular Monolith Scaffolding

### Context & Challenge

In medium-to-large Laravel applications adopting Modular Architecture or Domain-Driven Design (DDD), creating a new domain concept (e.g. `Billing/Subscriptions`, `Inventory/Warehousing`) is never as simple as running `php artisan make:model`.

A single domain feature typically requires 8–12 strictly coordinated files across distinct architectural boundaries:
* **Domain Layer:** Entities, Enums, Value Objects, Domain Events.
* **Application Layer:** Actions, Single-action Controllers, DTOs / Form Requests.
* **Infrastructure Layer:** Eloquent Models, Repositories, Database Migrations.
* **Test Suite:** Action Feature Tests, Unit Tests.

Creating this directory hierarchy and typing repetitive namespaces manually wastes 15–30 minutes per slice, introduces human typo errors, and leads to inconsistent folder conventions across team members.

### Template Directory Structure

The package or application maintains standard stubs preserving the natural folder tree:

```
stubs/domain-slice/
├── Actions/
│   └── Create{{ Entity|studly }}Action.php.stub
├── Contracts/
│   └── {{ Entity|studly }}RepositoryInterface.php.stub
├── Data/
│   └── {{ Entity|studly }}Data.php.stub
├── Events/
│   └── {{ Entity|studly }}CreatedEvent.php.stub
├── Http/
│   └── Controllers/
│       └── {{ Entity|studly }}Controller.php.stub
├── Models/
│   └── {{ Entity|studly }}.php.stub
└── Tests/
    └── Feature/
        └── Create{{ Entity|studly }}ActionTest.php.stub
```

Inside `Create{{ Entity|studly }}Action.php.stub`:

```php
namespace {{ rootNamespace }}\{{ Domain|studly }}\Actions;

use {{ rootNamespace }}\{{ Domain|studly }}\Data\{{ Entity|studly }}Data;
use {{ rootNamespace }}\{{ Domain|studly }}\Events\{{ Entity|studly }}CreatedEvent;
use {{ rootNamespace }}\{{ Domain|studly }}\Models\{{ Entity|studly }};

final readonly class Create{{ Entity|studly }}Action
{
    public function execute({{ Entity|studly }}Data $data): {{ Entity|studly }}
    {
        $entity = {{ Entity|studly }}::query()->create($data->toArray());

        event(new {{ Entity|studly }}CreatedEvent($entity));

        return $entity;
    }
}
```

### Implementation & Command Recipe

```php
namespace App\Console\Commands;

use AlexKassel\StubEngine\Facades\StubEngine;
use Illuminate\Console\Command;

class MakeDomainSliceCommand extends Command
{
    protected $signature = 'make:domain-slice {domain} {entity} {--force}';

    public function handle(): int
    {
        $domain = trim((string) $this->argument('domain'));
        $entity = trim((string) $this->argument('entity'));
        $force = (bool) $this->option('force');

        $result = StubEngine::scaffoldTree(
            sourceDir: base_path('stubs/domain-slice'),
            targetDir: app_path("Modules/{$domain}"),
            tokens: [
                'rootNamespace' => 'App\Modules',
                'Domain' => $domain,
                'Entity' => $entity,
            ],
            overrideDir: base_path("stubs/overrides/{$domain}"),
            force: $force,
            strict: true,
        );

        $source = $result->hasOverrides() ? 'custom domain overrides' : 'standard defaults';
        $this->info("Domain slice [{$domain}/{$entity}] scaffolded ({$result->fileCount} files created using {$source}).");

        return self::SUCCESS;
    }
}
```

### Why StubEngine Excels Here

* **Dual-Axis Replacement:** File names (`Create{{ Entity|studly }}Action.php`) and internal namespaces (`App\Modules\Billing\Actions`) are resolved in lockstep.
* **Cascading Host Overrides (`Overlay` Strategy):** If the `Billing` team requires custom event dispatching or specific auditing in their models, they place a single `Models/{{ Entity|studly }}.php.stub` inside `stubs/overrides/Billing/`. All other 6 default files continue flowing from the standard package template without duplication.
* **Zero Syntax Drift:** Teams maintain uniform architectural patterns across all modules.

---

## 2. White-Label & Multi-Tenant Infrastructure Provisioning

### Context & Challenge

In B2B SaaS, multi-tenant enterprise platforms, or Agency client delivery, onboarding a new tenant often requires spinning up an isolated set of infrastructure configurations, orchestration manifests, and localized assets:
* Dedicated `docker-compose.{tenant}.yml` or Kubernetes deployment configs.
* Web server reverse-proxy vhosts (Nginx server blocks or Caddyfile snippets) with custom domains and TLS parameters.
* Supervisor / Horizon queue worker definitions.
* Localized email templates, branded assets, and database seeds.

Hardcoding configuration generators with string concatenations (`$config .= "..."`) leads to unmaintainable code that breaks whenever an infrastructure parameter changes.

### Template Directory Structure

```
stubs/tenant-infrastructure/
├── docker/
│   └── docker-compose.{{ tenant|kebab }}.yml.stub
├── webserver/
│   └── {{ tenant|kebab }}.caddy.stub
├── supervisor/
│   └── worker-{{ tenant|kebab }}.conf.stub
└── config/
    └── branding.json.stub
```

Inside `docker-compose.{{ tenant|kebab }}.yml.stub`:

```yaml
version: '3.8'

services:
  app-{{ tenant|kebab }}:
    image: {{ registry_url }}/app:{{ app_version }}
    container_name: {{ tenant|kebab }}_app
    environment:
      TENANT_ID: '{{ tenant|kebab }}'
      DB_DATABASE: '{{ tenant|snake }}_prod'
      CACHE_PREFIX: '{{ tenant|snake }}_'
      APP_URL: 'https://{{ domain }}'
    deploy:
      resources:
        limits:
          memory: {{ memory_limit }}
```

Inside `{{ tenant|kebab }}.caddy.stub`:

```caddyfile
{{ domain }} {
    tls {{ cert_email }}
    encode zstd gzip

    reverse_proxy app-{{ tenant|kebab }}:8000 {
        header_up Host {host}
        header_up X-Tenant-Id "{{ tenant|kebab }}"
    }
}
```

### Implementation & Provisioning Service

```php
namespace App\Services\Tenant;

use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Facades\StubEngine;
use App\Models\Tenant;

class TenantProvisioningService
{
    public function provision(Tenant $tenant): ScaffoldResult
    {
        $isDedicatedEnterprise = $tenant->is_enterprise;

        return StubEngine::scaffoldTree(
            sourceDir: resource_path('stubs/tenant-infrastructure'),
            targetDir: storage_path("tenants/{$tenant->slug}"),
            tokens: [
                'tenant' => $tenant->slug,
                'domain' => $tenant->primary_domain,
                'registry_url' => config('infrastructure.registry'),
                'app_version' => config('app.version'),
                'cert_email' => $tenant->admin_email,
                'memory_limit' => $tenant->plan->memory_limit ?? '1G',
            ],
            // Enterprise tenants can supply a completely customized template suite
            overrideDir: $isDedicatedEnterprise ? storage_path("templates/enterprise/{$tenant->slug}") : null,
            strategy: $isDedicatedEnterprise ? OverrideStrategy::Replace : OverrideStrategy::Overlay,
            strict: true,
        );
    }
}
```

### Why StubEngine Excels Here

* **Strict Token Validation:** `strict: true` prevents catastrophic infrastructure misconfigurations (such as launching a container with an unreplaced `{{ registry_url }}` or empty database name).
* **`Replace` Strategy for Enterprise Clients:** Standard tenants receive the default infrastructure layout, while enterprise tenants with custom architecture completely substitute the directory structure without vendor code changes.
* **Non-Destructive Dry Run:** Operators can preview generated deployment manifests (`dryRun: true`) and verify file counts prior to physical server provisioning.
