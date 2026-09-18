<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Builders\ScaffoldBuilder;
use AlexKassel\StubEngine\DTOs\ScaffoldRequest;
use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\StubEngine;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

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
        $this->engine = $this->engine();
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_builder_can_be_instantiated(): void
    {
        $builder1 = app(ScaffoldBuilder::class);
        $this->assertInstanceOf(ScaffoldBuilder::class, $builder1);

        $builder2 = $this->engine->from($this->tempDir);
        $this->assertInstanceOf(ScaffoldBuilder::class, $builder2);

        $builder3 = $this->engine->from($this->tempDir)->override('/path');
        $this->assertInstanceOf(ScaffoldBuilder::class, $builder3);
    }

    public function test_builder_accumulates_tokens_via_with_tokens(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/sample.txt.stub', 'Hello {{ name }}, welcome to {{ place }} on {{ day }}!');

        $result = $this->engine
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

        $result = $this->engine
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

        $this->engine
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

        $builder = $this->engine
            ->from($sourceFile)
            ->to($targetFile)
            ->withTokens(['class' => 'MyClass']);

        $rendered = $builder->renderFile();
        $this->assertSame('<?php class MyClass {}', $rendered);
        $this->assertFileDoesNotExist($targetFile);

        $scaffolded = $builder->scaffold();
        $this->assertInstanceOf(ScaffoldResult::class, $scaffolded);
        $this->assertTrue($scaffolded->successful());
        $this->assertFileExists($targetFile);
        $this->assertSame('<?php class MyClass {}', $this->files->get($targetFile));
    }

    public function test_builder_dry_run_does_not_write_files(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/item.txt.stub', 'Content');

        $result = $this->engine
            ->from($sourceDir)
            ->to($targetDir)
            ->dryRun()
            ->scaffold();

        $this->assertInstanceOf(ScaffoldResult::class, $result);
        $this->assertTrue($result->request->dryRun);
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
        $this->engine
            ->from($sourceDir)
            ->to($targetDir)
            ->override($overrideDir)
            ->scaffold();

        $this->assertSame('Source A', $this->files->get($targetDir.'/a.txt'));
        $this->assertSame('Override B', $this->files->get($targetDir.'/b.txt'));

        // Clean target
        $this->files->cleanDirectory($targetDir);

        // Replace strategy: only b.txt from overrideDir exists
        $this->engine
            ->from($sourceDir)
            ->to($targetDir)
            ->override($overrideDir, OverrideStrategy::Replace)
            ->scaffold();

        $this->assertFileDoesNotExist($targetDir.'/a.txt');
        $this->assertSame('Override B', $this->files->get($targetDir.'/b.txt'));
    }

    public function test_builder_override_with_explicit_strategy(): void
    {
        $builder = app(ScaffoldBuilder::class);
        $builder->override('/custom/path', OverrideStrategy::Replace);

        $request = $builder->from('/source')->to('/target')->toRequest();
        $this->assertSame('/custom/path', $request->override);
        $this->assertSame(OverrideStrategy::Replace, $request->strategy);
    }

    public function test_strict_mode_throws_on_unresolved_tokens(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/template.txt.stub', 'Hello {{ missing_token }}!');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unresolved tokens in [template.txt]: {{ missing_token }}');

        $this->engine
            ->from($sourceDir)
            ->to($targetDir)
            ->strict()
            ->scaffold();
    }

    public function test_missing_target_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target destination must be specified for scaffolding.');

        app(ScaffoldBuilder::class)->from('/non-existent')->scaffold();
    }

    public function test_missing_source_file_throws_invalid_argument_exception_on_render_file(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stub file not found: [].');

        app(ScaffoldBuilder::class)->renderFile();
    }

    public function test_scaffold_request_implements_arrayable_and_to_array(): void
    {
        $request = new ScaffoldRequest(source: '/source');
        $this->assertInstanceOf(Arrayable::class, $request);
        $array = $request->toArray();
        $this->assertSame('/source', $array['source']);
        $this->assertSame(OverrideStrategy::Overlay, $array['strategy']);
    }

    public function test_builder_render_file_throws_exception_when_source_is_directory(): void
    {
        $dir = $this->tempDir.'/some_tree_dir';
        $this->files->ensureDirectoryExists($dir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot render directory [{$dir}] as a file. The renderFile() method only supports single stub files.");

        $this->engine->from($dir)->renderFile();
    }

    public function test_unified_from_and_to_handles_both_file_and_directory(): void
    {
        // 1. Single file via unified from() and to()
        $sourceFile = $this->tempDir.'/unified.stub';
        $targetFile = $this->tempDir.'/output/unified.txt';
        $this->files->put($sourceFile, 'Unified {{ type }}');

        $fileResult = $this->engine->from($sourceFile)
            ->to($targetFile)
            ->withTokens(['type' => 'File'])
            ->scaffold();

        $this->assertInstanceOf(ScaffoldResult::class, $fileResult);
        $this->assertTrue($fileResult->successful());
        $this->assertSame('Unified File', $this->files->get($targetFile));

        // 2. Directory tree via unified from() and to()
        $sourceDir = $this->tempDir.'/unified_tree';
        $targetDir = $this->tempDir.'/output/tree';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/hello.txt.stub', 'Hello {{ type }}');

        $treeResult = $this->engine->from($sourceDir)
            ->to($targetDir)
            ->withTokens(['type' => 'Tree'])
            ->scaffold();

        $this->assertInstanceOf(ScaffoldResult::class, $treeResult);
        $this->assertTrue($treeResult->successful());
        $this->assertSame('Hello Tree', $this->files->get($targetDir.'/hello.txt'));
    }

    public function test_direct_scaffold_request_execution(): void
    {
        $source = $this->tempDir.'/direct.stub';
        $target = $this->tempDir.'/output/direct.txt';
        $this->files->put($source, 'Direct {{ mode }}');

        $request = new ScaffoldRequest(
            source: $source,
            target: $target,
            tokens: ['mode' => 'Request'],
        );

        $result = $this->engine->scaffold($request);
        $this->assertTrue($result->successful());
        $this->assertSame('Direct Request', $this->files->get($target));
    }

    public function test_engine_render_file_method(): void
    {
        $source = $this->tempDir.'/render_test.stub';
        $this->files->put($source, 'Render {{ item }}');

        $rendered = $this->engine->renderFile(new ScaffoldRequest(source: $source, tokens: ['item' => 'Output']));
        $this->assertSame('Render Output', $rendered);
    }

    public function test_scaffold_tree_on_progress_callback_is_invoked_for_each_file(): void
    {
        $sourceDir = $this->tempDir.'/progress_source';
        $targetDir = $this->tempDir.'/progress_target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/file1.txt.stub', '1');
        $this->files->put($sourceDir.'/file2.txt.stub', '2');
        $this->files->put($sourceDir.'/raw.bin', 'RAW');

        $progressCalls = [];

        $this->engine->from($sourceDir)
            ->to($targetDir)
            ->onProgress(function (string $relativePath, int $index, int $total) use (&$progressCalls): void {
                $progressCalls[] = [
                    'file' => $relativePath,
                    'index' => $index,
                    'total' => $total,
                ];
            })
            ->scaffold();

        $this->assertCount(3, $progressCalls);
        $this->assertSame(3, $progressCalls[0]['total']);
        $this->assertSame(1, $progressCalls[0]['index']);
        $this->assertSame(2, $progressCalls[1]['index']);
        $this->assertSame(3, $progressCalls[2]['index']);
    }
}
