<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Services\StubEngine;
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
}
