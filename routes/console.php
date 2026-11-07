<?php

use Illuminate\Support\Facades\Schedule;

// Release unpaid held spots and remind admins about bookings waiting for approval.
Schedule::command('bookings:housekeeping')->everyFiveMinutes()->withoutOverlapping();
