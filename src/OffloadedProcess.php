<?php

/*
 * The MIT License
 *
 * Copyright 2024 rsousa <rmbsousa@gmail.com>.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

declare(strict_types=1);

use Flows\Kernel;
use Flows\Processes\Internal\IO\OffloadedIO;
use Flows\Processes\Registry;
use Monolog\ErrorHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

require __DIR__ . '/../vendor/autoload.php';

// $logger = new Logger('my_logger');
// $logger->pushHandler(new StreamHandler(__DIR__ . '/app/logs/app.log', Level::Debug));
// ErrorHandler::register($logger);

$line = trim(fgets(STDIN));
fclose(STDIN);
list($process, $contentTerminator, $processIO) = explode('|', $line);
$registry = new Registry();
$process = new $process();

$registry->add($process);

// what if you need more than one process?

$kernel = new Kernel($registry);
$return = $kernel->processProcess(
    get_class($process),
    unserialize(base64_decode($processIO))
);
fwrite(STDOUT, base64_encode(serialize($return)) . PHP_EOL);
fflush(STDOUT);
fwrite(STDOUT, $contentTerminator . PHP_EOL);
fflush(STDOUT);

fclose(STDOUT);
exit(0);
