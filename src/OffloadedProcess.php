<?php

declare(strict_types=1);
ini_set('display_errors', 'stderr');

use Flows\ApplicationKernel;
use Flows\Registries\ProcessRegistry;

ob_start();
$line = trim(fgets(STDIN));
fclose(STDIN);
list($process, $contentTerminator, $rootDir, $processIO) = explode('|', $line);
define('OFFLOADED_PROCESS_NAME', $process);
define('OFFLOADED_PROCESS_CONTENT_TERMINATOR', $contentTerminator);
define('OFFLOADED_PROCESS', 0);

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
exit(0);
