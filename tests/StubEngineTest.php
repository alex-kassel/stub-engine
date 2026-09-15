<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Services\StubEngine;
use AlexKassel\StubEngine\StubEngineServiceProvider;
use Illuminate\Config\Repository;
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
        $app = new Container;
        Container::setInstance($app);
        Facade::setFacadeApplication($app);

        // Bind config repository in container
        $config = new Repository([
            'stub-engine' => [
                'delimiters' => [
                    'open' => '[[',
                    'close' => ']]',
                ],
                'global_tokens' => [
                    'company' => 'Acme Global',
                    'year' => '2026',
                ],
            ],
        ]);
        $app->instance('config', $config);

        $engine = new StubEngine($this->files);

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
        $app = new Container;
        Facade::setFacadeApplication($app);

        $provider = new StubEngineServiceProvider($app);
        $provider->register();

        $stub = "{$this->tempDir}/facade.stub";
        $this->files->put($stub, 'Hello {{ name }}');

        $content = \AlexKassel\StubEngine\Facades\StubEngine::renderFile($stub, ['{{ name }}' => 'Laravel']);
        $this->assertSame('Hello Laravel', $content);

        $instance = $app->make(StubEngine::class);
        $this->assertInstanceOf(StubEngine::class, $instance);
    }
}
