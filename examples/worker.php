<?php

require __DIR__ . '/../vendor/autoload.php';

$queue = new Kjoe\Kjoe('demo', __DIR__ . '/../var/queues');

for ($i = 0; $i < 25; $i++) {
    $job = $queue->take();
    if ($job === false) {
        break;
    }

    // Do your work here.
    echo strtoupper($job[0]) . PHP_EOL;
}
