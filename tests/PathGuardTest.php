<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Tests;

use AlexKassel\StubEngine\Support\PathGuard;
use InvalidArgumentException;

class PathGuardTest extends TestCase
{
    public function test_it_allows_valid_contained_destinations(): void
    {
        $guard = new PathGuard;

        // Absolute target and valid destination
        $guard->ensureWithinTargetDirectory('/var/www/app', '/var/www/app/src/File.php');
        $guard->ensureWithinTargetDirectory('/var/www/app', '/var/www/app/File.php');

        // Relative target and valid destination
        $guard->ensureWithinTargetDirectory('output', 'output/src/File.php');
        $guard->ensureWithinTargetDirectory('.', 'File.php');
        $guard->ensureWithinTargetDirectory('', 'File.php');

        $this->assertTrue(true);
    }

    public function test_it_detects_and_prevents_directory_traversal(): void
    {
        $guard = new PathGuard;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attempts directory traversal outside target directory');

        $guard->ensureWithinTargetDirectory('/var/www/app', '/var/www/app/../../etc/passwd');
    }

    public function test_it_detects_relative_directory_traversal_above_current_dir(): void
    {
        $guard = new PathGuard;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attempts directory traversal outside target directory');

        $guard->ensureWithinTargetDirectory('.', '../outside.txt');
    }
}
