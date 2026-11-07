# FootballLab DXB · WhatsApp AI booking bot

Laravel 12 app that answers WhatsApp leads on **+971 50 374 1554** with Claude, takes bookings, and sends each paid booking to **both admins** for a one-tap Confirm/Reject. The first admin to tap wins, the client gets the confirmation, and the other admin is told who decided.

```
Client (ad / organic) ──► AI replies, lists this week's games (from the forwarded group post)
                      ──► picks game → name + players → booking held, pay link sent
                      ──► sends payment screenshot (or says "paid")
Admins (you + partner) ◄── "Booking FBL-7K3QX needs your OK" + screenshot + [Confirm] [Reject]
first tap wins ──► client: "You're in!"   other admin: "FBL-7K3QX confirmed by Farouk"
```

## What it does

| Area | Behaviour |
|---|---|
| AI replies | Claude answers in English or Arabic using only the facts in `config/fbl.php` and the live game list. It never confirms a booking itself. |
| Schedule | Forward your group announcement to the bot number (or start a message with `#games`). Claude extracts the games, and you tap **Publish**. The API can't read WhatsApp groups, so this forward is the bridge. |
| Booking | Spots are counted live, so a game can't be overbooked. Unpaid holds are released after 120 minutes. |
| Payment proof | An image or PDF sent while a booking is open is treated as payment proof. The bot re-uploads it so admins see the screenshot. |
| Approval | Both admins get buttons. A database lock makes sure only the first tap counts. A late tap gets "already confirmed by X". |
| 24h rule | WhatsApp only allows free-form messages within 24h of the person's last message. Outside that window the bot automatically uses your approved templates. |
| Human takeover | If you reply to a client from the WhatsApp Business app (coexistence), the bot stays quiet in that chat for 12h. A rejected payment also pauses the bot for that client. |
| Ad attribution | The first Click-to-WhatsApp ad that brought each contact is stored in `contacts.first_referral`. |

### Admin commands (send to the bot number from an admin phone)

```
help                       this list
(forward group post)       read games → Publish / Discard
#games <text>              same, typed manually
games                      upcoming games, confirmed/capacity, spots left
list 12                    game #12's list, ready to paste in the group
who 12                     same, with phone numbers + booking refs
add 12 Omar [2]            player who joined in the group (optionally 2 players)
remove 12 Omar             or: remove 12 FBL-XXXXX
copy 12 22:30              second game like #12 at 22:30 the same day; waitlist is told
copy 12 2026-11-07 21:00   same, another day
pending                    bookings waiting for approval
confirm FBL-XXXXX          approve by text (same as the button)
reject FBL-XXXXX
cancel game 12             stop bookings; tells you how many paid players need a refund
pause 9715… / resume 9715… bot off/on for one client
#test                      toggle: chat with the bot as if you were a client
```

### Keeping the group list in sync

The bot can't post in your WhatsApp group: Meta's Groups API only works on new API-created groups of up to 8 people. So the bot is the master list, and the group gets a copy:

1. **After every change** (a bot booking confirmed, `add`, `remove`), each admin with an open chat gets the updated list in your usual numbered format. Long-press, copy, paste in the group.
2. **Live page:** set `FBL_PUBLIC_LIST_TOKEN` and pin `https://YOUR-DOMAIN/games/<token>` in the group description. It refreshes every minute, shows first name + last initial only, and has a "Book on WhatsApp" button. Group members can check spots any time without anyone reposting.
3. **Second game:** when a game fills, clients can join a waitlist and admins get a nudge with the exact `copy …` command. Sending it publishes the new game, gives you the list to paste, and messages everyone waiting.

---

## Setup

### 1. WhatsApp side: 360dialog with coexistence (recommended)

Meta blocks a business from onboarding its own number with coexistence through an app the same business owns, so a BSP is needed. The code supports 360dialog out of the box (`WHATSAPP_PROVIDER=360dialog`).

1. Sign up at 360dialog as a direct client and choose a plan.
2. Start the **Coexistence** onboarding and pick the FootballLab business portfolio. Enter +971 50 374 1554 and **scan the QR code with the WhatsApp Business app on your phone**. Allow chat history sync if you want the bot to see the last 6 months.
3. In the 360dialog Hub, generate an **API key** for the number. That key is `WHATSAPP_TOKEN`.
4. Set `WHATSAPP_WEBHOOK_SECRET` to a random 32+ character string, deploy, then run `php artisan whatsapp:set-webhook` once.
5. Check that app→API echoes (`smb_message_echoes`) reach the webhook: reply to someone from your phone, and `pause` should show in the DB. If they don't arrive, ask 360dialog support to enable echoes for the number.

