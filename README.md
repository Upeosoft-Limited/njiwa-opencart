# Njiwa for OpenCart

WhatsApp your customers when their order is paid, sent or cancelled, and get a
message yourself when one comes in.

**This is for OpenCart 3.x** (3.0.0 to 3.0.3.9), PHP 7.4 or newer. OpenCart 4
moved and renamed nearly everything this extension touches, including the event
this one listens to, so it will not work there and does not pretend to.

## Install

The `upload` folder mirrors your OpenCart folder, so either:

- from the top of this repository run `zip -r njiwa.ocmod.zip upload`, then use
  **Extensions → Installer**. The archive must have `upload/` at its top level:
  OpenCart's installer copies only the entries whose path begins `upload/`, so
  an archive made from what is *inside* that folder installs nothing and says
  it succeeded. Or
- copy the three folders inside `upload` - `admin`, `catalog` and `system` -
  into your OpenCart root over FTP.

Then go to **Extensions → Extensions → Modules**, find **Njiwa WhatsApp** and
press the green **+** to install it, then the blue pencil to open its settings.

Pressing **+** is what makes the extension work at all. It does two things:

- it adds a row to OpenCart's event table so the extension hears about order
  status changes;
- it creates one table, `njiwa_message`, which is the record of every message
  and the thing that stops a customer being messaged twice.

The settings page tells you whether both of those are in place, at the bottom
of the **Connection** tab. If it says it is not listening, saving the page puts
that right.

## Set it up

