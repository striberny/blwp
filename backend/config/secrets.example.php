<?php
/**
 * TEMPLATE for config/secrets.php — copy this file and fill in real values.
 *
 *   cp secrets.example.php secrets.php
 *
 * secrets.php is gitignored because it holds live credentials. It must exist on
 * every machine and on the production server. Without it, every entry point that
 * loads api-config.php fails immediately with a clear message.
 *
 * See docs/operations.md#security
 */

return [
    // api-sports.io key — sent as the `x-apisports-key` header
    'api_key' => '',

    // Shared secret for POST /v1/register.php (X-Secret-Key header).
    // Must match the `blwp_api_secret_key` option on every registered site.
    // Treat this as the crown jewel: it is the only gate on site registration.
    'shared_secret' => '',

    // --- Alerting (optional) -------------------------------------------------
    // Both Telegram values are needed to switch alerts on. Leave them empty and every call
    // to blwp_notify() becomes a silent no-op, which is what development wants.
    //
    // Create the bot with @BotFather (/newbot), then send it /start — a bot cannot open a
    // conversation itself and sendMessage returns 403 until you have. Read the chat id from
    // https://api.telegram.org/bot<token>/getUpdates (message.chat.id).
    'telegram_bot_token' => '',
    'telegram_chat_id' => '',

    // Dead-man's switch ping URL from healthchecks.io (https://hc-ping.com/<uuid>).
    // Pinged once at the end of every tick; the monitor alerts when it stops arriving.
    'healthcheck_ping_url' => '',
];
