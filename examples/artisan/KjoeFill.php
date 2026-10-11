<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kjoe\Kjoe;

class KjoeFill extends Command
{
    protected $signature = 'kjoe:fill';
    protected $description = 'Add a few example jobs';

    public function handle()
    {
        $queue = new Kjoe('demo', storage_path('app/kjoe'));
        $queue->duplicates = false;

        $queue->put(['alpha']);
        $queue->put(['bravo']);
        $queue->put(['charlie']);

        $this->line($queue->jobsRemaining() . ' jobs pending');

        return 0;
    }
}
