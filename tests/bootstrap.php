<?php

declare(strict_types=1);

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

if ($loader !== null && method_exists($loader, 'addPsr4')) {
    $loader->addPsr4('AlexKassel\\WorkspaceDevelopmentToolkit\\Tests\\', __DIR__);
    $loader->addPsr4('AlexKassel\\WorkspaceDevelopmentToolkit\\', dirname(__DIR__).'/src');
}
