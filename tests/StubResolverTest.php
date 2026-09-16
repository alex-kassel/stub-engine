<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Enums\OverrideStrategy;
use AlexKassel\StubEngine\Resolvers\StubResolver;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

class StubResolverTest extends TestCase
{
    protected string $tempDir;

    protected Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = new Filesystem;
        $this->tempDir = sys_get_temp_dir().'/stub_resolver_test_'.uniqid();
        $this->files->ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        if ($this->files->isDirectory($this->tempDir)) {
            $this->files->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_throws_exception_if_source_directory_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $resolver = new StubResolver($this->files);
        $resolver->resolveStubsMap("{$this->tempDir}/non_existent");
    }

    public function test_overlay_strategy_merges_override_stubs_over_defaults(): void
    {
        $source = "{$this->tempDir}/source";
        $override = "{$this->tempDir}/override";

        $this->files->ensureDirectoryExists($source);
        $this->files->put("{$source}/base.txt.stub", 'default');
        $this->files->put("{$source}/shared.txt.stub", 'default shared');

        $this->files->ensureDirectoryExists($override);
        $this->files->put("{$override}/shared.txt.stub", 'custom shared');

        $resolver = new StubResolver($this->files);
        $map = $resolver->resolveStubsMap($source, $override, OverrideStrategy::Overlay);

        $this->assertCount(2, $map);
        $this->assertFalse($map['base.txt.stub']['isOverride']);
        $this->assertTrue($map['shared.txt.stub']['isOverride']);
        $this->assertSame("{$override}/shared.txt.stub", $map['shared.txt.stub']['sourcePath']);
    }

    public function test_replace_strategy_exclusively_uses_override_directory(): void
    {
        $source = "{$this->tempDir}/source";
        $override = "{$this->tempDir}/override";

        $this->files->ensureDirectoryExists($source);
        $this->files->put("{$source}/default_only.txt.stub", 'default');

        $this->files->ensureDirectoryExists($override);
        $this->files->put("{$override}/custom_only.txt.stub", 'custom');

        $resolver = new StubResolver($this->files);
        $map = $resolver->resolveStubsMap($source, $override, OverrideStrategy::Replace);

        $this->assertCount(1, $map);
        $this->assertArrayHasKey('custom_only.txt.stub', $map);
        $this->assertArrayNotHasKey('default_only.txt.stub', $map);
    }
}
