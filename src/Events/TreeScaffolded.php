<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Events;

use AlexKassel\StubEngine\DTOs\ScaffoldResult;
use Illuminate\Foundation\Events\Dispatchable;

class TreeScaffolded
{
    use Dispatchable;

    /**
     * @param  ScaffoldResult  $result  Detailed audit result of the scaffolding run
     */
    public function __construct(
        public ScaffoldResult $result,
    ) {}
}
