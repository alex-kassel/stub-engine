<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

class FileScaffolding
{
    use Dispatchable;

    /**
     * @param  string  $destination  Absolute or resolved destination path
     * @param  string  $relativePath  Relative path inside the target tree
     * @param  string  $content  Interpolated content to be written
     * @param  bool  $isOverride  Whether this file originates from a host override
     * @param  bool  $isRawCopy  Whether this file is a non-stub asset copied directly
     * @param  bool  $dryRun  Whether this execution is running in simulation mode
     * @param  bool  $shouldSkip  Set to true by a listener to cancel writing this file
     */
    public function __construct(
        public string $destination,
        public string $relativePath,
        public string $content,
        public bool $isOverride = false,
        public bool $isRawCopy = false,
        public bool $dryRun = false,
        public bool $shouldSkip = false,
    ) {}

    /**
     * Cancel the creation of this file.
     */
    public function skip(): self
    {
        $this->shouldSkip = true;

        return $this;
    }

    /**
     * Modify the content that will be written to disk.
     */
    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }
}
