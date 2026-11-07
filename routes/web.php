<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['app' => 'FootballLab WhatsApp bot', 'ok' => true]));

// Live roster page. Pin https://your-domain/games/{FBL_PUBLIC_LIST_TOKEN} in the group description.
Route::get('/games/{token}', \App\Http\Controllers\PublicRosterController::class);
