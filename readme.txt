=== CatCode Form Leads to CRM ===
Contributors: catcodestudio
Tags: contact form 7, crm, leads, telegram, keycrm
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends leads from your site forms to KeyCRM, SalesDrive, NetHunt, KeepinCRM and Telegram. No WooCommerce needed.

== Description ==

A site of services has no shop, but it has forms. This plugin takes every lead from a form and creates it in your CRM: the contact, the visitor's message, the other fields, the page and the ad source.

* **Four CRMs:** KeyCRM (an order or a pipeline card), SalesDrive, NetHunt (the folder and its fields are prepared for you), KeepinCRM (a deal with a lead client).
* **Telegram:** every lead to one or several chats and group topics, next to the CRM or on its own.
* **Contact Form 7** works out of the box. Field roles come from the field types and captions, so auto-generated names like `text-32` are fine. Fields without a role go to the CRM comment under their captions.
* **A lead is not lost when a CRM is down.** It is saved first and sent right after the visitor gets the answer. If the CRM does not answer, it is tried three more times within about 40 minutes.
* **A double click is one lead:** the same submission within 5 minutes is sent once.
* **A log of every delivery** with the reason of a failure, in plain view, and a notice on the plugin screen when something did not arrive.
* **Ad source:** utm tags, gclid and fbclid of the visit are remembered in a first-party cookie and added to the lead.
* **Buttons to check it:** "Check the connection" and "Send a test lead" for each connection.


KeyCRM, SalesDrive, NetHunt, KeepinCRM and Telegram are third-party products. This is an independent integration, not affiliated with or endorsed by them.

For developers: `do_action( 'catcode_flc_capture', $raw )` sends a lead from any custom form, the filter `catcode_flc_lead` changes or drops a lead, and the action `catcode_lead_captured` fires once per lead.

== Installation ==

1. Install and activate the plugin, and make sure Contact Form 7 is active.
2. Open Form Leads in the admin menu.
3. Choose the CRM, paste its key, save, and press "Check the connection".
4. Press "Send a test lead" and look in the CRM.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. The plugin works with forms on any WordPress site.

= What does it do with fields the CRM has no place for? =

They go to the comment of the lead, each under its caption. Nothing a visitor filled in is dropped, except consent boxes, file uploads and captchas.

= What happens to Contact Form 7 mail? =

Nothing changes: the form sends its mail as usual, this plugin only adds the delivery to the CRM.

= KeepinCRM answers 402 =

KeepinCRM opens its API on paid plans only. On a free plan every request is refused.

= Could a lead appear twice in the CRM? =

Yes, in one case: the CRM created the lead, but its answer did not reach the site. The plugin cannot tell this from a CRM that is down, so it tries again. A double is easier to fix than a lost lead.

= Does it store personal data? =

The log keeps the leads on your own site for 30 days by default. You can change the period and clear the log. The settings and the log are deleted together with the plugin, unless you switch that off.

== External services ==

The plugin sends data only to the services you connect, and nothing until you enter a key. For each of them, the data of a lead is: name, phone, e-mail, message, the other fields of the form, form title, page address, site address and the ad source (utm tags, gclid, fbclid, referring site, landing page) if that option is on. It is sent when a visitor submits a form and on repeated attempts; the test buttons on the settings screen send a sample instead.

**KeyCRM** (`https://openapi.keycrm.app/v1`): creates an order (`POST /order`) or a card (`POST /pipelines/cards`); reads the lists of sources and pipelines (`GET /order/source`, `GET /pipelines`). [Terms of use](https://keycrm.app/terms-of-use), [privacy policy](https://keycrm.app/privacy-policy).

**SalesDrive** (`https://<your account>.salesdrive.me`): creates a lead (`POST /handler/`); checks the key (`GET /api/statuses/`). [Terms of service](https://salesdrive.ua/oferta/), [privacy policy](https://salesdrive.ua/privacy-policy/).

**NetHunt** (`https://nethunt.com/api/v2`): creates a record (`POST /folders/{id}/records`); when the settings are saved, reads or creates the folder and its fields (`/folders`). [Terms of service](https://nethunt.com/legal/terms), [privacy policy](https://nethunt.com/legal/privacy).

**KeepinCRM** (`https://api.keepincrm.com/v1`): creates a deal with a lead client (`POST /agreements`); reads the lists of statuses, funnels and sources (`GET /clients/statuses`, `GET /agreements/funnels`, `GET /sources`). [Contract offer](https://keepincrm.com/contract-offer), [privacy policy](https://keepincrm.com/privacy).

**Telegram Bot API** (`https://api.telegram.org`): sends the lead as a message to your chats (`sendMessage`); checks the bot and finds chats on your request (`getMe`, `getUpdates`). [Terms of service](https://telegram.org/tos), [privacy policy](https://telegram.org/privacy).


The first-party cookie `catcode_flc_utm` keeps the ad source of the visit for 90 days. It goes only into the lead. You can switch it off in the settings.

== Changelog ==

= 1.0.2 =
* Posted settings and the source cookie are sanitised as soon as they are read.
* The notice about failed deliveries shows only on the plugin screen.

= 1.0.1 =
* Clearer settings page: every block is a card, the country code field shows the whole code, the extra CRMs sit in one box.
* The log shows statuses as coloured labels and the full lead in a box.

= 1.0.0 =
* First release.