After onboarding: keep the WhatsApp Business app installed and open it at least once every 13 days, or the link drops. Greeting/Away messages, quick replies, broadcast lists and group sync stop working. Linked companion devices (WhatsApp Web/Desktop) are logged out once and can be re-linked.

<details><summary>Direct Meta Cloud API instead (no coexistence: the number leaves the phone app)</summary>

1. Create a Business app at developers.facebook.com and add WhatsApp.
2. Create a System User with a permanent token (`whatsapp_business_messaging`, `whatsapp_business_management`) and put it in `WHATSAPP_TOKEN`. Also set `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN` and `WHATSAPP_PROVIDER=meta`.
3. Configure the webhook at `https://YOUR-DOMAIN/api/webhooks/whatsapp` with your verify token, subscribed to **messages**.
</details>

### Templates

Create these in WhatsApp Manager (or the 360dialog Hub) → Message templates, category **Utility**, language English. The names must match `.env`.

| Name | Body | Buttons |
|---|---|---|
| `booking_approval_request` | `New FootballLab booking {{1}} needs your approval. Client: {{2}}. Game: {{3}}. Details: {{4}}. Payment: {{5}}. Tap a button below to decide.` | Quick reply: `Confirm`, `Reject` (in this order) |
| `admin_alert` | `FootballLab booking system update: {{1}}. Reply here to manage it.` | none |
| `waitlist_game_opened` | `Hi {{1}}, a new FootballLab game just opened: {{2}}. Reply here if you want a spot.` | none |
| `booking_update` | `Hi {{1}}, here is an update on your FootballLab booking {{2}}: {{3}}. Reply here if you have any questions.` | none |

Sample values for review: `FBL-7K3QX` · `Ali (+971501234567)` · `Thu 6 Nov, 9:00 PM · Al Safa Sports Ground` · `2 player(s), 100 AED` · `screenshot attached`.

> Templates only matter when the 24h window is closed. If you message the bot once a day (for example `games`), admin alerts arrive as rich buttons with the screenshot instead.

### 2. Laravel Forge

1. Push this folder to a private GitHub repo and create a Forge site from it (PHP 8.3, MySQL).
2. Copy `.env.example` into the site's Environment. Fill in everything, then run `php artisan key:generate`.
3. Add this to the deploy script, after `composer install`:
   ```
   php artisan migrate --force
   php artisan config:cache
   php artisan queue:restart
   ```
4. Under **Queue**, add a worker: connection `database`, queue `default`, `--tries=3 --timeout=160`. Webhooks only queue the work; the worker sends the replies.
5. Under **Scheduler**, turn on the scheduler. It runs `bookings:housekeeping` every 5 minutes, which releases unpaid holds and sends a 30-minute "still waiting for your OK" reminder.
6. Turn on SSL (Let's Encrypt). Meta needs HTTPS.

> Keep `DB_QUEUE_RETRY_AFTER` (200) above the worker `--timeout` (160), or a slow AI reply gets picked up twice.

### 3. First run

1. From your phone, send `help` to +971 50 374 1554. This opens your 24h window. Your partner should do the same.
2. Forward this week's group post and tap **Publish**.
3. Send `#test`, then message as a client: book a game and send any image as the "screenshot". Confirm from your partner's phone. Send `#test` again to leave test mode.
4. Watch `storage/logs/laravel.log` for `WhatsApp send failed`. The most common cause is a template name or language mismatch.

### Tests

```
composer install
php artisan test
```

The feature tests fake both WhatsApp and Claude, and cover:
- webhook verification and signatures;
- the AI booking with tool calls;
- a screenshot going to both admins, as buttons or as a template;
- the first tap winning;
- the human takeover pause;
- schedule import and publish;
- overbooking protection, including a failed rebooking keeping the old hold and an admin being blocked from confirming past capacity;
- duplicate webhooks.

---

## Costs (rough)

- **Claude:** one reply is usually 1–3 API calls. With `claude-sonnet-5-5`, expect a few US cents per conversation. Set `CLAUDE_MODEL=claude-haiku-5-5` to make it cheaper, at the cost of slightly weaker booking logic.
- **WhatsApp:** Meta charges per message under its current pricing. That covers replies inside the 24h window and template sends outside it. Check WhatsApp Manager → Insights for the actual numbers.

## Known limits

- When an admin's window is closed, the approval template carries text only. The screenshot can't be attached, so if you want to see it, open the client's chat on your phone.
- If a client sends a second screenshot for the same booking, the admins get the approval request again.
- Refunds and paid-booking cancellations are handed to a human on purpose.
- Edit what the AI may say in `config/fbl.php` and the `FBL_*` variables. It is told never to state anything else about the business.
