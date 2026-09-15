<?php
/**
 * HTTP endpoint for manual data fetch
 * Triggered by WordPress "Refresh Data Now" button
 *
 * Usage: GET /v1/fetch.php?domain=testwp.test&token=abc123
 */

date_default_timezone_set('Europe/Berlin');

// Early logging - capture ALL requests even if script crashes
$early_log_file = __DIR__ . '/../../backend/logs/fetch-debug.log';
$early_log_dir = dirname($early_log_file);
if (!is_dir($early_log_dir)) {
    mkdir($early_log_dir, 0755, true);
}
file_put_contents($early_log_file,
    "[" . date('Y-m-d H:i:s') . "] REQUEST START\n" .
    "URL: " . ($_SERVER['REQUEST_URI'] ?? 'N/A') . "\n" .
    "Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A') . "\n" .
    "GET params: " . json_encode($_GET) . "\n" .
    "Referer: " . ($_SERVER['HTTP_REFERER'] ?? 'N/A') . "\n\n",
    FILE_APPEND
);

// Locate bootstrap based on environment
if (file_exists('/home/deploy/blwp/bootstrap.php')) {
    require_once '/home/deploy/blwp/bootstrap.php';
} else {
    require_once __DIR__ . '/../../backend/bootstrap.php';
}

header('Content-Type: application/json');

require_once BLWP_LIB_DIR . '/fetch-functions.php';

$config = require BLWP_CONFIG_DIR . '/api-config.php';
$config_dir = BLWP_CONFIG_DIR;

// Logging function
function blwp_log($message) {
    $log_file = BLWP_LOGS_DIR . '/api.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[{$timestamp}] [FETCH] {$message}\n", FILE_APPEND);
}

// blwp_log("Fetch API called - Method: {$_SERVER['REQUEST_METHOD']}");

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use GET.']);
    exit;
}

// Get parameters
$domain = $_GET['domain'] ?? '';
$token = $_GET['token'] ?? '';

// blwp_log("Request - Domain: {$domain}, Token: " . ($token ? '[PRESENT]' : '[MISSING]'));

// Validate required parameters
if (empty($domain) || empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing domain or token']);
    exit;
}

// Load site mapping to verify token
$mapping_file = $config_dir . '/site-mapping.json';
if (!file_exists($mapping_file)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Site mapping not found']);
    exit;
}

$mapping = json_decode(file_get_contents($mapping_file), true);
$site = null;

foreach ($mapping as $entry) {
    if ($entry['domain'] === $domain) {
        $site = $entry;
        break;
    }
}

// Verify site exists
if (!$site) {
    blwp_log("ERROR - Site not found: {$domain}");
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Site not found']);
    exit;
}

// Verify token
if (!isset($site['api_token']) || $site['api_token'] !== $token) {
    blwp_log("ERROR - Invalid token for domain: {$domain}");
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

// Check if site is enabled
if (empty($site['enabled'])) {
    blwp_log("ERROR - Site disabled: {$domain}");
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Site is disabled']);
    exit;
}

// Rate limiting check (configurable interval)
$rate_limit_interval = $config['intervals']['http_rate_limit'] ?? 5;
$cron_state_file = $config_dir . '/cron-state.json';
$cron_state = file_exists($cron_state_file)
    ? json_decode(file_get_contents($cron_state_file), true)
    : ['global' => [], 'sites' => []];

$last_fetch = $cron_state['sites'][$domain]['last_manual_fetch'] ?? null;
if ($last_fetch) {
    $minutes_since_fetch = (time() - strtotime($last_fetch)) / 60;
    if ($minutes_since_fetch < $rate_limit_interval) {
        $wait_time = ceil($rate_limit_interval - $minutes_since_fetch);
        blwp_log("RATE LIMIT - Domain {$domain} must wait {$wait_time} minutes");
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => "Rate limit exceeded. Please wait {$wait_time} minute(s).",
            'retry_after' => $wait_time
        ]);
        exit;
    }
}

// blwp_log("Starting forced fetch for: {$domain}");

// Update global standings first (force update)
$cron_state = update_global_standings($config, $cron_state, true);

// Fetch site data (force update)
$result = fetch_site_data($domain, $config, true);

if ($result['success']) {
    // Update manual fetch timestamp in cron state
    if (!isset($cron_state['sites'][$domain])) {
        $cron_state['sites'][$domain] = [];
    }
    $cron_state['sites'][$domain]['last_manual_fetch'] = date('c');
    $cron_state['sites'][$domain]['last_update'] = date('c');
    $cron_state['sites'][$domain]['last_update_type'] = [
        'fixtures' => true,
        'standings' => true
    ];

    // Save cron state
    file_put_contents($cron_state_file, json_encode($cron_state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    blwp_log("SUCCESS - Fetched data for {$domain}: {$result['fixtures_count']} fixtures, {$result['results_count']} results");

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Data refreshed successfully',
        'data' => [
            'domain' => $result['domain'],
            'fixtures_count' => $result['fixtures_count'],
            'results_count' => $result['results_count'],
            'generated_at' => $result['generated_at']
        ]
    ]);
} else {
    blwp_log("ERROR - Failed to fetch data for {$domain}: {$result['error']}");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $result['error']
    ]);
}
