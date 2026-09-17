<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

class TreeScaffolding
{
    use Dispatchable;

    /**
     * @param  string  $sourceDir  Stubs source directory
     * @param  string  $targetDir  Target destination directory
     * @param  string|null  $overrideDir  Optional host override directory
     * @param  bool  $dryRun  Whether execution is simulated without disk writes
     */
    public function __construct(
        public string $sourceDir,
        public string $targetDir,
        public ?string $overrideDir = null,
        public bool $dryRun = false,
    ) {}
}
