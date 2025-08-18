# Scheduled Sending  

Schedule messages to be transmitted when you want them to go.
Currently works good, but no attachment support at this time.

# Scheduled Sending — Installation Guide for Roundcube

This plugin lets users **schedule emails to be sent later**. It includes a web UI, localization, and CLI helpers to trigger a **queue worker** that delivers messages when they’re due.

This guide covers both **Composer-based** and **manual** installation, database setup, configuration, and setting up the worker trigger (cron/systemd). All steps are derived from the plugin code you provided.

---

## 1) Requirements
- Roundcube (modern version; plugin uses standard `rcube_plugin` API).
- PHP compatible with your Roundcube (PHP 8.x recommended).
- Database access for creating the plugin table.
- Ability to run a periodic job (cron or systemd timer) to call the worker endpoint.
- Web access to your Roundcube URL for the worker (or curl from cron).

---

## 2) Install the plugin

### Option A — Composer (preferred)
1. Place the plugin in a VCS or local path that Composer can reference.
2. Ensure your Roundcube root has the **Roundcube plugin installer** in `require` (most distros do):

   ```json
   "require": {
     "roundcube/plugin-installer": "^0.3"
   }
   ```

3. Add a repository that points to the plugin (adjust the path):

   ```json
   "repositories": [
     { "type": "path", "url": "../scheduled_sending_composer" }
   ],
   "require": {
     "gene/scheduled_sending": "*"
   }
   ```

4. Run:
   ```bash
   composer install
   # or
   composer require gene/scheduled_sending:*
   ```

> Composer will install the plugin under `plugins/scheduled_sending` (per its `composer.json`).

### Option B — Manual install
1. Unzip the plugin into Roundcube’s plugins directory:
   ```bash
   cd /path/to/roundcube
   unzip /tmp/scheduled_sending.zip -d plugins/scheduled_sending
   ```
2. Ensure permissions match your web server user:
   ```bash
   chown -R www-data:www-data plugins/scheduled_sending
   find plugins/scheduled_sending -type d -exec chmod 755 {} \;
   find plugins/scheduled_sending -type f -exec chmod 644 {} \;
   ```

---

## 3) Database schema

Create the queue table (MySQL/MariaDB):

- File: `plugins/scheduled_sending/SQL/mysql.initial.sql`
- SQL (for convenience):

```sql
CREATE TABLE `scheduled_queue` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `identity_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `scheduled_at` datetime NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `raw_mime` mediumtext DEFAULT NULL,
  `meta_json` text DEFAULT NULL,
  `dedupe_key` varchar(64) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `scheduled_queue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_scheduled_dedupe` (`dedupe_key`),
  ADD KEY `idx_sched_at` (`scheduled_at`),
  ADD KEY `idx_status` (`status`);

ALTER TABLE `scheduled_queue`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;
```

> If you use a database other than MySQL/MariaDB, adapt the SQL accordingly.

---

## 4) Configuration

Copy the example config and edit it:

```bash
cd plugins/scheduled_sending
cp config.inc.php.dist config.inc.php
```

Key options (from `config.inc.php.dist`):

```php
$config['scheduled_sending_table']       = 'scheduled_queue';
$config['scheduled_worker_batch']        = 20;
$config['scheduled_timezone']            = 'America/Chicago'; // optional; storage is UTC
$config['scheduled_debug']               = false;
$config['scheduled_force_plugin_assets'] = false;
$config['scheduled_show_fab']            = true;

$config['scheduled_sending_worker_token'] = '32_character_key';
$config['scheduled_sending_delivery']     = 'smtp';  // 'smtp', 'mail', or 'none' for dry-run
$config['scheduled_sending_batch']        = 25;      // optional
$config['scheduled_sending_sent_folder']  = 'Sent';  // optional

$config['db_table_scheduled_sending']     = 'scheduled_queue';

