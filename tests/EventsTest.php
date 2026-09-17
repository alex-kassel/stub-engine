<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Events\FileScaffolded;
use AlexKassel\StubEngine\Events\FileScaffolding;
use AlexKassel\StubEngine\Events\TreeScaffolded;
use AlexKassel\StubEngine\Events\TreeScaffolding;
use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;

class EventsTest extends TestCase
{
    protected Filesystem $files;

    protected string $tempDir;

    protected Dispatcher $events;

    protected StubEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/stub_engine_events_test_'.bin2hex(random_bytes(6));
        $this->files->ensureDirectoryExists($this->tempDir);
        $this->events = new Dispatcher;
        $this->engine = new StubEngine(
            files: $this->files,
            events: $this->events,
        );
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_tree_scaffolding_and_tree_scaffolded_events_are_dispatched(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/test.txt.stub', 'Hello {{ name }}');

        $treeScaffoldingFired = false;
        $treeScaffoldedFired = false;

        $this->events->listen(TreeScaffolding::class, function (TreeScaffolding $event) use ($sourceDir, $targetDir, &$treeScaffoldingFired): void {
            $treeScaffoldingFired = true;
            $this->assertSame($sourceDir, $event->sourceDir);
            $this->assertSame($targetDir, $event->targetDir);
            $this->assertFalse($event->dryRun);
        });

        $this->events->listen(TreeScaffolded::class, function (TreeScaffolded $event) use (&$treeScaffoldedFired): void {
            $treeScaffoldedFired = true;
            $this->assertCount(1, $event->result->createdFiles);
            $this->assertContains('test.txt', $event->result->createdFiles);
        });

        $this->engine->scaffoldTree(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            tokens: ['name' => 'World'],
        );

        $this->assertTrue($treeScaffoldingFired);
        $this->assertTrue($treeScaffoldedFired);
        $this->assertSame('Hello World', $this->files->get($targetDir.'/test.txt'));
    }

    public function test_scaffold_tree_on_progress_callback_is_invoked_for_each_file(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
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

    public function test_single_file_scaffolding_dispatches_events(): void
    {
        $sourceFile = $this->tempDir.'/single.stub';
        $targetFile = $this->tempDir.'/single.txt';
        $this->files->put($sourceFile, 'Hello {{ user }}');

        $eventsReceived = [];

        $this->events->listen(FileScaffolding::class, function (FileScaffolding $event) use (&$eventsReceived): void {
            $eventsReceived[] = 'scaffolding';
            $this->assertSame('single.txt', $event->relativePath);
        });

        $this->events->listen(FileScaffolded::class, function (FileScaffolded $event) use (&$eventsReceived): void {
            $eventsReceived[] = 'scaffolded';
            $this->assertSame('single.txt', $event->relativePath);
        });

        $this->engine->scaffoldFile(
            sourceFile: $sourceFile,
            targetFile: $targetFile,
            tokens: ['user' => 'Alex'],
        );

        $this->assertSame(['scaffolding', 'scaffolded'], $eventsReceived);
        $this->assertSame('Hello Alex', $this->files->get($targetFile));
    }

    public function test_scaffold_file_respects_skip_event(): void
    {
        $sourceFile = $this->tempDir.'/cancel.stub';
        $targetFile = $this->tempDir.'/cancel.txt';
        $this->files->put($sourceFile, 'Content');

        $this->events->listen(FileScaffolding::class, function (FileScaffolding $event): void {
            $event->skip();
        });

        $written = $this->engine->scaffoldFile(
            sourceFile: $sourceFile,
            targetFile: $targetFile,
            tokens: [],
        );

        $this->assertFalse($written);
        $this->assertFileDoesNotExist($targetFile);
    }
}
