<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Services\Ai\AgentTools;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoalkeeperTest extends TestCase
{
    use RefreshDatabase;

    public function test_keeper_is_marked_and_matches_the_plain_name_in_a_post(): void
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]));
        $admin = Contact::create(['wa_id' => '971500000091', 'last_inbound_at' => now()]);
        $omar = Contact::create(['wa_id' => '971500000001', 'last_inbound_at' => now()]);
        $game = Game::create(['starts_at' => now()->addHours(6), 'venue' => 'Al Safa Sports Ground', 'format' => '8v8', 'price' => 53, 'capacity' => 16, 'status' => 'published']);

        app(AgentTools::class)->run($omar, 'create_booking', ['game_id' => $game->id, 'player_name' => 'Omar', 'players' => 1, 'goalkeeper' => true]);
        $booking = Booking::sole();
        $this->assertSame('Omar 🧤', $booking->player_name);
        $booking->update(['status' => 'confirmed']);

        // The admin's post lists him as plain "Omar": not added twice.
        app(BookingService::class)->syncPostPlayers($game, ['Omar', 'Ali'], $admin);
        $this->assertSame(2, $game->fresh()->confirmedPlayers());
        $this->assertSame('omar', Booking::plainName('Omar (GK)'));
    }
}
