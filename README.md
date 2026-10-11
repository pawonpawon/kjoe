# KJOE

**A small, self-contained PHP job queue for low-cost shared hosting.**

Put work in a queue and process it later, a few jobs at a time. KJOE is one PHP
class that stores jobs in a CSV file. It needs no database, queue server, or
framework. A short worker script called by cron is enough.

## Why I built it

I wrote KJOE around 2020 for stock integrations running on inexpensive shared
hosting. PHP, disk space, and cron were available, so I built something that
could run with those.

The original queue ran three webshop syncs across six feeds for five years
without problems in those installations. It handled thousands of products a day,
and tens of thousands in the larger shops.

An earlier version used a separate file for every job. That created too many
files and was slow on the hosting I used, so I switched to one CSV file per queue.
This version keeps that approach, with the Lumen dependency removed and some
fixes to locking and CSV handling.

## How it works

`put()` adds a job at the end of the file. `take()` returns the first job and
removes it, moving the remaining contents forward in small chunks. The whole
queue never needs to be loaded into memory.

Your worker decides what to do with each job. I used it for stock updates, but
it can also hold tasks such as creating thumbnails, refreshing pages, or building
exports. Jobs can come from application code, an API, or an import script.

```php
use Kjoe\Kjoe;

$queue = new Kjoe('tasks', __DIR__ . '/queues');
$queue->put(['hello from kjoe']);

$job = $queue->take();
if ($job !== false) {
    echo strtoupper($job[0]);
}
```

A job is removed when you take it. If the worker crashes afterward, KJOE does
not retry it automatically.

## Installation

Requires PHP 7.3+ and a writable directory on a local Linux filesystem with
`flock()` support. Keep the queue directory outside your public web directory.

To try it:

```sh
git clone https://github.com/pawonpawon/kjoe.git
cd kjoe
composer install
php examples/producer.php
php examples/worker.php
```

To add it to an existing Composer project:

```sh
composer config repositories.kjoe vcs https://github.com/pawonpawon/kjoe
composer require pawonpawon/kjoe:dev-main
```

You can also copy [src/Kjoe.php](src/Kjoe.php) to `lib/Kjoe.php` in your project,
include [LICENSE](LICENSE), and load the class directly:

```php
require __DIR__ . '/lib/Kjoe.php';
```

## A simple producer and worker

These scripts go in your application directory, alongside Composer's `vendor/`
directory. The producer adds work; the worker processes it later.

**producer.php**

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$queue = new Kjoe\Kjoe('demo', __DIR__ . '/queues');
$queue->duplicates = false;

$queue->put(['alpha']);
$queue->put(['bravo']);
$queue->put(['charlie']);
```

**worker.php**

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$queue = new Kjoe\Kjoe('demo', __DIR__ . '/queues');

for ($i = 0; $i < 25; $i++) {
    $job = $queue->take();
    if ($job === false) {
        break;
    }

    // Do your work here.
    echo strtoupper($job[0]) . PHP_EOL;
}
```

The worker prints `ALPHA`, `BRAVO`, and `CHARLIE`. Replace that line with your own
job handling. Working copies of both scripts are in [examples/](examples/).

## Cron

Run the worker every minute, processing up to 25 jobs each time. Change the paths
to match your hosting account:

```cron
* * * * * /usr/bin/php /home/you/myapp/worker.php >> /home/you/myapp/worker.log 2>&1
```

If you fill the queue on a schedule, add a producer entry too. For example,
once a day at 02:00:

```cron
0 2 * * * /usr/bin/php /home/you/myapp/producer.php >> /home/you/myapp/worker.log 2>&1
```

You can also add jobs directly from your application while the worker runs from cron.

## Laravel and Lumen

Use the same class with your application's storage directory:

```php
$queue = new Kjoe\Kjoe('tasks', storage_path('app/kjoe'));
$queue->put(['hello']);
```

The example Artisan commands are in
[examples/artisan/KjoeFill.php](examples/artisan/KjoeFill.php) and
[examples/artisan/KjoeWork.php](examples/artisan/KjoeWork.php).
See [the short setup instructions](examples/README.md) for copying them into
Laravel or Lumen and running them from cron.

## API

| Method or setting | What it does |
| --- | --- |
| `new Kjoe($name, $directory)` | Opens or creates a named queue. |
| `put($item)` | Adds a job. Returns `true`, or `false` if it skips a duplicate. |
| `take()` | Removes and returns the oldest job as an array. Returns `false` when empty. |
| `hasJob()` | Returns whether the queue has any jobs. |
| `jobsRemaining()` | Counts the pending jobs. |
| `clear()` | Empties the queue. |
| `$queue->duplicates = false` | Skips identical jobs already waiting in the queue. Duplicates are allowed by default. |

### Job data

Use an array of values for each job. Values are returned as strings; array keys
are discarded. Objects use their property values. Nested data can be encoded
as JSON in a field.

A string is treated as CSV: `put('a,b')` becomes `['a', 'b']` when taken.
Use `put(['a,b'])` to keep the comma inside one value. Quotes and newlines are
handled by the CSV functions.

Duplicate checks compare the whole job. Once a job has been taken, the same job
can be added again.

## License

[MIT](LICENSE).
