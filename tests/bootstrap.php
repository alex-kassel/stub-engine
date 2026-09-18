<?php

declare(strict_types=1);
use Composer\Autoload\ClassLoader;

$autoloadCandidates = [
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../../../vendor/autoload.php',
    __DIR__.'/../../../vendor/autoload.php',
];

$loader = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $loader = require $candidate;
        break;
    }
}

if ($loader instanceof ClassLoader) {
    $loader->addPsr4('AlexKassel\\StubEngine\\Tests\\', __DIR__);
    $loader->addPsr4('AlexKassel\\StubEngine\\', dirname(__DIR__).'/src');
}
