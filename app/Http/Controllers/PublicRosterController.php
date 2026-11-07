<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Services\Booking\RosterService;

/** Live list of upcoming games and players. Pin the link in the WhatsApp group description. */
class PublicRosterController extends Controller
{
    public function __invoke(string $token, RosterService $roster)
    {
        $expected = (string) config('fbl.public_list_token');
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        $games = Game::upcoming()->limit(10)->get()->map(fn (Game $g) => [
            'game' => $g,
            'names' => $roster->names($g),
            'left' => max(0, $g->capacity - $g->confirmedPlayers()),
        ]);

        return response()
            ->view('roster', [
                'games' => $games,
                'bookUrl' => 'https://wa.me/'.config('fbl.bot_number').'?text='.rawurlencode('Hi, I want to book a game'),
                'currency' => config('fbl.currency'),
            ])
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
