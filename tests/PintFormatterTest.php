<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Exceptions\FormatterNotFoundException;
use AlexKassel\StubEngine\Formatters\PintFormatter;
use AlexKassel\StubEngine\Services\StubEngine;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PintFormatterTest extends TestCase
{
    protected Filesystem $files;

    protected string $tempDir;

    protected PintFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/stub_engine_pint_test_'.bin2hex(random_bytes(6));
        $this->files->ensureDirectoryExists($this->tempDir);
        $this->formatter = new PintFormatter;
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_format_ignores_non_php_files(): void
    {
        $textFile = $this->tempDir.'/file.txt';
        $this->files->put($textFile, 'hello');

        $result = $this->formatter->format([$textFile]);

        $this->assertFalse($result['formatted']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_format_throws_exception_in_strict_mode_when_binary_not_found(): void
    {
        $phpFile = $this->tempDir.'/file.php';
        $this->files->put($phpFile, '<?php echo "hi";');

        $this->expectException(FormatterNotFoundException::class);
        $this->expectExceptionMessage('Laravel Pint binary not found at [/non/existent/pint]');

        $this->formatter->format(
            files: [$phpFile],
            strict: true,
            customBinary: '/non/existent/pint',
        );
    }

    public function test_format_returns_warning_in_lenient_mode_when_binary_not_found(): void
    {
        $phpFile = $this->tempDir.'/file.php';
        $this->files->put($phpFile, '<?php echo "hi";');

        $result = $this->formatter->format(
            files: [$phpFile],
            strict: false,
            customBinary: '/non/existent/pint',
        );

        $this->assertFalse($result['formatted']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Laravel Pint binary not found at [/non/existent/pint]', $result['warnings'][0]);
    }

    public function test_format_runs_process_successfully(): void
    {
        $mockBinary = $this->tempDir.'/fake-pint';
        $this->files->put($mockBinary, '#!/bin/sh');
        chmod($mockBinary, 0755);

        $phpFile = $this->tempDir.'/Sample.php';
        $this->files->put($phpFile, '<?php class Sample {}');

        $this->formatter->process()->fake([
            '*'.$mockBinary.'*' => $this->formatter->process()->result('Fixed 1 file', exitCode: 0),
        ]);

        $result = $this->formatter->format(
            files: [$phpFile],
            strict: true,
            customBinary: $mockBinary,
        );

        $this->assertTrue($result['formatted']);
        $this->assertSame([], $result['warnings']);

        $this->formatter->process()->assertRan(function ($process) use ($mockBinary, $phpFile): bool {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, $mockBinary) && str_contains($cmd, $phpFile);
        });
    }

    public function test_format_handles_process_failure_in_lenient_mode(): void
    {
        $mockBinary = $this->tempDir.'/fake-pint';
        $this->files->put($mockBinary, '#!/bin/sh');
        chmod($mockBinary, 0755);

        $phpFile = $this->tempDir.'/Sample.php';
        $this->files->put($phpFile, '<?php class Sample {}');

        $this->formatter->process()->fake([
            '*'.$mockBinary.'*' => $this->formatter->process()->result(errorOutput: 'Syntax error in file', exitCode: 1),
        ]);

        $result = $this->formatter->format(
            files: [$phpFile],
            strict: false,
            customBinary: $mockBinary,
        );

        $this->assertFalse($result['formatted']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Laravel Pint formatting exited with code 1: Syntax error in file', $result['warnings'][0]);
    }

    public function test_format_handles_process_failure_in_strict_mode(): void
    {
        $mockBinary = $this->tempDir.'/fake-pint';
        $this->files->put($mockBinary, '#!/bin/sh');
        chmod($mockBinary, 0755);

        $phpFile = $this->tempDir.'/Sample.php';
        $this->files->put($phpFile, '<?php class Sample {}');

        $this->formatter->process()->fake([
            '*'.$mockBinary.'*' => $this->formatter->process()->result(errorOutput: 'Syntax error', exitCode: 1),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Laravel Pint formatting exited with code 1: Syntax error');

        $this->formatter->format(
            files: [$phpFile],
            strict: true,
            customBinary: $mockBinary,
        );
    }

    public function test_scaffold_builder_format_with_pint_populates_result(): void
    {
        $mockBinary = $this->tempDir.'/fake-pint';
        $this->files->put($mockBinary, '#!/bin/sh');
        chmod($mockBinary, 0755);

        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/Class.php.stub', '<?php class {{ name }} {}');

        $engine = new StubEngine($this->files);
        $engine->formatter()->process()->fake([
            '*'.$mockBinary.'*' => $engine->formatter()->process()->result('1 file formatted', exitCode: 0),
        ]);

        $result = $engine->from($sourceDir)
            ->to($targetDir)
            ->token('name', 'Invoice')
            ->formatWithPint(enabled: true, strict: true, binary: $mockBinary)
            ->scaffold();

        $this->assertTrue($result->isFormatted());
        $this->assertFalse($result->hasWarnings());
        $this->assertSame([], $result->warnings);
    }

    public function test_scaffold_builder_format_with_pint_records_warning_when_missing_in_lenient_mode(): void
    {
        $sourceDir = $this->tempDir.'/source';
        $targetDir = $this->tempDir.'/target';
        $this->files->ensureDirectoryExists($sourceDir);
        $this->files->put($sourceDir.'/Service.php.stub', '<?php class Service {}');

        $engine = new StubEngine($this->files);
        $result = $engine->from($sourceDir)
            ->to($targetDir)
            ->formatWithPint(enabled: true, strict: false, binary: '/invalid/path/to/pint')
            ->scaffold();

        $this->assertFalse($result->isFormatted());
        $this->assertTrue($result->hasWarnings());
        $this->assertStringContainsString('Laravel Pint binary not found', $result->warnings[0]);
    }
}
