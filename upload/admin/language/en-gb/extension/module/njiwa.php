<?php
// Heading
$_['heading_title']            = 'Njiwa WhatsApp';

// Text
$_['text_extension']           = 'Extensions';
$_['text_success']             = 'Your Njiwa settings have been saved.';
$_['text_edit']                = 'Njiwa sends the WhatsApp messages. Your shop tells it when.';
$_['text_enabled']             = 'Enabled';
$_['text_disabled']            = 'Disabled';
$_['text_choose_status']       = '-- choose one of your order statuses --';
$_['text_send_response']       = 'As soon as the shop has answered the customer';
$_['text_send_cron']           = 'Only when my cron job runs';
$_['text_asking']              = 'Asking Njiwa...';
$_['text_saved_only']          = 'Both buttons use the settings as they are saved, not as they are on the screen. Save first, then check.';
$_['text_placeholders']        = 'Each message is plain text. Anything in braces is filled in from the order:';
$_['text_test_key']            = '<strong>This is a test key.</strong> Every message is checked and stored, and nothing reaches WhatsApp. Swap it for a key beginning sk_live_ when you are ready.';
$_['text_no_instances']        = 'The key works, but this account has no numbers yet. Add one in the Njiwa console under Numbers and link it.';
$_['text_connected']           = 'Connected. This key can send from:';
$_['text_not_linked']          = 'not linked yet';
$_['text_from_unknown']        = '<strong>Send from does not match any number on this account, so every message will be refused.</strong> Correct it, or clear it to use the default number.';
$_['text_test_sent']           = 'Sent to +%s (%s).';
$_['text_test_was_test_key']   = '<strong>This is a test key, so nothing actually reached the phone.</strong>';
$_['text_test_message']        = 'Test message from %s. If you can read this, your shop can reach your customers on WhatsApp.';
$_['text_state']               = 'How it stands';
$_['text_event_registered']    = 'Listening to order status changes: yes.';
$_['text_event_missing']       = 'Listening to order status changes: <strong>no</strong>. Saving this page puts that right; if it says this again afterwards, remove the extension on the Extensions page and install it once more.';
$_['text_table_missing']       = 'The message table is missing, so nothing can be queued. Saving this page creates it.';
$_['text_waiting']             = 'Messages waiting to be sent: %s.';
$_['text_cron_url']            = 'Nothing has to call this address, though a shop that calls it retries a message on time rather than waiting for its next order. If you would rather no sending at all happened during a customer\'s visit, set sending to "%s" and have your host run this every minute:';

// Tabs
$_['tab_connection']           = 'Connection';
$_['tab_customer']             = 'Messages to your customers';
$_['tab_alert']                = 'The message to you';

// Entry
$_['entry_status']             = 'Send WhatsApp messages';
$_['entry_api_key']            = 'API key';
$_['entry_url']                = 'Njiwa address';
$_['entry_from']               = 'Send from';
$_['entry_country_code']       = 'Country code for local numbers';
$_['entry_send_mode']          = 'When to send';
$_['entry_event']              = 'Send this message';
$_['entry_order_status']       = 'When an order reaches';
$_['entry_template']           = 'What it says';
$_['entry_alert']              = 'Tell me about new orders';
$_['entry_alert_numbers']      = 'Your WhatsApp numbers';

