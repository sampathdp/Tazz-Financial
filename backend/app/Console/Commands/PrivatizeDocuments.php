<?php

namespace App\Console\Commands;

use App\Support\DocStore;
use Illuminate\Console\Command;

class PrivatizeDocuments extends Command
{
    protected $signature = 'files:privatize';

    protected $description = 'Move customer / loan / speed-draft documents from the web-reachable public disk to the private documents disk';

    public function handle(): int
    {
        $this->info('Moved ' . DocStore::privatize() . ' file(s) to the private documents disk.');

        return self::SUCCESS;
    }
}
