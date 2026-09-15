<?php
/**
 * Outbound alerting — Telegram.
 *
 * Two rules shape everything in this file:
 *
 *   1. Alerting must never be able to break a fetch. Every path is wrapped, every network
 *      call has a short timeout, and a failed send is only ever a log line.
 *   2. Alerts are throttled per key. An expired API key fails on all 480 ticks a day, and an
 *      unthrottled notifier turns one outage into a second one.
 *
 * What this covers: something is wrong *inside* the pipeline. What it cannot cover by
 * construction: nothing is running at all — a disabled scheduler, a fatal on startup, a dead
 * host. That is what blwp_ping_healthcheck() is for, and why both are needed.
 *
 * See docs/operations.md.
 */

require_once __DIR__ . '/logging.php';

// Fallback throttle window, in minutes, when intervals.alert_throttle is not configured.
// The same problem is announced at most once per window.
const BLWP_NOTIFY_THROTTLE_MINUTES = 240; // 4 hours

// Seconds. A fetch must never sit waiting on a messaging service.
const BLWP_NOTIFY_TIMEOUT = 5;

/**
 * Credentials and limits, loaded once and memoised.
 *
 * Returns an empty token/chat_id when nothing is configured, which is the normal state in
 * development and on a fresh clone — every call site then degrades to a silent no-op.
 */
function blwp_notify_settings(): array
{
    static $settings = null;

    if ($settings !== null) {
        return $settings;
    }

    $config = require BLWP_CONFIG_DIR . '/api-config.php';

    return $settings = [
        'token'    => trim((string) ($config['telegram_bot_token'] ?? '')),
        'chat_id'  => trim((string) ($config['telegram_chat_id'] ?? '')),
        'throttle' => (int) ($config['intervals']['alert_throttle'] ?? BLWP_NOTIFY_THROTTLE_MINUTES),
    ];
}

/**
 * Where the throttle bookkeeping lives.
 *
 * Deliberately its own file rather than a section of cron-state.json: the cron loads that
 * file once at startup and writes the whole array back at the end, which would silently
 * discard anything an alert had written to it mid-run.
 */
function blwp_notify_state_file(): string
{
    return BLWP_CONFIG_DIR . '/alerts.json';
}

function blwp_notify_state(): array
{
    $file = blwp_notify_state_file();

    if (!is_file($file)) {
        return [];
    }

    return json_decode((string) file_get_contents($file), true) ?: [];
}

function blwp_notify_state_save(array $state): void
{
    @file_put_contents(
        blwp_notify_state_file(),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

/**
 * Send an alert, at most once per key per throttle window.
 *
 * @param string $message Body text.
 * @param string $key     Stable identifier. Alerts sharing a key are throttled together, so
 *                        use something specific like "site:example.test" rather than a
 *                        single "error" key for everything.
 * @return bool           True when a message was actually delivered.
 */
function blwp_notify(string $message, string $key = 'general'): bool
{
    try {
        $settings = blwp_notify_settings();

        // Not configured — stay silent rather than failing.
        if ($settings['token'] === '' || $settings['chat_id'] === '') {
            return false;
        }

        $state = blwp_notify_state();
        $entry = $state[$key] ?? [];
        $suppressed = (int) ($entry['suppressed'] ?? 0);

        if (!empty($entry['sent_at'])) {
            $minutes_since = (time() - strtotime($entry['sent_at'])) / 60;

            if ($minutes_since < $settings['throttle']) {
                // Inside the quiet window: remember that it happened, say nothing.
                $entry['suppressed'] = $suppressed + 1;
                $state[$key] = $entry;
                blwp_notify_state_save($state);

                return false;
            }
        }

        // Tell the reader how much was held back, so a single message does not hide the fact
        // that the problem has been repeating for hours.
        if ($suppressed > 0) {
            $message .= "\n\n(+{$suppressed} more since the last alert for this problem)";
        }

        if (blwp_notify_send($settings['token'], $settings['chat_id'], $message)) {
            $state[$key] = ['sent_at' => date('c'), 'suppressed' => 0, 'active' => true];
            blwp_notify_state_save($state);

            return true;
        }

        return false;
    } catch (Throwable $e) {
        blwp_log('ALERT FAILED - ' . $e->getMessage());

        return false;
    }
}

/**
 * Send an all-clear for a key, but only while that key has an alert outstanding.
 *
 * Without this, silence is ambiguous: a throttled key and a fixed problem look identical.
 * Clears the key first, so a failed send cannot cause the recovery to be re-announced on
 * every following tick.
 */
function blwp_notify_recovered(string $key, string $message = ''): bool
{
    try {
        $state = blwp_notify_state();

        if (empty($state[$key]['active'])) {
            return false;
        }

        unset($state[$key]);
        blwp_notify_state_save($state);

        $settings = blwp_notify_settings();

        if ($settings['token'] === '' || $settings['chat_id'] === '') {
            return false;
        }

        if ($message === '') {
            $message = "Resolved: {$key}";
        }

        return blwp_notify_send($settings['token'], $settings['chat_id'], $message);
    } catch (Throwable $e) {
        blwp_log('ALERT FAILED - ' . $e->getMessage());

        return false;
    }
}

/**
 * POST a message to the Telegram Bot API.
 *
 * Never call this directly — go through blwp_notify() so throttling applies.
 */
function blwp_notify_send(string $token, string $chat_id, string $message): bool
{
    if (!function_exists('curl_init')) {
        blwp_log('ALERT NOT SENT - the curl extension is not available');

        return false;
    }

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");

    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'chat_id' => $chat_id,
            'text' => $message,
            // No parse_mode on purpose. Markdown or HTML would turn an unexpected _ or * in a
            // team name into an HTTP 400, and the alert would disappear without a trace.
            'disable_web_page_preview' => true,
        )),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => BLWP_NOTIFY_TIMEOUT,
        CURLOPT_TIMEOUT => BLWP_NOTIFY_TIMEOUT,
    ));

    curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    // curl_close($ch);  // consistent with inc/APIFootball.php

    if ($http_code === 200) {
        return true;
    }

    // The thing that reports problems must not fail silently itself. The URL carries the bot
    // token, so it is never logged — only the status and the transport error.
    $detail = $curl_error !== '' ? ": {$curl_error}" : '';
    blwp_log("ALERT NOT SENT (HTTP {$http_code}{$detail}) - {$message}");

    return false;
}

/**
 * Ping an external dead-man's switch (healthchecks.io and friends).
 *
 * This is the only check that can notice the pipeline stopped running entirely: a fatal, a
 * disabled cron entry or a dead host means no request is ever made, and the monitor alerts on
 * that silence. See docs/operations.md for the setup.
 *
 * Plain ping only, never the /fail variant — this signal means "the pipeline is alive", while
 * anything granular goes to Telegram. Reporting failures here too would double every alert.
 */
function blwp_ping_healthcheck(string $url): bool
{
    if ($url === '' || !function_exists('curl_init')) {
        return false;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => BLWP_NOTIFY_TIMEOUT,
        CURLOPT_TIMEOUT => BLWP_NOTIFY_TIMEOUT,
        CURLOPT_USERAGENT => 'blwp-cron',
    ));

    curl_exec($ch);
    $ok = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    // curl_close($ch);  // consistent with inc/APIFootball.php

    if (!$ok) {
        blwp_log('WARNING - healthcheck ping failed; the external monitor may report the cron as down');
    }

    return $ok;
}