// Help
$_['help_status']              = 'The master switch. Turn it off and this extension stops sending anything at all, without losing your key, your numbers or your wording. Orders carry on exactly as before.';
$_['help_api_key']             = 'A key beginning <code>sk_test_</code> checks and stores every message and delivers nothing, which is what you want while you set this up. A key beginning <code>sk_live_</code> sends to real phones, and real messages cost money. The console shows a key once and keeps only its fingerprint, so a lost key is replaced rather than recovered. OpenCart keeps this key in its settings table in plain text, the same as it keeps your payment gateway keys.';
$_['help_url']                 = 'Leave this exactly as it is. It exists for shops that have been given their own Njiwa address, and changing it otherwise stops messages reaching anybody.';
$_['help_from']                = 'Which of your linked WhatsApp numbers these messages come from. Digits only, in full international form, such as 254712345678. Leave it empty to use the number marked default in the console, which is the right answer if you have one number.';
$_['help_country_code']        = 'Only needed if your customers type local numbers and you sell in more than one country. Digits only, no plus: 254 for Kenya, 44 for the United Kingdom. A customer number starting with 0 then has that 0 replaced by this code. Left empty, numbers go to Njiwa as they were typed, and Njiwa reads them against the country of the number you send from, which is right for a shop selling in one country.';
$_['help_send_mode']           = 'Messages are never sent while the customer is waiting to be told their order went through. This says what happens next: send them the moment the shop has finished answering that customer, which needs nothing set up, or leave them for your own cron job, which keeps every send outside the shop entirely. Either way a message that could not be sent is tried again, on the next order that changes status or on the next cron run.';
$_['help_order_status']        = 'Order statuses belong to your shop, so pick the one that means this really happened here. Nothing is sent until you choose one.';
$_['help_template']            = 'Empty this box and nothing is sent for this moment, whatever the switch above says. That is how you keep a moment set up but silent.';
$_['help_alert']               = 'One message when an order becomes real, sent once. Not when the order row is created, which happens the moment somebody reaches the payment page and usually means nothing, so an abandoned checkout never wakes you up.';
$_['help_alert_numbers']       = 'Where that message goes. Digits only, in full international form, separated by commas if there are several. Everybody listed gets their own copy.';
$_['help_template_alert']      = '<code>{admin_url}</code> is worth having here: it opens the order in this dashboard from your phone, after you sign in.';

// The moments, and what each one is for
$_['event_placed']             = 'Order placed, payment not in yet';
$_['event_paid']               = 'Payment received';
$_['event_shipped']            = 'Sent, or on its way';
$_['event_cancelled']          = 'Order cancelled';
$_['event_refunded']           = 'Order refunded';

$_['about_placed']             = 'For bank transfer, cash on delivery and anything else where the order is placed before the money arrives. Tell them you have it and that you are waiting.';
$_['about_paid']               = 'The one most shops want. Payment has landed and you are getting the order ready.';
$_['about_shipped']            = 'It has left you. OpenCart sends its own email at the same moment; this arrives where people actually look.';
$_['about_cancelled']          = 'Worth sending. A cancellation nobody explained is what turns into a phone call.';
$_['about_refunded']           = 'Money is on its way back. Saying so stops the "where is my refund" message before it is sent.';

// What each placeholder is replaced with
$_['njiwa_placeholder'] = array(
	'{first_name}'     => 'The first name on the order, or "there" if it somehow has none.',
	'{last_name}'      => 'The last name on the order.',
	'{customer_name}'  => 'Both names together.',
	'{order_number}'   => 'The order number, as it appears in your order list.',
	'{order_total}'    => 'The total, in the currency the customer paid in.',
	'{order_date}'     => 'The date the order was placed.',
	'{order_status}'   => 'The status the order has just moved to, in your own words for it.',
	'{payment_method}' => 'How they paid, as shown on the order.',
	'{items}'          => 'One line per item, as "2 x Blue shirt".',
	'{item_count}'     => 'How many items in total.',
	'{shop_name}'      => 'The name of the store the order was placed in.',
	'{order_url}'      => 'A link to the order in the customer\'s account. Only useful to customers who have an account.',
	'{admin_url}'      => 'A link that opens the order in this dashboard. Only put this in the message to yourself.'
);

// Buttons
$_['button_test']              = 'Test connection';
$_['button_send_test']         = 'Send me a test message';

// Error
$_['error_permission']         = 'You do not have permission to change Njiwa settings.';
$_['error_url']                = 'A Njiwa address is needed. Put https://njiwa.upeo.ai back if you are not sure.';
$_['error_from']               = 'The sending number must be digits only, in full international form, such as 254712345678. A leading zero cannot be read here, because nothing knows which country it belongs to.';
$_['error_country_code']       = 'A country code is digits only, without the plus: 254, not +254.';
$_['error_no_status']          = 'Choose the order status that means this, or turn the message off.';
$_['error_status_clash']       = 'Two moments are set to the same order status, so one status change would send the customer two messages.';
$_['error_alert_numbers']      = 'Put at least one of your own WhatsApp numbers here, digits only, or turn the alert off.';
$_['error_test_numbers']       = 'Put your own WhatsApp number in "Your WhatsApp numbers" and save, then try again. The test goes there, never to a customer.';
$_['error_test_too_soon']      = 'That was less than half a minute ago. Give it a moment before sending another test.';
