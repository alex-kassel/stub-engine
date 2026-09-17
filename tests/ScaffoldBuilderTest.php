<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Builders\ScaffoldBuilder;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ScaffoldBuilderTest extends TestCase
{
    protected Filesystem $files;

    protected string $tempDir;

    protected StubEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/stub_engine_builder_test_'.bin2hex(random_bytes(6));
        $this->files->ensureDirectoryExists($this->tempDir);
        $this->engine = new StubEngine($this->files);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_builder_can_be_instantiated_via_factory_methods(): void
    {
        $builder1 = $this->engine->newBuilder();
        $this->assertInstanceOf(ScaffoldBuilder::class, $builder1);

        $builder2 = $this->engine->from($this->tempDir);
        $this->assertInstanceOf(ScaffoldBuilder::class, $builder2);

        $builder3 = $this->engine->fromFile($this->tempDir.'/file.stub');
        $this->assertInstanceOf(ScaffoldBuilder::class, $builder3);

        $builder4 = $this->engine->from($this->tempDir)->forPackage('alex-kassel/test-pkg');
        $this->assertInstanceOf(ScaffoldBuilder::class, $builder4);
    }

    public function test_builder_accumulates_tokens_via_with_tokens(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/sample.txt.stub', 'Hello {{ name }}, welcome to {{ place }} on {{ day }}!');

        $result = $this->engine->newBuilder()
            ->from($sourceDir)
            ->to($targetDir)
            ->withTokens(['name' => 'Alice', 'place' => 'Wonderland', 'day' => 'Monday'])
            ->scaffold();

        $this->assertInstanceOf(ScaffoldResult::class, $result);
        $this->assertFileExists($targetDir.'/sample.txt');
        $this->assertSame('Hello Alice, welcome to Wonderland on Monday!', $this->files->get($targetDir.'/sample.txt'));
    }

    public function test_builder_supports_conditionable_trait(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/test.txt.stub', 'Flag is: {{ flag }}');

        $isProduction = true;
        $isDebug = false;

        $result = $this->engine->newBuilder()
            ->from($sourceDir)
            ->to($targetDir)
            ->when($isProduction, function (ScaffoldBuilder $builder): void {
                $builder->withTokens(['flag' => 'PROD']);
            })
            ->unless($isDebug, function (ScaffoldBuilder $builder): void {
                $builder->force(true);
            })
            ->scaffold();

        $this->assertInstanceOf(ScaffoldResult::class, $result);
        $this->assertSame('Flag is: PROD', $this->files->get($targetDir.'/test.txt'));
    }

    public function test_builder_supports_macroable_trait(): void
    {
        ScaffoldBuilder::macro('withAuthor', function (string $author): ScaffoldBuilder {
            /** @var ScaffoldBuilder $this */
            return $this->withTokens(['author' => $author]);
        });

        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/book.txt.stub', 'Author: {{ author }}');

        $this->engine->newBuilder()
            ->from($sourceDir)
            ->to($targetDir)
            ->withAuthor('Alex Kassel')
            ->scaffold();

        $this->assertSame('Author: Alex Kassel', $this->files->get($targetDir.'/book.txt'));
    }

    public function test_builder_scaffold_file_and_render(): void
    {
        $sourceFile = $this->tempDir.'/class.php.stub';
        $targetFile = $this->tempDir.'/output/MyClass.php';
        $this->files->put($sourceFile, '<?php class {{ class }} {}');

        $builder = $this->engine->newBuilder()
            ->fromFile($sourceFile)
            ->toFile($targetFile)
            ->withTokens(['class' => 'MyClass']);

        $rendered = $builder->render();
        $this->assertSame('<?php class MyClass {}', $rendered);
        $this->assertFileDoesNotExist($targetFile);

        $scaffolded = $builder->scaffold();
        $this->assertTrue($scaffolded);
        $this->assertFileExists($targetFile);
        $this->assertSame('<?php class MyClass {}', $this->files->get($targetFile));
    }

    public function test_builder_dry_run_does_not_write_files(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/item.txt.stub', 'Content');

        $result = $this->engine->newBuilder()
            ->from($sourceDir)
            ->to($targetDir)
            ->dryRun()
            ->scaffold();

        $this->assertInstanceOf(ScaffoldResult::class, $result);
        $this->assertTrue($result->dryRun);
        $this->assertFileDoesNotExist($targetDir.'/item.txt');
    }

    public function test_builder_overlay_and_replace_strategies(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $overrideDir = $this->tempDir.'/override';
        $targetDir = $this->tempDir.'/target';

        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->ensureDirectoryExists($overrideDir);

        $this->files->put($sourceDir.'/a.txt.stub', 'Source A');
        $this->files->put($sourceDir.'/b.txt.stub', 'Source B');
        $this->files->put($overrideDir.'/b.txt.stub', 'Override B');

        // Overlay strategy: a.txt and override b.txt exist
        $this->engine->newBuilder()
            ->from($sourceDir)
            ->to($targetDir)
            ->overlay($overrideDir)
            ->scaffold();

        $this->assertSame('Source A', $this->files->get($targetDir.'/a.txt'));
        $this->assertSame('Override B', $this->files->get($targetDir.'/b.txt'));

        // Clean target
        $this->files->cleanDirectory($targetDir);

        // Replace strategy: only b.txt from overrideDir exists
        $this->engine->newBuilder()
            ->from($sourceDir)
            ->to($targetDir)
            ->replace($overrideDir)
            ->scaffold();

        $this->assertFileDoesNotExist($targetDir.'/a.txt');
        $this->assertSame('Override B', $this->files->get($targetDir.'/b.txt'));
    }

    public function test_for_package_auto_discovers_host_stubs_when_directory_exists(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/config.php.stub', 'default config');

        // Simulate host override convention: stubs/vendor/alex-kassel/test-pkg
        $conventionOverride = $this->tempDir.'/stubs/vendor/alex-kassel/test-pkg';
        $this->files->ensureDirectoryExists($conventionOverride);
        $this->files->put($conventionOverride.'/config.php.stub', 'customized host config');

        // When directory exists at the custom path
        $builder = $this->engine->newBuilder();
        $builder->overlay($conventionOverride);
        $result = $builder
            ->from($sourceDir)
            ->to($targetDir)
            ->scaffold();

        $this->assertInstanceOf(ScaffoldResult::class, $result);
        $this->assertSame('customized host config', $this->files->get($targetDir.'/config.php'));
        $this->assertContains('config.php', $result->overrideFiles);
    }

    public function test_for_package_gracefully_ignores_non_existent_host_directory(): void
    {
        $builder = $this->engine->newBuilder();
        $builder->forPackage('nonexistent/package-name');

        // Strategy should still default to Overlay, but no error thrown
        $this->assertInstanceOf(ScaffoldBuilder::class, $builder);
    }

    public function test_for_package_supports_subpath(): void
    {
        $builder = $this->engine->newBuilder();
        $builder->forPackage('alex-kassel/test-pkg', 'configs');

        $this->assertInstanceOf(ScaffoldBuilder::class, $builder);
    }

    public function test_strict_mode_throws_on_unresolved_tokens(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/template.txt.stub', 'Hello {{ missing_token }}!');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unresolved tokens in [template.txt]: {{ missing_token }}');

        $this->engine->newBuilder()
            ->from($sourceDir)
            ->to($targetDir)
            ->strict()
            ->scaffold();
    }

    public function test_missing_from_or_to_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Both source directory (from) and target directory (to) must be specified for tree scaffolding.');

        $this->engine->newBuilder()->scaffoldTree();
    }

    public function test_missing_source_file_throws_invalid_argument_exception_on_render(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source file must be specified via fromFile() to render.');

        $this->engine->newBuilder()->render();
    }
}
