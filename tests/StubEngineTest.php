<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Services\StubEngine;
use AlexKassel\StubEngine\StubEngineServiceProvider;
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
