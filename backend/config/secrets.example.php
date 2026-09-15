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
];
