<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

class FileScaffolded
{
    use Dispatchable;

    /**
     * @param  string  $destination  Destination path of the written file
     * @param  string  $relativePath  Relative path inside the target tree
     * @param  bool  $isOverride  Whether this file originated from a host override
     * @param  bool  $isRawCopy  Whether this file was copied as a raw non-stub asset
     * @param  bool  $dryRun  Whether this execution was simulated
     */
    public function __construct(
        public string $destination,
        public string $relativePath,
        public bool $isOverride = false,
        public bool $isRawCopy = false,
        public bool $dryRun = false,
    ) {}
}
