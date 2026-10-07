<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'inventory:expire-reservations';

    protected $description = 'Release stock held by unpaid reservations that passed their expiry time';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->expireStale();
        $this->info("Expired {$count} reservation(s).");

        return self::SUCCESS;
    }
}
