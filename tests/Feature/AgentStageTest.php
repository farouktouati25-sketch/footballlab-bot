<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Models\Message;
use App\Services\Ai\BookingAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_ad_lead_gets_the_welcome_group_then_day_flow(): void
    {
        $lead = Contact::create(['wa_id' => '971500000001', 'source' => 'meta_ad']);
        $agent = app(BookingAgent::class);

        $this->assertFalse($agent->isReturning($lead));
        $prompt = $agent->systemPrompt($lead);
        $this->assertStringContainsString('NEW LEAD', $prompt);
        $this->assertStringContainsString('Do NOT mention prices, payment, booking or upcoming games', $prompt);
        $this->assertStringContainsString('if they play football frequently', $prompt);
        $this->assertStringContainsString("Don't ask about their level", $prompt);
        $this->assertStringContainsString('we play every day', $prompt);
        $this->assertStringContainsString("Never ask if they've played WITH US", $prompt);
        $this->assertStringContainsString('Suggest joining our community group', $prompt);
        $this->assertStringContainsString("we'll send them a reminder for that game", $prompt);
    }

    public function test_known_players_skip_the_intro(): void
    {
        $agent = app(BookingAgent::class);

        $booked = Contact::create(['wa_id' => '971500000002', 'name' => 'Omar Khalid']);
        $game = Game::create(['starts_at' => now()->addDay(), 'venue' => 'Al Safa', 'price' => 50, 'capacity' => 14, 'status' => 'published']);
        Booking::create(['contact_id' => $booked->id, 'game_id' => $game->id, 'player_name' => 'Omar', 'players' => 1, 'amount' => 50, 'status' => 'cancelled']);

        $talkedToTeam = Contact::create(['wa_id' => '971500000003']);
        Message::create(['contact_id' => $talkedToTeam->id, 'direction' => 'out', 'author' => 'human', 'type' => 'text', 'body' => 'Hi bro']);

        $oldChat = Contact::create(['wa_id' => '971500000004']);
        $m = Message::create(['contact_id' => $oldChat->id, 'direction' => 'in', 'author' => 'client', 'type' => 'text', 'body' => 'price?']);
        $m->forceFill(['created_at' => now()->subDays(5)])->save();

        foreach ([$booked, $talkedToTeam, $oldChat] as $c) {
            $this->assertTrue($agent->isReturning($c), $c->wa_id);
        }

        $prompt = $agent->systemPrompt($booked);
        $this->assertStringContainsString('RETURNING player', $prompt);
        $this->assertStringContainsString('Hey Omar 👊', $prompt);
        $this->assertStringNotContainsString('NEW LEAD', $prompt);
    }

    public function test_admin_in_test_mode_gets_the_new_lead_flow_and_a_clean_history(): void
    {
        config(['whatsapp.admins' => ['971500000091']]);
        $admin = Contact::create(['wa_id' => '971500000091', 'player_name' => 'Farouk', 'act_as_client' => true]);
        Message::create(['contact_id' => $admin->id, 'direction' => 'out', 'author' => 'bot', 'type' => 'text', 'body' => 'FootballLab bot · admin commands']);
        Message::create(['contact_id' => $admin->id, 'direction' => 'in', 'author' => 'admin', 'type' => 'text', 'body' => '#test']);
        Message::create(['contact_id' => $admin->id, 'direction' => 'out', 'author' => 'bot', 'type' => 'text', 'body' => 'Test mode ON']);
        Message::create(['contact_id' => $admin->id, 'direction' => 'in', 'author' => 'client', 'type' => 'text', 'body' => 'Hey i saw your ad']);

        $agent = app(BookingAgent::class);
        $this->assertFalse($agent->isReturning($admin));
        $this->assertStringContainsString('NEW LEAD', $agent->systemPrompt($admin));

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['content' => [['type' => 'text', 'text' => 'Welcome to Football Lab ⚽']], 'stop_reason' => 'end_turn'])]);
        $agent->reply($admin);
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'anthropic')
            && count($r->data()['messages']) === 1
            && $r->data()['messages'][0]['content'] === 'Hey i saw your ad');
    }
}
