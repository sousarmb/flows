<?php

declare(strict_types=1);

use Flows\Kernel;
use Flows\Registries\ProcessRegistry;

require __DIR__ . '/../vendor/autoload.php';

ob_start();
$line = trim(fgets(STDIN));
fclose(STDIN);
list($process, $contentTerminator, $processIO) = explode('|', $line);
//
// What if you need more than one process?
//
$kernel = new Kernel();
$kernel->setProcessRegistry(
    (new ProcessRegistry())
        ->add(new $process())
);
$return = $kernel->processProcess(
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
