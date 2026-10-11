# Laravel and Lumen examples

The two example Artisan commands are [KjoeFill.php](artisan/KjoeFill.php) and
[KjoeWork.php](artisan/KjoeWork.php). After installing KJOE with Composer, copy
these files into your application's `app/Console/Commands/` directory.

Laravel normally discovers commands in that directory automatically.

In Lumen, add them to the `$commands` list in `app/Console/Kernel.php`:

```php
protected $commands = [
    \App\Console\Commands\KjoeFill::class,
    \App\Console\Commands\KjoeWork::class,
];
```

If your Lumen application does not already register its console kernel, add this
in `bootstrap/app.php`, before `return $app`:

```php
$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);
```

Run the examples:

```sh
php artisan kjoe:fill
php artisan kjoe:work
```

The producer adds three words. The worker takes up to 25 jobs and prints the words
in uppercase. Both use `storage/app/kjoe` for the queue. Replace the example work
with your own code.

To run them from cron, change these paths to your application's directory:

```cron
0 2 * * * /usr/bin/php /home/you/myapp/artisan kjoe:fill >> /home/you/myapp/storage/logs/kjoe.log 2>&1
* * * * * /usr/bin/php /home/you/myapp/artisan kjoe:work >> /home/you/myapp/storage/logs/kjoe.log 2>&1
```

The daily producer is optional if your application already adds jobs as needed.
Use a PHP version supported by your Laravel or Lumen installation.
