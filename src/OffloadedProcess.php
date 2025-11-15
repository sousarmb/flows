<?php

declare(strict_types=1);

use Flows\ApplicationKernel;
use Flows\Registries\ProcessRegistry;

define('OFFLOADED_PROCESS', 0);

ob_start();
$line = trim(fgets(STDIN));
fclose(STDIN);
list($process, $contentTerminator, $rootDir, $processIO) = explode('|', $line);
require $rootDir . 'vendor/autoload.php';
//
// What if you need more than one process?
//
$app = new ApplicationKernel();
$app->setProcessRegistry(
    (new ProcessRegistry())
        ->add(new $process())
);
$return = $app->process(
    $process,
    unserialize(base64_decode($processIO))
);
ob_clean();
fwrite(STDOUT, base64_encode(serialize($return)) . PHP_EOL);
fflush(STDOUT);
fwrite(STDOUT, $contentTerminator . PHP_EOL);
fflush(STDOUT);
fclose(STDOUT);
exit(0);
