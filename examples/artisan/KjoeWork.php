<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kjoe\Kjoe;

class KjoeWork extends Command
{
    protected $signature = 'kjoe:work';
    protected $description = 'Process up to 25 jobs';

    public function handle()
    {
        $queue = new Kjoe('demo', storage_path('app/kjoe'));

        for ($i = 0; $i < 25; $i++) {
            $job = $queue->take();
            if ($job === false) {
                break;
            }

            // Do your work here.
            $this->line(strtoupper($job[0]));
        }

        return 0;
    }
}
