<?php

$failed = false;
foreach (['src', 'examples', 'tests'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $directory));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
        if (proc_close($process) !== 0) {
            $failed = true;
        }
    }
}
if ($failed) {
    exit(1);
}
exit(0);
