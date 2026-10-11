<?php

require __DIR__ . '/../vendor/autoload.php';

$queue = new Kjoe\Kjoe('demo', __DIR__ . '/../var/queues');
$queue->duplicates = false;

$queue->put(['alpha']);
$queue->put(['bravo']);
$queue->put(['charlie']);

echo $queue->jobsRemaining() . ' jobs pending' . PHP_EOL;
