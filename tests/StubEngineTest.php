<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\StubEngine;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\Filesystem;
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

        $engine = $this->engine();
        $result = $engine->scaffold(new ScaffoldRequest(
            source: $stubsDir,
            target: $targetDir,
            tokens: [
                '{{ vendor }}' => 'acme',
                '{{ package }}' => 'tools',
                '{{ ClassName }}' => 'MyTool',
            ],
        ));

        $this->assertSame(2, $result->fileCount);
        $this->assertEmpty($result->overrideFiles);
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

        $engine = $this->engine();
        $result = $engine->scaffold(new ScaffoldRequest(
            source: $defaultStubs,
            target: $targetDir,
            tokens: ['{{ name }}' => 'alex'],
            override: $overrideStubs,
        ));

        $this->assertNotEmpty($result->overrideFiles);
        $this->assertStringEqualsFile("{$targetDir}/file.txt", 'custom: alex');
    }

    public function test_render_file_and_scaffold_file_with_host_override(): void
    {
        $defaultStub = "{$this->tempDir}/default.stub";
        $overrideStub = "{$this->tempDir}/override.stub";
        $targetFile = "{$this->tempDir}/target.txt";

        $this->files->put($defaultStub, 'Hello {{ name }}, default path: {{ path }}');
        $this->files->put($overrideStub, 'Hello {{ name }}, custom path: {{ path }}');

        $engine = $this->engine();

        // 1. Render default without override
        $content = $engine->renderFile(new ScaffoldRequest(source: $defaultStub, tokens: ['{{ name }}' => 'Alex', '{{ path }}' => '/foo']));
        $this->assertSame('Hello Alex, default path: /foo', $content);

        // 2. Render with host override
        $contentOverride = $engine->renderFile(new ScaffoldRequest(source: $defaultStub, tokens: ['{{ name }}' => 'Alex', '{{ path }}' => '/foo'], override: $overrideStub));
        $this->assertSame('Hello Alex, custom path: /foo', $contentOverride);

        // 3. Scaffold file to disk
        $created = $engine->scaffold(new ScaffoldRequest(
            source: $defaultStub,
            target: $targetFile,
            tokens: ['{{ name }}' => 'World', '{{ path }}' => '/bar'],
        ));
        $this->assertNotEmpty($created->createdFiles);
        $this->assertFileExists($targetFile);
        $this->assertStringEqualsFile($targetFile, 'Hello World, default path: /bar');

        // 4. Skip without force
        $skipped = $engine->scaffold(new ScaffoldRequest(
            source: $defaultStub,
            target: $targetFile,
            tokens: ['{{ name }}' => 'World', '{{ path }}' => '/bar'],
            force: false,
        ));
        $this->assertEmpty($skipped->renderedFiles);
        $this->assertNotEmpty($skipped->skippedFiles);

        // 5. Overwrite with force
        $overwritten = $engine->scaffold(new ScaffoldRequest(
            source: $defaultStub,
            target: $targetFile,
            tokens: ['{{ name }}' => 'Universe', '{{ path }}' => '/baz'],
            force: true,
        ));
        $this->assertNotEmpty($overwritten->renderedFiles);
        $this->assertNotEmpty($overwritten->overwrittenFiles);
        $this->assertStringEqualsFile($targetFile, 'Hello Universe, default path: /baz');
    }

    public function test_throws_exception_when_source_directory_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $engine = $this->engine();
        $engine->scaffold(new ScaffoldRequest(
            source: "{$this->tempDir}/non_existent",
            target: "{$this->tempDir}/out",
        ));
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

        $engine = $this->engine();
        $result = $engine->scaffold(new ScaffoldRequest(
            source: $defaultStubs,
            target: $targetDir,
            tokens: ['{{ name }}' => 'Laravel'],
            override: $overrideStubs,
        ));

        $this->assertSame(3, count($result));
        $this->assertNotEmpty($result->overrideFiles);
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

        $engine = $this->engine();

        // 1. Without force (skip existing)
        $skipResult = $engine->scaffold(new ScaffoldRequest(
            source: $stubsDir,
            target: $targetDir,
            force: false,
        ));

        $this->assertNotEmpty($skipResult->skippedFiles);
        $this->assertSame(['file1.txt'], $skipResult->skippedFiles);
        $this->assertSame(['file2.txt'], $skipResult->createdFiles);
        $this->assertStringEqualsFile("{$targetDir}/file1.txt", 'existing content');
        $this->assertStringEqualsFile("{$targetDir}/file2.txt", 'new 2');

        // 2. With force (overwrite existing)
        $forceResult = $engine->scaffold(new ScaffoldRequest(
            source: $stubsDir,
            target: $targetDir,
            force: true,
        ));

        $this->assertNotEmpty($forceResult->overwrittenFiles);
        $this->assertContains('file1.txt', $forceResult->overwrittenFiles);
        $this->assertStringEqualsFile("{$targetDir}/file1.txt", 'new 1');
    }

    public function test_dry_run_does_not_write_to_disk(): void
    {
        $stubsDir = "{$this->tempDir}/stubs";
        $targetDir = "{$this->tempDir}/output";

        $this->files->ensureDirectoryExists($stubsDir);
        $this->files->put("{$stubsDir}/file.txt.stub", 'content: {{ name }}');

        $engine = $this->engine();

        // Single file dry run
        $fileResult = $engine->scaffold(new ScaffoldRequest(
            source: "{$stubsDir}/file.txt.stub",
            target: "{$targetDir}/standalone.txt",
            tokens: ['{{ name }}' => 'Test'],
            dryRun: true,
        ));
        $this->assertTrue($fileResult->request->dryRun);
        $this->assertNotEmpty($fileResult->renderedFiles);
        $this->assertFileDoesNotExist("{$targetDir}/standalone.txt");

        // Directory tree dry run
        $treeResult = $engine->scaffold(new ScaffoldRequest(
            source: $stubsDir,
            target: $targetDir,
            tokens: ['{{ name }}' => 'Test'],
            dryRun: true,
        ));

        $this->assertTrue($treeResult->request->dryRun);
        $this->assertSame(1, count($treeResult));
        $this->assertSame(['file.txt'], $treeResult->createdFiles);
        $this->assertFileDoesNotExist("{$targetDir}/file.txt");
    }

    public function test_token_modifiers_and_prefix_collision_resistance(): void
    {
        $interpolator = $this->interpolator();

        // 1. Collision resistance
        $templateWithCollisions = 'ID: {{ item_id }}, Name: {{ item }}';
        $renderedCollisions = $interpolator->interpolate($templateWithCollisions, new ScaffoldRequest(
            source: 'memory',
            tokens: [
                'item' => 'pencil',
                'item_id' => '987',
            ],
        ));
        $this->assertSame('ID: 987, Name: pencil', $renderedCollisions);

        // 2. Case modifiers
        $templateWithModifiers = 'Class: {{ entity|studly }}, table: {{ entity|snake }}, route: {{ entity|kebab }}, var: {{ entity|camel }}, title: {{ entity|title }}, plural: {{ entity|plural }}';
        $renderedModifiers = $interpolator->interpolate($templateWithModifiers, new ScaffoldRequest(
            source: 'memory',
            tokens: [
                'entity' => 'user profile',
            ],
        ));

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

        $engine = $this->engine();
        $result = $engine->scaffold(new ScaffoldRequest(
            source: $defaultStubs,
            target: $targetDir,
            override: $overrideStubs,
            strategy: OverrideStrategy::Replace,
        ));

        $this->assertSame(OverrideStrategy::Replace, $result->request->strategy);
        $this->assertSame(1, count($result));
        $this->assertFileExists("{$targetDir}/custom_only.txt");
        $this->assertFileDoesNotExist("{$targetDir}/default_one.txt");
        $this->assertFileDoesNotExist("{$targetDir}/default_two.txt");
    }

    public function test_custom_delimiters_via_runtime_arguments(): void
    {
        $interpolator = $this->interpolator();

        $template = 'Hello <% name|studly %> and [[ other ]]';
        $rendered = $interpolator->interpolate(
            content: $template,
            request: new ScaffoldRequest(
                source: 'memory',
                tokens: ['name' => 'alex kassel'],
                openDelimiter: '<%',
                closeDelimiter: '%>',
            ),
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

        $interpolator = $this->interpolator();

        $template = 'Company: [[ company|kebab ]], Year: [[ year ]], User: [[ user|studly ]]';
        $rendered = $interpolator->interpolate($template, new ScaffoldRequest(
            source: 'memory',
            tokens: ['user' => 'john doe'],
        ));

        $this->assertSame('Company: acme-global, Year: 2026, User: JohnDoe', $rendered);
    }

    public function test_scaffold_tree_with_custom_delimiters_in_paths_and_content(): void
    {
        $stubsDir = "{$this->tempDir}/custom_delim_stubs";
        $targetDir = "{$this->tempDir}/custom_delim_output";

        $this->files->ensureDirectoryExists("{$stubsDir}/src");
        $this->files->put("{$stubsDir}/src/<% module|studly %>.php.stub", 'namespace App\\<% module|studly %>; class <% module|studly %> {}');

        $engine = $this->engine();
        $result = $engine->scaffold(new ScaffoldRequest(
            source: $stubsDir,
            target: $targetDir,
            tokens: ['module' => 'billing service'],
            openDelimiter: '<%',
            closeDelimiter: '%>',
        ));

        $this->assertSame(1, count($result));
        $this->assertFileExists("{$targetDir}/src/BillingService.php");
        $this->assertStringEqualsFile("{$targetDir}/src/BillingService.php", 'namespace App\BillingService; class BillingService {}');
    }

    public function test_facade_and_container_binding(): void
    {
        $stub = "{$this->tempDir}/facade.stub";
        $this->files->put($stub, 'Hello {{ name }}');

        $content = \AlexKassel\StubEngine\Facades\StubEngine::from($stub)
            ->withTokens(['{{ name }}' => 'Laravel'])
            ->renderFile();
        $this->assertSame('Hello Laravel', $content);

        $instance = $this->app->make(StubEngine::class);
        $this->assertInstanceOf(StubEngine::class, $instance);
    }

    public function test_custom_token_modifier_registration(): void
    {
        $engine = $this->engine();
        $engine->registerModifier('reverse', fn (string $val): string => strrev($val));
        $engine->registerModifier('slug', fn (string $val): string => strtolower(str_replace(' ', '-', $val)));

        $stubFile = "{$this->tempDir}/modifier_test.stub";
        $this->files->put($stubFile, 'Reversed: {{ name|reverse }}, Slug: {{ name|slug }}');

        $rendered = $this->engine()->renderFile(new ScaffoldRequest($stubFile, tokens: ['name' => 'John Doe']));

        $this->assertSame('Reversed: eoD nhoJ, Slug: john-doe', $rendered);
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

        $engine = $this->engine();
        $result = $engine->scaffold(new ScaffoldRequest(
            source: $stubsDir,
            target: $targetDir,
            tokens: ['name' => 'Laravel'],
            ignoredFiles: ['.DS_Store', '.gitkeep'],
        ));

        $this->assertSame(2, count($result));
        $this->assertNotEmpty($result->rawCopiedFiles);
        $this->assertContains('binary_asset.bin', $result->rawCopiedFiles);
        $this->assertFileExists("{$targetDir}/template.txt");
        $this->assertFileExists("{$targetDir}/binary_asset.bin");
        $this->assertFileDoesNotExist("{$targetDir}/.DS_Store");
        $this->assertFileDoesNotExist("{$targetDir}/.gitkeep");

        $this->assertStringEqualsFile("{$targetDir}/template.txt", 'interpolated: Laravel');
        $this->assertStringEqualsFile("{$targetDir}/binary_asset.bin", 'raw asset: {{ name }} should remain unchanged');
    }

    public function test_extract_tokens_and_strict_mode(): void
    {
        $engine = $this->engine();

        $content = 'Hello {{ name }}, role: {{ role }}, age: {{ age|studly }}';
        $missing = $engine->extractTokens($content);

        $this->assertSame(['name', 'role', 'age'], $missing);

        $stubsDir = "{$this->tempDir}/strict_stubs";
        $targetDir = "{$this->tempDir}/strict_output";

        $this->files->ensureDirectoryExists($stubsDir);
        $this->files->put("{$stubsDir}/strict.txt.stub", 'Hello {{ defined_token }} and {{ missing_token }}');

        // Without strict mode, tracks unresolved tokens in ScaffoldResult
        $result = $engine->scaffold(new ScaffoldRequest(
            source: $stubsDir,
            target: $targetDir,
            tokens: ['defined_token' => 'World'],
            strict: false,
        ));

        $this->assertNotEmpty($result->unresolvedTokens);
        $this->assertArrayHasKey('strict.txt', $result->unresolvedTokens);
        $this->assertContains('{{ missing_token }}', $result->unresolvedTokens['strict.txt']);

        // With strict mode, throws InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unresolved tokens in [strict.txt]: {{ missing_token }}');

        $engine->scaffold(new ScaffoldRequest(
            source: $stubsDir,
            target: "{$this->tempDir}/strict_output_fail",
            tokens: ['defined_token' => 'World'],
            strict: true,
        ));
    }

    public function test_modifier_chaining(): void
    {
        $interpolator = $this->interpolator();

        $template = 'Table: {{ entity | snake | plural }}, UpperSnake: {{ entity | snake | upper }}, Class: {{ entity | camel | studly }}';
        $rendered = $interpolator->interpolate($template, new ScaffoldRequest(
            source: 'memory',
            tokens: ['entity' => 'UserProfile'],
        ));

        $this->assertSame('Table: user_profiles, UpperSnake: USER_PROFILE, Class: UserProfile', $rendered);
    }

    public function test_whitespace_resilience_in_modifier_expressions(): void
    {
        $interpolator = $this->interpolator();

        $template = 'A: {{ name | studly}}, B: {{name | studly }}, C: {{  name  |  snake  |  plural  }}, D: {{name|upper}}';
        $rendered = $interpolator->interpolate($template, new ScaffoldRequest(
            source: 'memory',
            tokens: ['name' => 'order item'],
        ));

        $this->assertSame('A: OrderItem, B: OrderItem, C: order_items, D: ORDER ITEM', $rendered);
    }

    public function test_custom_string_modifiers(): void
    {
        $interpolator = $this->interpolator();
        $interpolator->registerModifier('sku', fn (string $val): string => 'SKU_'.$val);
        $interpolator->registerModifier('bracket', fn (string $val): string => '['.$val.']');

        $template = 'Prefixed: {{ code | sku }}, Bracketed: {{ code | bracket }}';
        $rendered = $interpolator->interpolate($template, new ScaffoldRequest(
            source: 'memory',
            tokens: ['code' => '12345'],
        ));

        $this->assertSame('Prefixed: SKU_12345, Bracketed: [12345]', $rendered);
    }

    public function test_scaffold_file_with_relative_path_in_current_directory(): void
    {
        $engine = $this->engine();
        $sourceStub = "{$this->tempDir}/standalone.stub";
        $this->files->put($sourceStub, 'Content: {{ key }}');

        // Target file with bare relative path inside current directory
        $targetFile = 'test_relative_output_'.uniqid().'.txt';
        try {
            $result = $engine->scaffold(new ScaffoldRequest(
                source: $sourceStub,
                target: $targetFile,
                tokens: ['key' => 'Success'],
            ));

            $this->assertNotEmpty($result->createdFiles);
            $this->assertCount(1, $result->createdFiles);
            $this->assertFileExists($targetFile);
            $this->assertStringEqualsFile($targetFile, 'Content: Success');
        } finally {
            if ($this->files->exists($targetFile)) {
                $this->files->delete($targetFile);
            }
        }
    }

    public function test_scaffold_file_supports_relative_parent_directory_navigation(): void
    {
        $engine = $this->engine();
        $sourceStub = "{$this->tempDir}/source.stub";
        $this->files->put($sourceStub, 'Content: {{ key }}');

        $subDir = "{$this->tempDir}/sub/nested";
        $this->files->ensureDirectoryExists($subDir);

        $targetFile = "{$subDir}/../output.txt";

        $result = $engine->scaffold(new ScaffoldRequest(
            source: $sourceStub,
            target: $targetFile,
            tokens: ['key' => 'Success'],
        ));

        $this->assertNotEmpty($result->createdFiles);
        $this->assertFileExists("{$this->tempDir}/sub/output.txt");
        $this->assertStringEqualsFile("{$this->tempDir}/sub/output.txt", 'Content: Success');
    }

    public function test_extract_tokens_captures_tokens_with_spaces_and_chains(): void
    {
        $interpolator = $this->interpolator();

        $template = 'Hello {{ missing_var | snake | plural }} and {{  another_missing  }}';
        $tokens = $interpolator->extractTokens($template);

        $this->assertSame(['missing_var', 'another_missing'], $tokens);
    }

    public function test_blade_template_escape_and_verbatim_syntax(): void
    {
        $interpolator = $this->interpolator();

        $template = '<h1>{{ title }}</h1><div>@{{ $user->name }}</div><span>@{{ route(\'profile\', [\'id\' => $user->id]) }}</span>';
        $rendered = $interpolator->interpolate($template, new ScaffoldRequest(
            source: 'memory',
            tokens: ['title' => 'User Dashboard'],
        ));

        $this->assertSame(
            '<h1>User Dashboard</h1><div>{{ $user->name }}</div><span>{{ route(\'profile\', [\'id\' => $user->id]) }}</span>',
            $rendered
        );
    }

    public function test_blade_escaping_in_strict_mode_does_not_trigger_unresolved_exception(): void
    {
        $engine = $this->engine();

        $stubsDir = "{$this->tempDir}/blade_stubs";
        $targetDir = "{$this->tempDir}/blade_output";

        $this->files->ensureDirectoryExists($stubsDir);
        $this->files->put(
            "{$stubsDir}/view.blade.php.stub",
            '<x-layout title="{{ pageTitle }}"><p>@{{ $post->title }}</p></x-layout>'
        );

        // In strict mode, escaped Blade syntax should NOT trigger an exception
        $result = $engine->scaffold(new ScaffoldRequest(
            source: $stubsDir,
            target: $targetDir,
            tokens: ['pageTitle' => 'Blog Post'],
            strict: true,
        ));

        $this->assertSame(1, count($result));
        $this->assertEmpty($result->unresolvedTokens);
        $this->assertFileExists("{$targetDir}/view.blade.php");
        $this->assertStringEqualsFile(
            "{$targetDir}/view.blade.php",
            '<x-layout title="Blog Post"><p>{{ $post->title }}</p></x-layout>'
        );
    }

    public function test_extract_tokens_ignores_blade_escaped_expressions(): void
    {
        $engine = $this->engine();

        $template = 'Valid: {{ resolved }}, Escaped: @{{ $blade_var }}, Missing: {{ missing_var }}';
        $tokens = $engine->extractTokens($template);

        $this->assertSame(['resolved', 'missing_var'], $tokens);
    }

    public function test_built_in_string_modifiers(): void
    {
        $interpolator = $this->interpolator();

        $template = implode("\n", [
            'Studly: {{ entity | studly }}',
            'Camel: {{ entity | camel }}',
            'Kebab: {{ entity | kebab }}',
            'Snake: {{ entity | snake }}',
            'Lower: {{ entity | lower }}',
            'Upper: {{ entity | upper }}',
            'Title: {{ entity | title }}',
            'Plural: {{ entity | plural }}',
            'Singular: {{ entity | singular }}',
            'Trim: {{ padded | trim }}',
            'Chained: {{ entity | snake | upper }}',
        ]);

        $rendered = $interpolator->interpolate($template, new ScaffoldRequest(
            source: 'memory',
            tokens: [
                'entity' => 'user profile',
                'padded' => '   clean   ',
            ],
        ));

        $this->assertStringContainsString('Studly: UserProfile', $rendered);
        $this->assertStringContainsString('Camel: userProfile', $rendered);
        $this->assertStringContainsString('Kebab: user-profile', $rendered);
        $this->assertStringContainsString('Snake: user_profile', $rendered);
        $this->assertStringContainsString('Lower: user profile', $rendered);
        $this->assertStringContainsString('Upper: USER PROFILE', $rendered);
        $this->assertStringContainsString('Title: User Profile', $rendered);
        $this->assertStringContainsString('Plural: user profiles', $rendered);
        $this->assertStringContainsString('Singular: user profile', $rendered);
        $this->assertStringContainsString('Trim: clean', $rendered);
        $this->assertStringContainsString('Chained: USER_PROFILE', $rendered);
    }

    public function test_stub_engine_is_macroable(): void
    {
        StubEngine::macro('renderBanner', function (string $source, string $title): string {
            /** @var StubEngine $this */
            return $this->renderFile(new ScaffoldRequest($source, tokens: ['title' => $title]));
        });

        $sourceFile = "{$this->tempDir}/banner.stub";
        $this->files->put($sourceFile, '=== {{ title|upper }} ===');

        $engine = $this->engine();
        $result = $engine->renderBanner($sourceFile, 'welcome');

        $this->assertSame('=== WELCOME ===', $result);
    }

    public function test_scaffold_result_implements_arrayable_and_countable(): void
    {
        $request = new ScaffoldRequest(source: '/source');
        $result = new ScaffoldResult(
            request: $request,
            createdFiles: ['a.txt'],
            overwrittenFiles: ['b.txt'],
            skippedFiles: ['c.txt'],
            overrideFiles: ['d.txt'],
        );

        $this->assertInstanceOf(Arrayable::class, $result);
        $this->assertInstanceOf(\Countable::class, $result);
        $this->assertCount(2, $result);
        $this->assertSame(2, $result->fileCount);
        $this->assertSame(['a.txt', 'b.txt'], $result->renderedFiles);

        $array = $result->toArray();
        $this->assertSame(['a.txt'], $array['createdFiles']);
        $this->assertSame(['b.txt'], $array['overwrittenFiles']);
        $this->assertSame(['c.txt'], $array['skippedFiles']);
        $this->assertSame(['d.txt'], $array['overrideFiles']);
        $this->assertSame(['a.txt', 'b.txt'], $array['renderedFiles']);
        $this->assertSame(2, $array['fileCount']);
    }
}
