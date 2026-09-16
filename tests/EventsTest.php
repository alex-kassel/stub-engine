<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Events\FileScaffolded;
use AlexKassel\StubEngine\Events\FileScaffolding;
use AlexKassel\StubEngine\Events\TreeScaffolded;
use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

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

    public function test_file_scaffolding_and_file_scaffolded_events_are_dispatched(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/test.txt.stub', 'Hello {{ name }}');

        $scaffoldingFired = false;
        $scaffoldedFired = false;

        $this->events->listen(FileScaffolding::class, function (FileScaffolding $event) use (&$scaffoldingFired): void {
            $scaffoldingFired = true;
            $this->assertSame('test.txt', $event->relativePath);
            $this->assertSame('Hello World', $event->content);
            $this->assertFalse($event->isOverride);
            $this->assertFalse($event->isRawCopy);
            $this->assertFalse($event->dryRun);
        });

        $this->events->listen(FileScaffolded::class, function (FileScaffolded $event) use (&$scaffoldedFired): void {
            $scaffoldedFired = true;
            $this->assertSame('test.txt', $event->relativePath);
            $this->assertFalse($event->isOverride);
            $this->assertFalse($event->isRawCopy);
        });

        $this->engine->scaffoldTree(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            tokens: ['name' => 'World'],
        );

        $this->assertTrue($scaffoldingFired);
        $this->assertTrue($scaffoldedFired);
        $this->assertSame('Hello World', $this->files->get($targetDir.'/test.txt'));
    }

    public function test_file_scaffolding_allows_mutating_content_before_writing(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/header.txt.stub', 'Original Content: {{ var }}');

        $this->events->listen(FileScaffolding::class, function (FileScaffolding $event): void {
            $event->setContent("// LICENSE: MIT\n".$event->content);
        });

        $this->engine->scaffoldTree(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            tokens: ['var' => '123'],
        );

        $this->assertSame("// LICENSE: MIT\nOriginal Content: 123", $this->files->get($targetDir.'/header.txt'));
    }

    public function test_file_scaffolding_allows_skipping_specific_files(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/keep.txt.stub', 'Keep this');
        $this->files->put($sourceDir.'/skip.txt.stub', 'Skip this');

        $this->events->listen(FileScaffolding::class, function (FileScaffolding $event): void {
            if ($event->relativePath === 'skip.txt') {
                $event->skip();
            }
        });

        $result = $this->engine->scaffoldTree(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            tokens: [],
        );

        $this->assertFileExists($targetDir.'/keep.txt');
        $this->assertFileDoesNotExist($targetDir.'/skip.txt');
        $this->assertContains('skip.txt', $result->skippedFiles);
        $this->assertContains('keep.txt', $result->createdFiles);
    }

    public function test_tree_scaffolded_event_is_dispatched_with_result(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/file1.txt.stub', 'A');
        $this->files->put($sourceDir.'/file2.txt.stub', 'B');

        $treeEventDispatched = false;

        $this->events->listen(TreeScaffolded::class, function (TreeScaffolded $event) use (&$treeEventDispatched): void {
            $treeEventDispatched = true;
            $this->assertCount(2, $event->result->createdFiles);
            $this->assertFalse($event->result->dryRun);
        });

        $this->engine->scaffoldTree(
            sourceDir: $sourceDir,
            targetDir: $targetDir,
            tokens: [],
        );

        $this->assertTrue($treeEventDispatched);
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
