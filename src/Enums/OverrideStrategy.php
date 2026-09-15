<?php

declare(strict_types=1);

namespace AlexKassel\StubEngine\Enums;

enum OverrideStrategy: string
{
    case Overlay = 'overlay';
    case Replace = 'replace';
}
