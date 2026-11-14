<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Game;
use App\Services\Ai\AgentTools;
use App\Services\Ai\BookingAgent;
use App\Services\Booking\BookingService;
use App\Services\Booking\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TournamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_interest_is_saved_and_invited_first_when_a_tournament_is_published(): void
    {
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]));
        Contact::create(['wa_id' => '971500000091', 'last_inbound_at' => now()]);
        $ali = Contact::create(['wa_id' => '971500000001', 'name' => 'Ali', 'last_inbound_at' => now()]);

        $out = app(AgentTools::class)->run($ali, 'tournament_interest', ['with_team' => true]);
        $this->assertTrue($out['saved']);
        $this->assertNotNull($ali->fresh()->tournament_interest_at);

        $this->assertStringContainsString('TOURNAMENTS: yes, we do tournaments', app(BookingAgent::class)->systemPrompt($ali));

        $game = Game::create(['starts_at' => now()->addDays(3), 'venue' => 'Dubai Schools Al Barsha', 'format' => 'tournament',
            'price' => 70, 'capacity' => 48, 'status' => 'published']);
        $this->assertSame(1, app(ReminderService::class)->notifyForGame($game));
        Http::assertSent(fn (Request $r) => str_contains($r->data()['text']['body'] ?? '', 'asked about tournaments'));

        // A whole team books together.
        $team = app(BookingService::class)->create($ali, $game->id, 'Team Ali', 9);
        $this->assertTrue($team['ok']);
    }
}
