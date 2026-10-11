<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Kjoe\Kjoe;

[$script, $mode, $directory, $name, $id, $count] = $argv;
$queue = new Kjoe($name, $directory);
echo "ready\n";
fflush(STDOUT);
fgets(STDIN); // Parent releases all children after they have opened the queue.

if ($mode === 'produce' || $mode === 'unique') {
    $queue->duplicates = true;
    $producer = $id;
    if ($mode === 'unique') {
        $queue->duplicates = false;
        $producer = 'shared';
    }
    for ($i = 0; $i < (int) $count; ++$i) {
        $queue->put([$producer, (string) $i, "comma, quote\" slash\\\nsecond line"]);
    }
} elseif ($mode === 'consume' || $mode === 'mixed-consume') {
    $jobs = [];
    $deadline = microtime(true) + 15;
    while (true) {
        $job = $queue->take();
        if ($job !== false) {
            $jobs[] = $job;
            continue;
        }
        if ($mode === 'consume' || is_file($directory . '/producers-done')) {
            // Check once more after the marker: a producer may have committed just before it.
            $job = $queue->take();
            if ($job === false) {
                break;
            }
            $jobs[] = $job;
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Consumer timed out.');
        }
        usleep(1000);
    }
    echo json_encode($jobs, JSON_THROW_ON_ERROR) . "\n";
} elseif ($mode === 'take-and-wait') {
    echo json_encode($queue->take(), JSON_THROW_ON_ERROR) . "\n";
    fflush(STDOUT);
    fgets(STDIN); // Parent kills us before any handler or acknowledgement.
} elseif ($mode === 'put-once') {
    $queue->put('new');
} elseif ($mode === 'clear') {
    $queue->clear();
} elseif ($mode === 'count') {
    echo $queue->jobsRemaining() . "\n";
} elseif ($mode === 'has-job') {
    if ($queue->hasJob()) {
        echo "yes\n";
    } else {
        echo "no\n";
    }
} elseif ($mode === 'limited-put' || $mode === 'limited-take') {
    // Simulate a disk write failing without filling the machine's disk.
    pcntl_signal(SIGXFSZ, SIG_IGN);
    if (!posix_setrlimit(POSIX_RLIMIT_FSIZE, 128, 128)) {
        throw new RuntimeException('Cannot set test file-size limit.');
    }
    try {
        if ($mode === 'limited-take') {
            $queue->take();
        } else {
            $queue->put(str_repeat('x', 8192));
        }
    } catch (RuntimeException $error) {
        echo "write-failed\n";
        exit(0);
    }
    exit(3);
} else {
    throw new RuntimeException('Unknown child mode.');
}
