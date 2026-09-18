<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Services\Scaffolder;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

class ScaffolderTest extends TestCase
{
    protected Filesystem $files;

    protected string $tempDir;

    protected Scaffolder $scaffolder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/scaffolder_test_'.bin2hex(random_bytes(6));
        $this->files->ensureDirectoryExists($this->tempDir);
        $this->scaffolder = $this->app->make(Scaffolder::class);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_scaffolder_can_be_resolved_from_container(): void
    {
        $this->assertInstanceOf(Scaffolder::class, $this->scaffolder);
    }

    public function test_scaffolder_renders_and_writes_single_file(): void
    {
        $stub = "{$this->tempDir}/test.stub";
        $target = "{$this->tempDir}/output/test.txt";
        $this->files->put($stub, 'Hello {{ name }}');

        $result = $this->scaffolder->scaffold(new ScaffoldRequest(
            source: $stub,
            target: $target,
            tokens: ['name' => 'World'],
        ));

        $this->assertInstanceOf(ScaffoldResult::class, $result);
        $this->assertTrue($result->hasCreated());
        $this->assertFileExists($target);
        $this->assertStringEqualsFile($target, 'Hello World');
    }

    public function test_scaffolder_render_file_method_returns_string_without_writing(): void
    {
        $stub = "{$this->tempDir}/render_only.stub";
        $this->files->put($stub, 'Count: {{ count }}');

        $rendered = $this->scaffolder->renderFile(new ScaffoldRequest(source: $stub, tokens: ['count' => '42']));

        $this->assertSame('Count: 42', $rendered);
    }

    public function test_scaffolder_render_file_throws_exception_when_source_is_directory(): void
    {
        $dir = "{$this->tempDir}/some_dir";
        $this->files->ensureDirectoryExists($dir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot render directory [{$dir}] as a file. The renderFile() method only supports single stub files.");

        $this->scaffolder->renderFile(new ScaffoldRequest(source: $dir));
    }

    public function test_scaffolder_render_file_throws_exception_when_override_is_directory(): void
    {
        $stub = "{$this->tempDir}/single.stub";
        $this->files->put($stub, 'Hello');
        $dir = "{$this->tempDir}/override_dir";
        $this->files->ensureDirectoryExists($dir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Override path [{$dir}] is a directory. File operations require a file override.");

        $this->scaffolder->renderFile(new ScaffoldRequest(source: $stub, override: $dir));
    }

    public function test_scaffolder_tree_overlay_merges_overrides(): void
    {
        $source = "{$this->tempDir}/source";
        $override = "{$this->tempDir}/override";
        $target = "{$this->tempDir}/output";

        $this->files->ensureDirectoryExists($source);
        $this->files->put("{$source}/base.txt.stub", 'default {{ val }}');
        $this->files->put("{$source}/shared.txt.stub", 'default shared {{ val }}');

        $this->files->ensureDirectoryExists($override);
        $this->files->put("{$override}/shared.txt.stub", 'custom shared {{ val }}');

        $result = $this->scaffolder->scaffold(new ScaffoldRequest(
            source: $source,
            target: $target,
            tokens: ['val' => '123'],
            override: $override,
            strategy: OverrideStrategy::Overlay,
        ));

        $this->assertCount(2, $result);
        $this->assertTrue($result->hasOverrides());
        $this->assertStringEqualsFile("{$target}/base.txt", 'default 123');
        $this->assertStringEqualsFile("{$target}/shared.txt", 'custom shared 123');
    }

    public function test_scaffolder_tree_replace_exclusively_uses_override(): void
    {
        $source = "{$this->tempDir}/source";
        $override = "{$this->tempDir}/override";
        $target = "{$this->tempDir}/output_replace";

        $this->files->ensureDirectoryExists($source);
        $this->files->put("{$source}/base.txt.stub", 'default');

        $this->files->ensureDirectoryExists($override);
        $this->files->put("{$override}/custom.txt.stub", 'custom');

        $result = $this->scaffolder->scaffold(new ScaffoldRequest(
            source: $source,
            target: $target,
            override: $override,
            strategy: OverrideStrategy::Replace,
        ));

        $this->assertCount(1, $result);
        $this->assertFileExists("{$target}/custom.txt");
        $this->assertFileDoesNotExist("{$target}/base.txt");
    }

    public function test_scaffolder_tree_throws_exception_when_override_is_file(): void
    {
        $sourceDir = "{$this->tempDir}/tree_source";
        $this->files->ensureDirectoryExists($sourceDir);
        $overrideFile = "{$this->tempDir}/override_file.stub";
        $this->files->put($overrideFile, 'file content');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Override path [{$overrideFile}] is a file. Directory scaffolding requires a directory override.");

        $this->scaffolder->scaffold(new ScaffoldRequest(
            source: $sourceDir,
            target: "{$this->tempDir}/tree_target",
            override: $overrideFile,
        ));
    }

    public function test_scaffolder_file_throws_exception_when_target_is_directory(): void
    {
        $sourceFile = "{$this->tempDir}/source_file.stub";
        $this->files->put($sourceFile, 'content');
        $targetDir = "{$this->tempDir}/existing_target_dir";
        $this->files->ensureDirectoryExists($targetDir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Target path [{$targetDir}] is an existing directory. File scaffolding requires a file destination.");

        $this->scaffolder->scaffold(new ScaffoldRequest(
            source: $sourceFile,
            target: $targetDir,
        ));
    }

    public function test_scaffolder_tree_throws_exception_when_target_is_file(): void
    {
        $sourceDir = "{$this->tempDir}/source_dir";
        $this->files->ensureDirectoryExists($sourceDir);
        $targetFile = "{$this->tempDir}/existing_target_file.txt";
        $this->files->put($targetFile, 'existing');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Target path [{$targetFile}] is an existing file. Directory tree scaffolding requires a directory destination.");

        $this->scaffolder->scaffold(new ScaffoldRequest(
            source: $sourceDir,
            target: $targetFile,
        ));
    }

    public function test_scaffolder_throws_exception_when_source_does_not_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source stub path not found: [/non/existent/path].');

        $this->scaffolder->scaffold(new ScaffoldRequest(
            source: '/non/existent/path',
            target: "{$this->tempDir}/some_target",
        ));
    }
}