$config['scheduled_sending_lock_key']     = 'scheduled_sending_worker';
$config['scheduled_sending_lock_timeout'] = 10; // seconds
```

**Important: set a strong** `scheduled_sending_worker_token` (32+ random characters).

---

## 5) Enable the plugin in Roundcube

Edit your Roundcube main config (e.g. `config/config.inc.php`) and add the plugin name:

```php
// Add 'scheduled_sending' to the plugins array
$config['plugins'] = array_merge($config['plugins'] ?? [], ['scheduled_sending']);
```

Clear Roundcube caches if needed (e.g., remove `temp/*` & `cache/*` contents, keeping the dirs).

---

## 6) Queue worker — how delivery is triggered

The plugin exposes an **HTTP worker action** that sends all messages scheduled at or before “now”. The worker requires the token from your `config.inc.php`.

**Worker URL shape:**

```
https://YOUR-ROUNDCUBE-BASE/?_task=mail&_action=plugin.scheduled_sending.send_due&_token=YOUR_SECRET
```

There are two convenient ways to call it periodically:

### A) Via provided CLI helper (recommended)

- `bin/scheduled_queue_worker.php` makes an HTTP request to the worker URL.
- `bin/scheduled_send.php` is a shim that requires the worker script.

**Examples:**

```bash
# Using CLI flags
php plugins/scheduled_sending/bin/scheduled_queue_worker.php   --url="https://mail.example.com/roundcube/"   --token="YOUR_SECRET"

# Or via environment variables
SS_WORKER_URL="https://mail.example.com/roundcube/" SS_WORKER_TOKEN="YOUR_SECRET" php plugins/scheduled_sending/bin/scheduled_queue_worker.php
```

**Cron:** run once per minute (or every 5 minutes if your use-case is lax):

```cron
* * * * * SS_WORKER_URL="https://mail.example.com/roundcube/" SS_WORKER_TOKEN="YOUR_SECRET"     php /var/www/roundcube/plugins/scheduled_sending/bin/scheduled_queue_worker.php >> /var/log/roundcube/scheduled_worker.log 2>&1
```

### B) Direct HTTP call (curl/wget)

```bash
curl -fsS "https://mail.example.com/roundcube/?_task=mail&_action=plugin.scheduled_sending.send_due&_token=YOUR_SECRET"
```

Cron variant:

```cron
* * * * * curl -fsS "https://mail.example.com/roundcube/?_task=mail&_action=plugin.scheduled_sending.send_due&_token=YOUR_SECRET" >> /var/log/roundcube/scheduled_worker.log 2>&1
```

> The worker is idempotent and gated by a lock (`scheduled_sending_lock_key` / timeout) to avoid overlap.

---

## 7) Verifying

1. Log in to Roundcube, compose a message, pick a **future time**, and schedule.
2. Confirm a row is added to the `scheduled_queue` table.
3. Ensure your cron/systemd job runs; the message should send at/after the scheduled time.
4. Check logs:
   - Web server access/error logs
   - `logs/` or a dedicated log (the plugin writes via `rcube::write_log('scheduled_sending', ...)` when enabled)
5. Confirm sent messages land in the configured **Sent** folder (if set).

---

## 8) Troubleshooting

- **401/403 on worker call**: bad or missing `_token`. Verify `scheduled_sending_worker_token` matches the value you pass.
- **Nothing gets sent**: verify the cron is running and the URL points to your Roundcube base. Check that due items exist in `scheduled_queue` and `status` is `queued`.
- **Timezones**: UI may use `scheduled_timezone`; storage is UTC. Ensure your server clock is correct (NTP) and PHP `date.timezone` is set.
- **Mail transport**: `scheduled_sending_delivery` uses Roundcube’s SMTP by default. If using `mail` or a relay, ensure it’s configured correctly in Roundcube.
- **Locks**: if you see messages about an active lock, either reduce the cron frequency or increase `scheduled_sending_lock_timeout`.

---

## 9) Uninstall

- Remove the plugin folder or uninstall via Composer.
- Optionally drop the table:
  ```sql
  DROP TABLE IF EXISTS `scheduled_queue`;
  ```

---

## 10) File map (high-level)

- `scheduled_sending.php` — main plugin class (registers actions/UI)
- `config.inc.php.dist` — example configuration
- `SQL/mysql.initial.sql` — schema for the queue table
- `bin/scheduled_queue_worker.php` — CLI helper (HTTP to worker)
- `bin/scheduled_send.php` — thin wrapper including the worker helper
- `templates/` — Roundcube templates for UI
- `localization/` — i18n strings
- `skins/` / `js/` — assets

---

**That’s it.** Once the table exists, the config is set (especially `_token`), and the cron/systemd job is running, scheduled emails will go out on time.

Enjoy!

:moneybag: **Donations** :moneybag:

If you use this plugin and would like to show your appreciation by buying me a cup of coffee, I surely would appreciate it. A regular cup of Joe is sufficient, but a Starbucks Coffee would be better ... \
Zelle (Zelle is integrated within many major banks Mobile Apps by default) - Just send to texxasrulez at yahoo dot com \
No Zelle in your banks mobile app, no problem, just click [Paypal](https://paypal.me/texxasrulez?locale.x=en_US) and I can make a Starbucks run ...

I appreciate the interest in this plugin and hope all the best ...