Paste your API key from [console.upeo.ai](https://console.upeo.ai) → API keys,
save, then press **Test connection**. It lists the WhatsApp numbers your Njiwa
account actually has, so you find out now rather than at the moment a customer
should have been messaged.

**Start with a test key.** A key beginning `sk_test_` checks and stores every
message and delivers nothing. Turn on the messages you want, place a test
order, move it through your statuses and read `storage/logs/njiwa.log`. It says
what was sent, to whom, and Njiwa's own id for it, and a test send is marked
"Test key, so nothing reached WhatsApp". Only when that reads right, swap in
the `sk_live_` key. A live key sends real messages, and real messages cost
money.

OpenCart keeps extension settings, including this key, in plain text in its
settings table, exactly as it keeps your payment gateway keys. It has nowhere
encrypted to put them.

| Setting | What it is for |
| --- | --- |
| Send WhatsApp messages | The master switch. Off keeps every setting and sends nothing. |
| API key | `sk_test_` delivers nothing, `sk_live_` sends for real. |
| Njiwa address | Leave it alone unless you were given your own. |
| Send from | Which of your numbers sends. Empty means the account default. |
| Country code for local numbers | Only if you sell in more than one country. |
| When to send | Straight after the shop has answered the customer, or only on your cron. |
| Each message | On, off, which of your statuses means it, and the exact wording. |
| Your WhatsApp numbers | Where the new-order alert goes. Several, comma separated. |

Every message is off until you turn it on, and every one arrives with wording
that works unedited.

## Your statuses, not ours

OpenCart order statuses belong to each shop. Yours may be the ones OpenCart
ships with, or **Awaiting M-Pesa**, **Out for delivery** and **Boda sent**. So
this extension does not guess: you say which of your own statuses means each
moment.

| Moment | Who hears about it | You choose |
| --- | --- | --- |
| Order placed, payment not in yet | The customer | the status your gateway sets before payment |
| Payment received | The customer | the status you use for paid |
| Sent, or on its way | The customer | the status you use for shipped |
| Order cancelled | The customer | your cancelled status |
| Order refunded | The customer | your refunded status |
| The first status the order gets at all | You, once | nothing to choose |

A message with no status chosen is not saved as switched on; the page says so
rather than letting you leave believing it works. Two moments cannot share one
status either, because one status change would then send the same customer two
messages at the same instant.

**The alert to you needs no status.** OpenCart writes an order row while the
customer is still on the checkout, with no status at all, and gives it a real
status only when the payment method says so. That first status is the moment
the order became real, whatever it is called in your shop, and the alert goes
out then, once per order. Orders that already had a status before you switched
the alert on are never announced later, so marking a week-old order as sent
does not tell you it is new.

## The wording

Plain text with placeholders in braces. The settings page lists them all with
what each one means; they are `{first_name}`, `{last_name}`, `{customer_name}`,
`{order_number}`, `{order_total}`, `{order_date}`, `{order_status}`,
`{payment_method}`, `{items}`, `{item_count}`, `{shop_name}`, `{order_url}` and
`{admin_url}`.

**Emptying a wording box sends nothing** for that moment, whatever its switch
says. That is how you keep one set up but silent.

A placeholder that does not exist, `{order_no}` say, is removed before sending
rather than posted to a customer, and a line in `storage/logs/njiwa.log` tells
you where to look.

Two are worth a word of warning. `{order_url}` opens the order in the
customer's account, which only helps customers who have an account; guests are
better sent your phone number. `{admin_url}` opens the order in your own
dashboard after you sign in, and it only knows where your dashboard is because
this page wrote its own address down the last time you saved it. If you rename
your admin folder, save this page again.

## Nothing waits for WhatsApp

A shop must never wait on a message to take an order, so nothing is sent while
the customer is being told their order went through. The message is written
down as the status changes, which is a single row in a table, and the sending
happens afterwards.

**When to send** decides what "afterwards" means:

- **As soon as the shop has answered the customer** — the usual answer, and it
  needs nothing set up. On PHP-FPM hosting, which is nearly all of it, the
  answer goes back to the browser first and the sending happens after that, so
  nobody waits at all. On older hosting the sending happens at the very end of
  the same request: it can add a moment to a page, but the order is already
  saved and cannot be affected.
- **Only when my cron job runs** — nothing at all happens during a customer's
  visit. The settings page shows an address ending in a secret token. Give it
  to your host to call every minute:

  ```
  * * * * * curl -s "https://your-shop.example/index.php?route=extension/module/njiwa/run&token=..."
  ```

  Keep the token to yourself; anybody who has it can make your shop send what
  is already queued, though nothing else.

**A message that could not be sent is tried again, and both settings have
something that does the trying.** With **as soon as the shop has answered the
customer**, what picks it up is the next order that changes status: every status
change sends its own messages and whatever else is due with them. On a shop
taking orders that is a few minutes; on a quiet shop it can be the next morning.
With a cron job it is tried again on the next run, whether or not another order
ever arrives, which is why the cron address is worth setting up on either
setting.

## Things worth knowing

**Nothing is sent twice.** Each message claims the order, the moment and the
recipient in the `njiwa_message` table before it is sent, and there can only be
one such claim. It also carries an idempotency key, so if a send does somehow
run twice inside 24 hours, Njiwa replays the first answer instead of messaging
the customer again.

**Everything that happened is in `storage/logs/njiwa.log`,** and only what this
extension did, so you are not reading past every other notice your theme
produced. Every send, every refusal and every "this order has no phone number"
is in there, with the order number.

**Njiwa being unreachable is not a failure.** The message was never accepted,
so it goes back in the queue and is tried again, up to five times, with a few
minutes between attempts so that one bad minute cannot use all five up at once.
A refusal is different: Njiwa has looked at the message and said no, and repeating it would
only produce the same answer, so it stops and the reason is recorded.

**A customer with no phone number is normal.** Nothing is sent, nothing is
complained about, and a line goes in the log.

**Numbers are cleaned up, not second-guessed.** `+254 712 345 678`,
`0712345678` and `254712345678` are all understood. A local number keeps its
leading zero unless you have filled in a country code, because Njiwa reads a
recipient against the country of the number you send from. Anything that is not
a phone number is refused: in particular a WhatsApp **group** address, which
would post your customer's order to everybody in that group.

**Several stores.** `{shop_name}` is the name of the store the order was placed
in. The rest of the settings are shared.

**Removing the extension removes the key.** Press the red minus on the
Extensions page and the settings, the API key and the event row all go. The
`njiwa_message` table stays: it is the record of what was sent, and it is also
what stops the extension messaging everybody all over again if you put it back
tomorrow. Drop it by hand if you really want it gone.

## What it does not do

**It does not receive replies.** Inbound WhatsApp arrives as a webhook and
verifying one needs that number's signing secret, which the console does not
yet show. Until it does, a receiving feature could not check that a request
really came from Njiwa, so there is not one.

**It does not run campaigns.** Bulk sending to past customers is what the Njiwa
console is for, on Business plans and above.

**It does not keep its own copy of your messages.** Njiwa already stores every
message, its status and its failure reason. The table here holds what was sent
and what happened to it, not a second archive to keep in step.

---

Docs: https://docs.njiwa.upeo.ai · Console: https://console.upeo.ai
UPEO.AI · hello@upeo.ai · 0116888777 on WhatsApp
