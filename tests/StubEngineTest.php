<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Engines\Interpolator;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Resolvers\StubResolver;
use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;

class StubEngineTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/stub_engine_test_'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_it_scaffolds_tree_with_token_replacements(): void
    {
        $stubsDir = "{$this->tempDir}/stubs";
        $targetDir = "{$this->tempDir}/output";

        $this->files->ensureDirectoryExists("{$stubsDir}/src");
        $this->files->put("{$stubsDir}/composer.json.stub", '{"name": "{{ vendor }}/{{ package }}"}');
        $this->files->put("{$stubsDir}/src/{{ ClassName }}.php.stub", 'class {{ ClassName }} {}');

        $engine = new StubEngine($this->files);
        $result = $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: $targetDir,
            tokens: [
                '{{ vendor }}' => 'acme',
                '{{ package }}' => 'tools',
                '{{ ClassName }}' => 'MyTool',
            ],
        );

        $this->assertSame(2, $result->fileCount);
        $this->assertFalse($result->isOverride);
        $this->assertFileExists("{$targetDir}/composer.json");
        $this->assertFileExists("{$targetDir}/src/MyTool.php");
        $this->assertStringEqualsFile("{$targetDir}/composer.json", '{"name": "acme/tools"}');
        $this->assertStringEqualsFile("{$targetDir}/src/MyTool.php", 'class MyTool {}');
    }

    public function test_host_override_directory_takes_precedence(): void
    {
        $defaultStubs = "{$this->tempDir}/default_stubs";
        $overrideStubs = "{$this->tempDir}/host_stubs";
        $targetDir = "{$this->tempDir}/output";

        $this->files->ensureDirectoryExists($defaultStubs);
        $this->files->put("{$defaultStubs}/file.txt.stub", 'default: {{ name }}');

        $this->files->ensureDirectoryExists($overrideStubs);
        $this->files->put("{$overrideStubs}/file.txt.stub", 'custom: {{ name }}');

        $engine = new StubEngine($this->files);
        $result = $engine->scaffoldTree(
            sourceDir: $defaultStubs,
            targetDir: $targetDir,
            tokens: ['{{ name }}' => 'alex'],
            overrideDir: $overrideStubs,
        );

        $this->assertTrue($result->isOverride);
        $this->assertStringEqualsFile("{$targetDir}/file.txt", 'custom: alex');
    }

    public function test_render_file_and_scaffold_file_with_host_override(): void
    {
        $defaultStub = "{$this->tempDir}/default.stub";
        $overrideStub = "{$this->tempDir}/override.stub";
        $targetFile = "{$this->tempDir}/target.txt";

        $this->files->put($defaultStub, 'Hello {{ name }}, default path: {{ path }}');
        $this->files->put($overrideStub, 'Hello {{ name }}, custom path: {{ path }}');

        $engine = new StubEngine($this->files);

        // 1. Render default without override
        $content = $engine->renderFile($defaultStub, ['{{ name }}' => 'Alex', '{{ path }}' => '/foo']);
        $this->assertSame('Hello Alex, default path: /foo', $content);

        // 2. Render with host override
        $contentOverride = $engine->renderFile($defaultStub, ['{{ name }}' => 'Alex', '{{ path }}' => '/foo'], $overrideStub);
        $this->assertSame('Hello Alex, custom path: /foo', $contentOverride);

        // 3. Scaffold file to disk
        $created = $engine->scaffoldFile($defaultStub, $targetFile, ['{{ name }}' => 'World', '{{ path }}' => '/bar']);
        $this->assertTrue($created);
        $this->assertFileExists($targetFile);
        $this->assertStringEqualsFile($targetFile, 'Hello World, default path: /bar');

        // 4. Skip without force
        $skipped = $engine->scaffoldFile($defaultStub, $targetFile, ['{{ name }}' => 'World', '{{ path }}' => '/bar'], force: false);
        $this->assertFalse($skipped);

        // 5. Overwrite with force
        $overwritten = $engine->scaffoldFile($defaultStub, $targetFile, ['{{ name }}' => 'Universe', '{{ path }}' => '/baz'], force: true);
        $this->assertTrue($overwritten);
        $this->assertStringEqualsFile($targetFile, 'Hello Universe, default path: /baz');
    }

    public function test_throws_exception_when_source_directory_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $engine = new StubEngine;
        $engine->scaffoldTree(
            sourceDir: "{$this->tempDir}/non_existent",
            targetDir: "{$this->tempDir}/out",
            tokens: [],
        );
    }

    public function test_cascading_partial_overlay_merges_override_with_defaults(): void
    {
        $defaultStubs = "{$this->tempDir}/default_stubs";
        $overrideStubs = "{$this->tempDir}/host_stubs";
        $targetDir = "{$this->tempDir}/output";

        // Default templates: 2 files
        $this->files->ensureDirectoryExists($defaultStubs);
        $this->files->put("{$defaultStubs}/base.txt.stub", 'base: {{ name }}');
        $this->files->put("{$defaultStubs}/shared.txt.stub", 'default shared: {{ name }}');

        // Host overrides: 1 override + 1 extra
        $this->files->ensureDirectoryExists($overrideStubs);
        $this->files->put("{$overrideStubs}/shared.txt.stub", 'override shared: {{ name }}');
        $this->files->put("{$overrideStubs}/extra.txt.stub", 'extra: {{ name }}');

        $engine = new StubEngine($this->files);
        $result = $engine->scaffoldTree(
            sourceDir: $defaultStubs,
            targetDir: $targetDir,
            tokens: ['{{ name }}' => 'Laravel'],
            overrideDir: $overrideStubs,
        );

        $this->assertSame(3, $result->totalFiles());
        $this->assertTrue($result->hasOverrides());
        $this->assertCount(2, $result->overrideFiles);
        $this->assertContains('shared.txt', $result->overrideFiles);
        $this->assertContains('extra.txt', $result->overrideFiles);

        // base.txt should come from default
        $this->assertStringEqualsFile("{$targetDir}/base.txt", 'base: Laravel');
        // shared.txt should come from host override
        $this->assertStringEqualsFile("{$targetDir}/shared.txt", 'override shared: Laravel');
        // extra.txt should come from host override
        $this->assertStringEqualsFile("{$targetDir}/extra.txt", 'extra: Laravel');
    }

    public function test_scaffold_tree_handles_force_and_skips_existing_files(): void
    {
        $stubsDir = "{$this->tempDir}/stubs";
        $targetDir = "{$this->tempDir}/output";

        $this->files->ensureDirectoryExists($stubsDir);
        $this->files->put("{$stubsDir}/file1.txt.stub", 'new 1');
        $this->files->put("{$stubsDir}/file2.txt.stub", 'new 2');

        $this->files->ensureDirectoryExists($targetDir);
        $this->files->put("{$targetDir}/file1.txt", 'existing content');

        $engine = new StubEngine($this->files);

        // 1. Without force (skip existing)
        $skipResult = $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: $targetDir,
            tokens: [],
            force: false,
        );

        $this->assertTrue($skipResult->hasSkipped());
        $this->assertSame(['file1.txt'], $skipResult->skippedFiles);
        $this->assertSame(['file2.txt'], $skipResult->createdFiles);
        $this->assertStringEqualsFile("{$targetDir}/file1.txt", 'existing content');
        $this->assertStringEqualsFile("{$targetDir}/file2.txt", 'new 2');

        // 2. With force (overwrite existing)
        $forceResult = $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: $targetDir,
            tokens: [],
            force: true,
        );

        $this->assertTrue($forceResult->hasOverwritten());
        $this->assertContains('file1.txt', $forceResult->overwrittenFiles);
        $this->assertStringEqualsFile("{$targetDir}/file1.txt", 'new 1');
    }

    public function test_dry_run_does_not_write_to_disk(): void
    {
        $stubsDir = "{$this->tempDir}/stubs";
        $targetDir = "{$this->tempDir}/output";

        $this->files->ensureDirectoryExists($stubsDir);
        $this->files->put("{$stubsDir}/file.txt.stub", 'content: {{ name }}');

        $engine = new StubEngine($this->files);

        // Single file dry run
        $fileScaffolded = $engine->scaffoldFile(
            sourceFile: "{$stubsDir}/file.txt.stub",
            targetFile: "{$targetDir}/standalone.txt",
            tokens: ['{{ name }}' => 'Test'],
            dryRun: true,
        );
        $this->assertTrue($fileScaffolded);
        $this->assertFileDoesNotExist("{$targetDir}/standalone.txt");

        // Directory tree dry run
        $treeResult = $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: $targetDir,
            tokens: ['{{ name }}' => 'Test'],
            dryRun: true,
        );

        $this->assertTrue($treeResult->dryRun);
        $this->assertSame(1, $treeResult->totalFiles());
        $this->assertSame(['file.txt'], $treeResult->createdFiles);
        $this->assertFileDoesNotExist("{$targetDir}/file.txt");
    }

    public function test_token_modifiers_and_prefix_collision_resistance(): void
    {
        $engine = new StubEngine($this->files);

        // 1. Collision resistance
        $templateWithCollisions = 'ID: {{ item_id }}, Name: {{ item }}';
        $renderedCollisions = $engine->interpolate($templateWithCollisions, [
            '{{ item }}' => 'pencil',
            '{{ item_id }}' => '987',
        ]);
        $this->assertSame('ID: 987, Name: pencil', $renderedCollisions);

        // 2. Case modifiers
        $templateWithModifiers = 'Class: {{ entity|studly }}, table: {{ entity|snake }}, route: {{ entity|kebab }}, var: {{ entity|camel }}, title: {{ entity|title }}, plural: {{ entity|plural }}';
        $renderedModifiers = $engine->interpolate($templateWithModifiers, [
            'entity' => 'user profile',
        ]);

        $this->assertSame(
            'Class: UserProfile, table: user_profile, route: user-profile, var: userProfile, title: User Profile, plural: user profiles',
            $renderedModifiers
        );
    }

    public function test_scaffold_tree_with_replace_strategy_completely_substitutes_source(): void
    {
        $defaultStubs = "{$this->tempDir}/default_stubs";
        $overrideStubs = "{$this->tempDir}/host_stubs";
        $targetDir = "{$this->tempDir}/output";

        // Default: 2 files
        $this->files->ensureDirectoryExists($defaultStubs);
        $this->files->put("{$defaultStubs}/default_one.txt.stub", 'default 1');
        $this->files->put("{$defaultStubs}/default_two.txt.stub", 'default 2');

        // Override: completely different structure (1 file only)
        $this->files->ensureDirectoryExists($overrideStubs);
        $this->files->put("{$overrideStubs}/custom_only.txt.stub", 'custom only content');

        $engine = new StubEngine($this->files);
        $result = $engine->scaffoldTree(
            sourceDir: $defaultStubs,
            targetDir: $targetDir,
            tokens: [],
            overrideDir: $overrideStubs,
            strategy: OverrideStrategy::Replace,
        );

        $this->assertTrue($result->isReplace());
        $this->assertFalse($result->isOverlay());
        $this->assertSame(1, $result->totalFiles());
        $this->assertFileExists("{$targetDir}/custom_only.txt");
        $this->assertFileDoesNotExist("{$targetDir}/default_one.txt");
        $this->assertFileDoesNotExist("{$targetDir}/default_two.txt");
    }

    public function test_custom_delimiters_via_runtime_arguments(): void
    {
        $engine = new StubEngine($this->files);

        $template = 'Hello <% name|studly %> and [[ other ]]';
        $rendered = $engine->interpolate(
            content: $template,
            tokens: ['name' => 'alex kassel'],
            openDelimiter: '<%',
            closeDelimiter: '%>',
        );

        $this->assertSame('Hello AlexKassel and [[ other ]]', $rendered);
    }

    public function test_custom_delimiters_and_global_tokens_via_config(): void
    {
        $this->app['config']->set('stub-engine.delimiters', [
            'open' => '[[',
            'close' => ']]',
        ]);
        $this->app['config']->set('stub-engine.global_tokens', [
            'company' => 'Acme Global',
            'year' => '2026',
        ]);

        /** @var StubEngine $engine */
        $engine = $this->app->make(StubEngine::class);

        $template = 'Company: [[ company|kebab ]], Year: [[ year ]], User: [[ user|studly ]]';
        $rendered = $engine->interpolate($template, [
            'user' => 'john doe',
        ]);

        $this->assertSame('Company: acme-global, Year: 2026, User: JohnDoe', $rendered);
    }

    public function test_scaffold_tree_with_custom_delimiters_in_paths_and_content(): void
    {
        $stubsDir = "{$this->tempDir}/custom_delim_stubs";
        $targetDir = "{$this->tempDir}/custom_delim_output";

        $this->files->ensureDirectoryExists("{$stubsDir}/src");
        $this->files->put("{$stubsDir}/src/<% module|studly %>.php.stub", 'namespace App\\<% module|studly %>; class <% module|studly %> {}');

        $engine = new StubEngine($this->files);
        $result = $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: $targetDir,
            tokens: ['module' => 'billing service'],
            openDelimiter: '<%',
            closeDelimiter: '%>',
        );

        $this->assertSame(1, $result->totalFiles());
        $this->assertFileExists("{$targetDir}/src/BillingService.php");
        $this->assertStringEqualsFile("{$targetDir}/src/BillingService.php", 'namespace App\BillingService; class BillingService {}');
    }

    public function test_facade_and_container_binding(): void
    {
        $stub = "{$this->tempDir}/facade.stub";
        $this->files->put($stub, 'Hello {{ name }}');

        $content = \AlexKassel\StubEngine\Facades\StubEngine::renderFile($stub, ['{{ name }}' => 'Laravel']);
        $this->assertSame('Hello Laravel', $content);

        $instance = $this->app->make(StubEngine::class);
        $this->assertInstanceOf(StubEngine::class, $instance);
    }

    public function test_custom_token_modifier_registration(): void
    {
        $engine = new StubEngine($this->files);
        $engine->registerModifier('reverse', fn (string $val): string => strrev($val));
        $engine->registerModifier('slug', fn (string $val): string => strtolower(str_replace(' ', '-', $val)));

        $template = 'Reversed: {{ name|reverse }}, Slug: {{ name|slug }}';
        $rendered = $engine->interpolate($template, ['name' => 'John Doe']);

        $this->assertSame('Reversed: eoD nhoJ, Slug: john-doe', $rendered);
    }

    public function test_path_traversal_protection_in_scaffold_tree(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attempts directory traversal outside target directory');

        $stubsDir = "{$this->tempDir}/traversal_stubs";
        $targetDir = "{$this->tempDir}/safe_output";

        $this->files->ensureDirectoryExists("{$stubsDir}/{{ target }}");
        $this->files->put("{$stubsDir}/{{ target }}/file.txt.stub", 'malicious');

        $engine = new StubEngine($this->files);
        $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: $targetDir,
            tokens: ['target' => '../../outside'],
        );
    }

    public function test_raw_assets_copied_without_interpolation_and_ignored_files_skipped(): void
    {
        $stubsDir = "{$this->tempDir}/mixed_stubs";
        $targetDir = "{$this->tempDir}/mixed_output";

        $this->files->ensureDirectoryExists($stubsDir);
        $this->files->put("{$stubsDir}/template.txt.stub", 'interpolated: {{ name }}');
        $this->files->put("{$stubsDir}/binary_asset.bin", 'raw asset: {{ name }} should remain unchanged');
        $this->files->put("{$stubsDir}/.DS_Store", 'junk file');
        $this->files->put("{$stubsDir}/.gitkeep", '');

        $engine = new StubEngine($this->files);
        $result = $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: $targetDir,
            tokens: ['name' => 'Laravel'],
        );

        $this->assertSame(2, $result->totalFiles());
        $this->assertTrue($result->hasRawCopied());
        $this->assertContains('binary_asset.bin', $result->rawCopiedFiles);
        $this->assertFileExists("{$targetDir}/template.txt");
        $this->assertFileExists("{$targetDir}/binary_asset.bin");
        $this->assertFileDoesNotExist("{$targetDir}/.DS_Store");
        $this->assertFileDoesNotExist("{$targetDir}/.gitkeep");

        $this->assertStringEqualsFile("{$targetDir}/template.txt", 'interpolated: Laravel');
        $this->assertStringEqualsFile("{$targetDir}/binary_asset.bin", 'raw asset: {{ name }} should remain unchanged');
    }

    public function test_find_unresolved_tokens_and_strict_mode(): void
    {
        $engine = new StubEngine($this->files);

        $content = 'Hello {{ name }}, role: {{ role }}, age: {{ age|studly }}';
        $missing = $engine->findUnresolvedTokens($content);

        $this->assertCount(3, $missing);
        $this->assertContains('{{ name }}', $missing);
        $this->assertContains('{{ role }}', $missing);
        $this->assertContains('{{ age|studly }}', $missing);

        $stubsDir = "{$this->tempDir}/strict_stubs";
        $targetDir = "{$this->tempDir}/strict_output";

        $this->files->ensureDirectoryExists($stubsDir);
        $this->files->put("{$stubsDir}/strict.txt.stub", 'Hello {{ defined_token }} and {{ missing_token }}');

        // Without strict mode, tracks unresolved tokens in ScaffoldResult
        $result = $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: $targetDir,
            tokens: ['defined_token' => 'World'],
            strict: false,
        );

        $this->assertTrue($result->hasUnresolvedTokens());
        $this->assertArrayHasKey('strict.txt', $result->unresolvedTokens);
        $this->assertContains('{{ missing_token }}', $result->unresolvedTokens['strict.txt']);

        // With strict mode, throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unresolved tokens in [strict.txt]: {{ missing_token }}');

        $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: "{$this->tempDir}/strict_output_fail",
            tokens: ['defined_token' => 'World'],
            strict: true,
        );
    }

    public function test_standalone_engine_without_laravel_container(): void
    {
        Container::setInstance(null);
        Facade::setFacadeApplication(null);

        $engine = new StubEngine(
            files: new Filesystem,
            config: [
                'delimiters' => [
                    'open' => '<<',
                    'close' => '>>',
                ],
                'global_tokens' => [
                    'app' => 'Standalone',
                ],
            ],
        );

        $rendered = $engine->interpolate('Framework: << app >>, Version: << v >>', ['v' => '1.0']);
        $this->assertSame('Framework: Standalone, Version: 1.0', $rendered);
    }

    public function test_modifier_chaining(): void
    {
        $engine = new StubEngine($this->files);

        $template = 'Table: {{ entity | snake | plural }}, UpperSnake: {{ entity | snake | upper }}, Class: {{ entity | camel | studly }}';
        $rendered = $engine->interpolate($template, [
            'entity' => 'UserProfile',
        ]);

        $this->assertSame('Table: user_profiles, UpperSnake: USER_PROFILE, Class: UserProfile', $rendered);
    }

    public function test_whitespace_resilience_in_modifier_expressions(): void
    {
        $engine = new StubEngine($this->files);

        $template = 'A: {{ name | studly}}, B: {{name | studly }}, C: {{  name  |  snake  |  plural  }}, D: {{name|upper}}';
        $rendered = $engine->interpolate($template, [
            'name' => 'order item',
        ]);

        $this->assertSame('A: OrderItem, B: OrderItem, C: order_items, D: ORDER ITEM', $rendered);
    }

    public function test_parameterized_modifiers(): void
    {
        $engine = new StubEngine($this->files);
        $engine->registerModifier('prefix', fn (string $val, string $p = ''): string => $p.$val);
        $engine->registerModifier('wrap', fn (string $val, string $before = '', string $after = ''): string => $before.$val.$after);

        $template = 'Prefixed: {{ code | prefix:SKU_ }}, Wrapped: {{ code | wrap:[,!] }}';
        $rendered = $engine->interpolate($template, [
            'code' => '12345',
        ]);

        $this->assertSame('Prefixed: SKU_12345, Wrapped: [12345!]', $rendered);
    }

    public function test_scaffold_file_with_relative_path_in_current_directory(): void
    {
        $engine = new StubEngine($this->files);
        $sourceStub = "{$this->tempDir}/standalone.stub";
        $this->files->put($sourceStub, 'Content: {{ key }}');

        // Target file with bare relative path inside current directory
        $targetFile = 'test_relative_output_'.uniqid().'.txt';
        try {
            $created = $engine->scaffoldFile(
                sourceFile: $sourceStub,
                targetFile: $targetFile,
                tokens: ['key' => 'Success'],
            );

            $this->assertTrue($created);
            $this->assertFileExists($targetFile);
            $this->assertStringEqualsFile($targetFile, 'Content: Success');
        } finally {
            if ($this->files->exists($targetFile)) {
                $this->files->delete($targetFile);
            }
        }
    }

    public function test_scaffold_file_prevents_relative_directory_traversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attempts directory traversal outside target directory');

        $engine = new StubEngine($this->files);
        $sourceStub = "{$this->tempDir}/source.stub";
        $this->files->put($sourceStub, 'content');

        $engine->scaffoldFile(
            sourceFile: $sourceStub,
            targetFile: '../escaped_relative_file.txt',
            tokens: [],
        );
    }

    public function test_scaffold_file_prevents_absolute_directory_traversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attempts directory traversal outside target directory');

        $engine = new StubEngine($this->files);
        $sourceStub = "{$this->tempDir}/source.stub";
        $this->files->put($sourceStub, 'content');

        $engine->scaffoldFile(
            sourceFile: $sourceStub,
            targetFile: "{$this->tempDir}/sub/../../../../etc/malicious_cron",
            tokens: [],
        );
    }

    public function test_scaffold_file_respects_explicit_target_dir_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attempts directory traversal outside target directory');

        $engine = new StubEngine($this->files);
        $sourceStub = "{$this->tempDir}/source.stub";
        $this->files->put($sourceStub, 'content');

        $engine->scaffoldFile(
            sourceFile: $sourceStub,
            targetFile: "{$this->tempDir}/outside/escaped.txt",
            tokens: [],
            targetDir: "{$this->tempDir}/allowed_zone",
        );
    }

    public function test_find_unresolved_tokens_captures_placeholders_with_spaces_and_chains(): void
    {
        $engine = new StubEngine($this->files);

        $template = 'Hello {{ missing_var | snake | plural }} and {{  another_missing  }}';
        $unresolved = $engine->findUnresolvedTokens($template);

        $this->assertCount(2, $unresolved);
        $this->assertContains('{{ missing_var | snake | plural }}', $unresolved);
        $this->assertContains('{{  another_missing  }}', $unresolved);
    }

    public function test_blade_template_escape_and_verbatim_syntax(): void
    {
        $engine = new StubEngine($this->files);

        $template = '<h1>{{ title }}</h1><div>@{{ $user->name }}</div><span>@{{ route(\'profile\', [\'id\' => $user->id]) }}</span>';
        $rendered = $engine->interpolate($template, [
            'title' => 'User Dashboard',
        ]);

        $this->assertSame(
            '<h1>User Dashboard</h1><div>{{ $user->name }}</div><span>{{ route(\'profile\', [\'id\' => $user->id]) }}</span>',
            $rendered
        );
    }

    public function test_blade_escaping_in_strict_mode_does_not_trigger_unresolved_exception(): void
    {
        $engine = new StubEngine($this->files);

        $stubsDir = "{$this->tempDir}/blade_stubs";
        $targetDir = "{$this->tempDir}/blade_output";

        $this->files->ensureDirectoryExists($stubsDir);
        $this->files->put(
            "{$stubsDir}/view.blade.php.stub",
            '<x-layout title="{{ pageTitle }}"><p>@{{ $post->title }}</p></x-layout>'
        );

        // In strict mode, escaped Blade syntax should NOT trigger an exception
        $result = $engine->scaffoldTree(
            sourceDir: $stubsDir,
            targetDir: $targetDir,
            tokens: ['pageTitle' => 'Blog Post'],
            strict: true,
        );

        $this->assertSame(1, $result->totalFiles());
        $this->assertFalse($result->hasUnresolvedTokens());
        $this->assertFileExists("{$targetDir}/view.blade.php");
        $this->assertStringEqualsFile(
            "{$targetDir}/view.blade.php",
            '<x-layout title="Blog Post"><p>{{ $post->title }}</p></x-layout>'
        );
    }

    public function test_find_unresolved_tokens_ignores_blade_escaped_expressions(): void
    {
        $engine = new StubEngine($this->files);

        $template = 'Valid: {{ resolved }}, Escaped: @{{ $blade_var }}, Missing: {{ missing_var }}';
        $unresolved = $engine->findUnresolvedTokens($template);

        $this->assertCount(2, $unresolved);
        $this->assertContains('{{ resolved }}', $unresolved);
        $this->assertContains('{{ missing_var }}', $unresolved);
        $this->assertNotContains('@{{ $blade_var }}', $unresolved);
        $this->assertNotContains('{{ $blade_var }}', $unresolved);
    }

    public function test_built_in_parameterized_modifiers(): void
    {
        $engine = new StubEngine($this->files);

        $template = implode("\n", [
            'DefaultSet: {{ fallback | default:AcmeCorp }}',
            'DefaultEmpty: {{ empty_var | default:FallbackValue }}',
            'FormatDate: {{ timestamp | format:Y }}',
            'Replace: {{ win_path | replace:\\,/ }}',
            'Limit: {{ long_text | limit:10,... }}',
            'WrapSingle: {{ tag | wrap:" }}',
            'WrapDouble: {{ block | wrap:[,!] }}',
            'Trim: {{ padded | trim:_ }}',
            'ChainedWithParams: {{ model | default:billing_item | snake | plural }}',
        ]);

        $rendered = $engine->interpolate($template, [
            'fallback' => 'CustomOrg',
            'empty_var' => '',
            'timestamp' => '2026-09-16 12:00:00',
            'win_path' => 'app\\Domain\\Models',
            'long_text' => 'The quick brown fox jumps over the lazy dog',
            'tag' => 'header',
            'block' => 'ALERT',
            'padded' => '__clean__',
            'model' => '',
        ]);

        $this->assertStringContainsString('DefaultSet: CustomOrg', $rendered);
        $this->assertStringContainsString('DefaultEmpty: FallbackValue', $rendered);
        $this->assertStringContainsString('FormatDate: 2026', $rendered);
        $this->assertStringContainsString('Replace: app/Domain/Models', $rendered);
        $this->assertStringContainsString('Limit: The quick...', $rendered);
        $this->assertStringContainsString('WrapSingle: "header"', $rendered);
        $this->assertStringContainsString('WrapDouble: [ALERT!]', $rendered);
        $this->assertStringContainsString('Trim: clean', $rendered);
        $this->assertStringContainsString('ChainedWithParams: billing_items', $rendered);
    }

    public function test_stub_engine_is_macroable(): void
    {
        StubEngine::macro('generateBanner', function (string $title): string {
            /** @var StubEngine $this */
            return $this->interpolate('=== {{ title|upper }} ===', ['title' => $title]);
        });

        $engine = new StubEngine($this->files);
        $result = $engine->generateBanner('welcome');

        $this->assertSame('=== WELCOME ===', $result);
    }

    public function test_component_accessors(): void
    {
        $engine = new StubEngine($this->files);

        $this->assertSame($this->files, $engine->filesystem());
        $this->assertInstanceOf(Interpolator::class, $engine->interpolator());
        $this->assertInstanceOf(StubResolver::class, $engine->resolver());
    }
}
