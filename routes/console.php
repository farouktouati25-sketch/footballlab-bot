<?php

use Illuminate\Support\Facades\Schedule;

// Release unpaid held spots and remind admins about bookings waiting for approval.
Schedule::command('bookings:housekeeping')->everyFiveMinutes()->withoutOverlapping();

// Fixed weekly games: create them a week ahead and ask admins the format the day before where it varies.
Schedule::command('games:weekly')->everyFifteenMinutes()->withoutOverlapping();
