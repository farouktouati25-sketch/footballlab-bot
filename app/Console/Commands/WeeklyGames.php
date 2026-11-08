<?php

namespace App\Console\Commands;

use App\Services\Booking\WeeklySchedule;
use Illuminate\Console\Command;

class WeeklyGames extends Command
{
    protected $signature = 'games:weekly';

    protected $description = 'Create the fixed weekly games ahead of time and ask admins for this week\'s format where it changes';

    public function handle(WeeklySchedule $weekly): int
    {
        $created = $weekly->generate();
        $asked = $weekly->askFormats();
        $this->info(count($created).' game(s) created, '.$asked.' format question(s) sent.');

        return self::SUCCESS;
    }
}
