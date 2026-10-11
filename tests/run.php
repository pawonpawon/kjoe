<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Kjoe\Kjoe;

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!function_exists('proc_open')) {
    fwrite(STDERR, "Tests require proc_open for real process concurrency checks.\n");
    exit(1);
}

$root = sys_get_temp_dir() . '/kjoe-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$tests = [];
$children = [];

function same($expected, $actual)
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function raises($class, $action)
{
    try {
        $action();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected ' . $class);
}

function queue($name)
{
    global $root;

    return new Kjoe($name, $root);
}

// PHP 7.3 proc_open() takes a command string. Escape each argument separately.
function phpCommand($arguments)
{
    $command = 'exec ' . escapeshellarg(PHP_BINARY);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    return $command;
}

function spawn($mode, $name, $id = '0', $count = 1)
{
    global $root, $children;
    $process = proc_open(
        phpCommand([__DIR__ . '/process.php', $mode, $root, $name, $id, (string) $count]),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start child.');
    }
    $children[] = $process;
    stream_set_timeout($pipes[1], 20);
    same("ready\n", fgets($pipes[1]));

    return [$process, $pipes];
}

function release($child)
{
    fwrite($child[1][0], "go\n");
    fflush($child[1][0]);
}

function finish($child)
{
    [$process, $pipes] = $child;
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $timedOut = stream_get_meta_data($pipes[1])['timed_out'];
    if ($timedOut) {
        proc_terminate($process, 9);
    }
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    same(false, $timedOut);
    same('', $errors);
    same(0, $code);

    return $output;
}

function drain($queue)
{
    $jobs = [];
    while (($job = $queue->take()) !== false) {
        $jobs[] = $job;
    }

    return $jobs;
}

$tests['empty queue, FIFO, reopening and independent names'] = function () {
    $queue = queue('fifo');
    same(0, $queue->jobsRemaining());
    same(false, $queue->hasJob());
    same(false, $queue->take());
    same(false, $queue->take());
    same(true, $queue->put('first'));
    $queue->put(['second', 2]);
    same(2, queue('fifo')->jobsRemaining());
    same(0, queue('other')->jobsRemaining());
    same(true, $queue->hasJob());
    same(['first'], queue('fifo')->take());
    same(['second', '2'], $queue->take());
    same(false, $queue->take());
};

$tests['CSV round trips commas, quotes, backslashes, newlines, unicode and long fields'] = function () {
    $queue = queue('csv');
    $records = [
        ['comma,value', 'say "hello"', 'ends with \\', '\\"quoted\\"'],
        ["LF\nnext", "CR\rnext", "CRLF\r\nnext", "\n", "\r"],
        ['雪', 'café', '', '0', "\0binary"],
        [str_repeat('x', 20000), "long\n" . str_repeat('y', 12000)],
        [''], ['', ''], ['tail'],
        ['"', '""', '"""', "\0\"", "tail\0"],
    ];
    foreach ($records as $record) {
        $queue->put($record);
    }
    same(count($records), $queue->jobsRemaining());
    same($records, drain($queue));
};

$tests['CSV strings, arrays and objects retain the original input forms'] = function () {
    $queue = queue('values');
    $queue->put('a,b');
    $queue->put('"a,b","say ""hello"""');
    $queue->put(['a,b']);
    $queue->put('');
    $queue->put(['key' => 0, 'next' => true, 'false' => false, 'null' => null, 'float' => 1.25]);
    $queue->put((object) ['action' => 'refresh', 'id' => 12]);
    same([['a', 'b'], ['a,b', 'say "hello"'], ['a,b'], [''], ['0', '1', '', '', '1.25'], ['refresh', '12']], drain($queue));
};

$tests['original CSV helpers still append and return CSV strings'] = function () {
    $queue = queue('csv-helpers');
    $queue->addLineToFile('"comma,value","say ""hello"""');
    $queue->put(["first\nsecond", '']);
    $queue->put('0');
    same("\"comma,value\",\"say \"\"hello\"\"\"\n", $queue->readAndRemoveFirstLine());
    same(["first\nsecond", ''], $queue->take());
    same("0\n", $queue->readAndRemoveFirstLine());
    same(false, $queue->readAndRemoveFirstLine());

    $queue->duplicates = false;
    same(true, $queue->addLineToFile("\n"));
    same(false, $queue->put(''));
    same([''], $queue->take());
};

$tests['duplicates use whole pending records, not substrings or historical jobs'] = function () {
    $queue = queue('duplicates');
    $queue->duplicates = false;
    same(true, $queue->put(['abc', "x\ny"]));
    same(true, $queue->put(['bc', "x\ny"]));
    same(true, $queue->put(['abc']));
    same(false, $queue->put(['abc', "x\ny"]));
    same(true, $queue->put([1]));
    same(false, $queue->put(['1']));
    same(['abc', "x\ny"], $queue->take());
    same(true, $queue->put(['abc', "x\ny"]));
    $queue->duplicates = true;
    same(true, $queue->put(['abc']));
    same(5, $queue->jobsRemaining());
};

$tests['clear discards pending jobs and existing instances remain usable'] = function () {
    $one = queue('clear');
    $two = queue('clear');
    $one->put('first');
    $two->clear();
    $two->clear();
    same(false, $one->take());
    $one->put('next');
    same(['next'], $two->take());
};

$tests['invalid names and unsupported payloads do not change the queue'] = function () {
    foreach (['', '../escape', 'a/b', 'a\\b', '.hidden', "x\0y", str_repeat('x', 65)] as $name) {
        raises(InvalidArgumentException::class, function () use ($name) {
            queue($name);
        });
    }
    raises(InvalidArgumentException::class, function () {
        new Kjoe('valid', 'php://memory');
    });
    raises(InvalidArgumentException::class, function () {
        new Kjoe('valid', '');
    });
    $queue = queue('invalid');
    $queue->put('keep');
    raises(InvalidArgumentException::class, function () use ($queue) {
        $queue->put([]);
    });
    raises(InvalidArgumentException::class, function () use ($queue) {
        $queue->put([['nested']]);
    });
    raises(InvalidArgumentException::class, function () use ($queue) {
        $queue->put(new stdClass());
    });
    raises(InvalidArgumentException::class, function () use ($queue) {
        $queue->put([(object) ['nested' => 1]]);
    });
    same([['keep']], drain($queue));
};

$tests['directory and missing-file failures are explicit'] = function () {
    global $root;
    file_put_contents($root . '/not-a-directory', 'x');
    raises(RuntimeException::class, function () use ($root) {
        new Kjoe('bad', $root . '/not-a-directory');
    });
    $queue = queue('missing');
    unlink($root . '/missing.csv');
    raises(RuntimeException::class, function () use ($queue) {
        $queue->take();
    });
    raises(RuntimeException::class, function () use ($queue) {
        $queue->jobsRemaining();
    });
};

$tests['append, dequeue and clear keep the same queue file'] = function () {
    global $root;
    $queue = queue('same-file');
    $path = $root . '/same-file.csv';
    // An already-open handle must continue to refer to the current queue.
    $handle = fopen($path, 'rb');
    try {
        $queue->put('first');
        $queue->put('second');
        same("first\nsecond\n", stream_get_contents($handle));
        same(['first'], $queue->take());
        rewind($handle);
        same("second\n", stream_get_contents($handle));
        $queue->clear();
        rewind($handle);
        same('', stream_get_contents($handle));
        same([$path], glob($root . '/same-file*'));
    } finally {
        fclose($handle);
    }
};

$tests['all public operations wait on the queue file lock'] = function () {
    global $root;
    foreach (['put-once', 'clear', 'count', 'has-job', 'consume'] as $mode) {
        $queue = queue('lock-' . $mode);
        $queue->put('old');
        $child = spawn($mode, $queue->name);
        $lock = fopen($root . '/' . $queue->name . '.csv', 'r+b');
        flock($lock, LOCK_EX);
        try {
            release($child);
            usleep(100000);
            same(true, proc_get_status($child[0])['running']);
            same("old\n", file_get_contents($root . '/' . $queue->name . '.csv'));
        } finally {
            fclose($lock);
        }
        $result = finish($child);
        if ($mode === 'put-once') {
            same([['old'], ['new']], drain($queue));
        } elseif ($mode === 'count') {
            same("1\n", $result);
        } elseif ($mode === 'has-job') {
            same("yes\n", $result);
        } else {
            same(0, $queue->jobsRemaining());
        }
    }
};

$tests['concurrent producers retain all jobs and each producer order'] = function () {
    $queue = queue('producers');
    $children = [];
    for ($id = 0; $id < 4; ++$id) {
        $children[] = spawn('produce', $queue->name, (string) $id, 40);
    }
    foreach ($children as $child) {
        release($child);
    }
    foreach ($children as $child) {
        finish($child);
    }
    $jobs = drain($queue);
    same(160, count($jobs));
    foreach (range(0, 3) as $id) {
        $own = [];
        foreach ($jobs as $job) {
            if ($job[0] === (string) $id) {
                $own[] = $job[1];
            }
        }
        $expected = [];
        for ($i = 0; $i < 40; $i++) {
            $expected[] = (string) $i;
        }
        same($expected, $own);
    }
};

$tests['concurrent duplicate checks and insertion are one operation'] = function () {
    $queue = queue('unique');
    $children = [];
    for ($id = 0; $id < 4; ++$id) {
        $children[] = spawn('unique', $queue->name, (string) $id, 35);
    }
    foreach ($children as $child) {
        release($child);
    }
    foreach ($children as $child) {
        finish($child);
    }
    same(35, $queue->jobsRemaining());
};

$tests['concurrent workers take each pending record once during normal operation'] = function () {
    $queue = queue('workers');
    foreach (range(0, 79) as $id) {
        $queue->put([(string) $id]);
    }
    $children = [];
    for ($id = 0; $id < 4; ++$id) {
        $children[] = spawn('consume', $queue->name);
    }
    foreach ($children as $child) {
        release($child);
    }
    $actual = [];
    foreach ($children as $child) {
        $jobs = json_decode(finish($child), true, 512, JSON_THROW_ON_ERROR);
        foreach ($jobs as $job) {
            $actual[] = $job[0];
        }
    }
    sort($actual, SORT_NUMERIC);
    $expected = [];
    for ($i = 0; $i < 80; $i++) {
        $expected[] = (string) $i;
    }
    same($expected, $actual);
    same(false, $queue->take());
};

$tests['producers and consumers overlap without losing or repeating records'] = function () {
    global $root;
    $queue = queue('mixed');
    $producers = [];
    $consumers = [];
    for ($id = 0; $id < 3; ++$id) {
        $producers[] = spawn('produce', $queue->name, (string) $id, 30);
        $consumers[] = spawn('mixed-consume', $queue->name);
    }
    foreach ($consumers as $child) {
        release($child);
    }
    foreach ($producers as $child) {
        release($child);
    }
    foreach ($producers as $child) {
        finish($child);
    }
    touch($root . '/producers-done');
    $actual = [];
    $expected = [];
    foreach ($consumers as $child) {
        foreach (json_decode(finish($child), true, 512, JSON_THROW_ON_ERROR) as $job) {
            same("comma, quote\" slash\\\nsecond line", $job[2]);
            $actual[] = $job[0] . ':' . $job[1];
        }
    }
    for ($id = 0; $id < 3; ++$id) {
        for ($i = 0; $i < 30; ++$i) {
            $expected[] = $id . ':' . $i;
        }
    }
    sort($expected);
    sort($actual);
    same($expected, $actual);
    same(0, $queue->jobsRemaining());
};

$tests['killing a worker after take loses that job; the next record remains'] = function () {
    $queue = queue('crash');
    $queue->put('lost');
    $queue->put('next');
    $child = spawn('take-and-wait', $queue->name);
    release($child);
    same(["lost"], json_decode(trim(fgets($child[1][1])), true, 512, JSON_THROW_ON_ERROR));
    proc_terminate($child[0], 9);
    foreach ($child[1] as $pipe) {
        fclose($pipe);
    }
    proc_close($child[0]);
    same([['next']], drain($queue));
};

$tests['failed append reports the error but can leave a partial last record'] = function () {
    global $root;
    if (!function_exists('posix_setrlimit') || !function_exists('pcntl_signal')) {
        echo "  SKIP fault injection: POSIX and PCNTL extensions unavailable\n";
        return;
    }
    $queue = queue('disk-failure');
    $queue->put('keep');
    $child = spawn('limited-put', $queue->name);
    release($child);
    same("write-failed\n", finish($child));
    $contents = file_get_contents($root . '/disk-failure.csv');
    same(true, strpos($contents, "keep\nx") === 0);
    same(false, substr($contents, -1) === "\n");
    $queue->clear();
    $queue->put('recovered');
    same([['recovered']], drain($queue));
};

$tests['failed in-place dequeue reports the error but can partly change the queue'] = function () {
    global $root;
    if (!function_exists('posix_setrlimit') || !function_exists('pcntl_signal')) {
        echo "  SKIP fault injection: POSIX and PCNTL extensions unavailable\n";
        return;
    }
    $queue = queue('shift-failure');
    $queue->put('first');
    $queue->put(str_repeat('x', 8192));
    $before = file_get_contents($root . '/shift-failure.csv');
    $child = spawn('limited-take', $queue->name);
    release($child);
    same("write-failed\n", finish($child));
    $after = file_get_contents($root . '/shift-failure.csv');
    same(false, $before === $after);
    same(true, strpos($after, 'xxxxxx') === 0);
    $queue->clear();
    $queue->put('recovered');
    same([['recovered']], drain($queue));
};

$tests['example producer is repeatable and the worker respects its batch limit'] = function () {
    global $root;
    // Run the examples in a temporary checkout so their queue stays isolated.
    $example = $root . '/example';
    mkdir($example . '/examples', 0700, true);
    mkdir($example . '/vendor', 0700);
    foreach (['producer.php', 'worker.php'] as $script) {
        copy(dirname(__DIR__) . '/examples/' . $script, $example . '/examples/' . $script);
    }
    $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
    file_put_contents($example . '/vendor/autoload.php', '<?php require ' . $autoload . ';');

    $run = function ($script) use ($example) {
        $process = proc_open(
            phpCommand([$example . '/examples/' . $script]),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        return finish([$process, $pipes]);
    };
    same("3 jobs pending\n", $run('producer.php'));
    same("3 jobs pending\n", $run('producer.php'));
    same("ALPHA\nBRAVO\nCHARLIE\n", $run('worker.php'));
    same('', $run('worker.php'));

    $queue = new Kjoe('demo', $example . '/var/queues');
    $first = '';
    $rest = '';
    for ($i = 1; $i <= 30; $i++) {
        $queue->put(['job-' . $i]);
        if ($i <= 25) {
            $first .= 'JOB-' . $i . "\n";
        } else {
            $rest .= 'JOB-' . $i . "\n";
        }
    }
    same($first, $run('worker.php'));
    same(5, $queue->jobsRemaining());
    same($rest, $run('worker.php'));
    same(false, $queue->hasJob());
};

$failed = 0;
try {
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo "PASS $name\n";
        } catch (Throwable $error) {
            ++$failed;
            echo "FAIL $name\n  " . $error->getMessage() . "\n";
        }
    }
} finally {
    foreach ($children as $child) {
        if (is_resource($child)) {
            proc_terminate($child, 9);
            proc_close($child);
        }
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
}
printf("\n%d tests, %d failed\n", count($tests), $failed);
if ($failed > 0) {
    exit(1);
}
exit(0);
