<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\Game;
use App\Services\Booking\WeeklySchedule;
use App\Services\Payments\PaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeeklyScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_1 = '971500000091';

    protected function setUp(): void
    {
        parent::setUp();
        config(['fbl.payments.mode' => 'admin_link', 'fbl.payment_link_day_before_at' => '12:00']);
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.out.'.uniqid()]]]));
        // Sunday 8 Nov 2026, 17:00 Dubai
        $this->travelTo(Carbon::parse('2026-11-08 17:00', 'Asia/Dubai'));
    }

    private function local(Game $g): string
    {
        return $g->starts_at->copy()->setTimezone('Asia/Dubai')->format('D Y-m-d H:i');
    }

    public function test_creates_the_week_once_with_the_right_terms(): void
    {
        $created = app(WeeklySchedule::class)->generate();
        $this->assertCount(8, $created); // tonight's Sunday game + the next 7 days

        $byDay = collect($created)->keyBy(fn (Game $g) => $this->local($g));
        $mon = $byDay['Mon 2026-11-09 21:00'];
        $this->assertSame(['published', '7v7', 53.0, 14], [$mon->status, $mon->format, (float) $mon->price, $mon->capacity]);

        $tue = $byDay['Tue 2026-11-10 21:00'];
        $this->assertSame(['draft', null], [$tue->status, $tue->format]);

        // Sat/Sun now wait for the admins too (format and pitch change every night).
        $sat = $byDay['Sat 2026-11-14 20:00'];
        $this->assertSame(['draft', null], [$sat->status, $sat->format]);

        $this->assertSame('draft', $byDay['Fri 2026-11-13 21:00']->status);

        // Running again creates nothing; a cancelled game is never re-created.
        $mon->update(['status' => 'cancelled']);
        $this->assertCount(0, app(WeeklySchedule::class)->generate());
    }

    public function test_skips_a_slot_the_admins_already_posted(): void
    {
        Game::create(['starts_at' => Carbon::parse('2026-11-09 21:00', 'Asia/Dubai')->utc(), 'venue' => 'AL SAFA SPORTS HALL',
            'price' => 53, 'capacity' => 14, 'status' => 'published']);

        // This Friday is already set at another pitch, 21:20.
        Game::create(['starts_at' => Carbon::parse('2026-11-13 21:20', 'Asia/Dubai')->utc(), 'venue' => 'North London Collegiate School - Nad Al Sheba 1',
            'price' => 58, 'capacity' => 14, 'status' => 'published']);

        $created = collect(app(WeeklySchedule::class)->generate());
        $this->assertFalse($created->contains(fn (Game $g) => $this->local($g) === 'Mon 2026-11-09 21:00'));
        $this->assertFalse($created->contains(fn (Game $g) => $this->local($g) === 'Fri 2026-11-13 21:00'));
    }

    public function test_asks_admins_the_format_the_day_before_and_publishes_on_tap(): void
    {
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
        app(WeeklySchedule::class)->generate();
        $tue = Game::where('weekly_key', 'tuesday@21:00')->sole();

        $this->assertSame(1, app(WeeklySchedule::class)->askFormats()); // only tonight's Sunday game (asked since Saturday noon); Tuesday too early

        $this->travelTo(Carbon::parse('2026-11-09 12:05', 'Asia/Dubai'));
        Contact::where('wa_id', self::ADMIN_1)->update(['last_inbound_at' => now()]);
        $this->assertSame(1, app(WeeklySchedule::class)->askFormats());
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && ($r->data()['interactive']['action']['buttons'][0]['reply']['id'] ?? null) === "fmt:{$tue->id}:8v8");

        $this->whatsapp(self::ADMIN_1, ['type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "fmt:{$tue->id}:8v8", 'title' => '8v8']]]);

        // Format saved, but players aren't told until the pitch is confirmed too.
        $tue->refresh();
        $this->assertSame(['draft', '8v8'], [$tue->status, $tue->format]);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && ($r->data()['interactive']['action']['buttons'][1]['reply']['id'] ?? null) === "loc:{$tue->id}:1");

        // Client asking now: the night is mentioned as not confirmed, nothing bookable.
        $listed = app(\App\Services\Ai\AgentTools::class)->run(Contact::firstOrCreate(['wa_id' => '971500000001']), 'list_games', []);
        $this->assertNotContains($tue->id, array_column($listed['games'], 'game_id'));
        $this->assertContains('Tuesday 10 November, 9:00 PM', $listed['nights_not_confirmed_yet']);

        $this->whatsapp(self::ADMIN_1, ['type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => "loc:{$tue->id}:1", 'title' => 'Al Safa']]]);

        $tue->refresh();
        $this->assertSame(['published', '8v8', 'Al Safa Sports Ground', 53.0, 16], [$tue->status, $tue->format, $tue->venue, (float) $tue->price, $tue->capacity]);

        // Typed version for Saturday, pitch first then format; Nad Al Sheba has its own price/spots.
        $sat = Game::where('weekly_key', 'saturday@20:00')->sole();
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => "edit {$sat->id} pitch nad al sheba"]]);
        $this->assertSame('draft', $sat->fresh()->status);
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => "edit {$sat->id} format 11v11"]]);
        $sat->refresh();
        $this->assertSame(['published', '11v11', 'North London Collegiate School - Nad Al Sheba', 58.0, 14],
            [$sat->status, $sat->format, $sat->venue, (float) $sat->price, $sat->capacity]);
    }

    public function test_edit_command_changes_price_and_spots(): void
    {
        Contact::create(['wa_id' => self::ADMIN_1, 'last_inbound_at' => now()]);
        app(WeeklySchedule::class)->generate();
        $mon = Game::where('weekly_key', 'monday@21:00')->sole();

        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => "edit {$mon->id} price 60"]]);
        $this->whatsapp(self::ADMIN_1, ['type' => 'text', 'text' => ['body' => "edit {$mon->id} spots 16"]]);

        $mon->refresh();
        $this->assertSame([60.0, 16], [(float) $mon->price, $mon->capacity]);
    }

    public function test_spot_is_reserved_now_and_the_link_is_requested_the_day_before(): void
    {
        app(WeeklySchedule::class)->generate();
        $thu = Game::where('weekly_key', 'thursday@21:00')->sole();
        $client = Contact::create(['wa_id' => '971500000001', 'last_inbound_at' => now()]);

        $booking = app(\App\Services\Booking\BookingService::class)->create($client, $thu->id, 'Ali', 2)['booking'];
        $this->assertNull(app(PaymentService::class)->linkFor($booking));
        $booking->refresh();
        $this->assertSame('2026-11-11 12:00', $booking->link_due_at->setTimezone('Asia/Dubai')->format('Y-m-d H:i'));
        $this->assertNull($booking->link_requested_at);

        // Two days later the reservation still holds and no link was requested yet.
        $this->travelTo(Carbon::parse('2026-11-10 23:00', 'Asia/Dubai'));
        $this->artisan('bookings:housekeeping')->assertSuccessful();
        $this->assertSame('pending_payment', $booking->fresh()->status);
        $this->assertNull($booking->fresh()->link_requested_at);
        $this->assertSame(12, $thu->fresh()->spotsLeft());

        // Wednesday noon: admins are asked for the link.
        $this->travelTo(Carbon::parse('2026-11-11 12:05', 'Asia/Dubai'));
        Contact::firstOrCreate(['wa_id' => self::ADMIN_1])->update(['last_inbound_at' => now()]);
        $this->artisan('bookings:housekeeping')->assertSuccessful();
        $this->assertNotNull($booking->fresh()->link_requested_at);
        Http::assertSent(fn (Request $r) => ($r->data()['to'] ?? null) === self::ADMIN_1
            && str_contains($r->data()['text']['body'] ?? '', 'needs a payment link'));
    }

    private function whatsapp(string $from, array $message): void
    {
        $json = json_encode(['entry' => [['changes' => [[
            'field' => 'messages',
            'value' => [
                'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Test']]],
                'messages' => [$message + ['id' => 'wamid.in.'.uniqid(), 'from' => $from, 'timestamp' => (string) time()]],
            ],
        ]]]]]);

        $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'test-secret'),
        ], $json)->assertOk();
    }
}
