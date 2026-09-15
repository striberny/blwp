<?php
/**
 * Image Proxy with Resize + WebP Cache
 *
 * Fetches images from api-sports.io, resizes them to 2× the requested display
 * size using multi-step downscaling, converts to WebP (quality 100), caches
 * to disk, and serves them. The browser then does a clean 2:1 final scale.
 *
 * Usage: GET /v1/img.php?url=https://media.api-sports.io/...&s=20
 *
 * Params:
 *   url  - Original image URL (must be from media.api-sports.io)
 *   s    - Display size in pixels (default: 32, min: 12, max: 64)
 *          The proxy generates an image at 2× this size for sharpness.
 */

// Locate bootstrap based on environment
if (file_exists('/home/deploy/blwp/bootstrap.php')) {
    require_once '/home/deploy/blwp/bootstrap.php';
} else {
    require_once __DIR__ . '/../../backend/bootstrap.php';
}

require_once BLWP_LIB_DIR . '/cors.php';
handle_cors();

// --- Input validation ---
$raw_url = $_GET['url'] ?? '';
$size    = isset($_GET['s']) ? (int) $_GET['s'] : 32;

// Only proxy images from the known api-sports.io CDN
if (empty($raw_url) || !preg_match('#^https://media\.api-sports\.io/#', $raw_url)) {
    http_response_code(400);
    exit('Invalid URL');
}

// Clamp display size: minimum 12px, maximum 64px
$size = max(12, min(64, $size));

// Render at 2× display size — browser does a clean 2:1 final downscale.
// Cap at 150px (original source resolution).
$render_size = min($size * 2, 150);

// --- Cache path (sibling to api/data/ and api/v1/) ---
$cache_dir = dirname(BLWP_DATA_PATH) . '/cache/img';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

$cache_key  = md5($raw_url . '_' . $size);
$cache_file = $cache_dir . '/' . $cache_key . '.webp';

// --- Serve from cache if available ---
if (file_exists($cache_file)) {
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=2592000'); // 30 days
    header('X-Cache: HIT');
    readfile($cache_file);
    exit;
}

// --- Fetch original from api-sports.io ---
$ctx = stream_context_create([
    'http' => [
        'timeout'         => 5,
        'follow_location' => true,
        'user_agent'      => 'BuliWidgets/1.0',
    ]
]);

$image_data = @file_get_contents($raw_url, false, $ctx);
if ($image_data === false || strlen($image_data) < 100) {
    http_response_code(502);
    exit('Failed to fetch image');
}

// --- Decode image ---
$source = @imagecreatefromstring($image_data);
if ($source === false) {
    http_response_code(502);
    exit('Failed to decode image');
}

$orig_w = imagesx($source);
$orig_h = imagesy($source);

// --- Multi-step downscaling for best quality ---
// Halve dimensions repeatedly until within 2× of the render target,
// then do one final precise step. This keeps each bilinear pass ≤2:1
// where it performs well, avoiding the blur of large single-step resamples.
$cur   = $source;
$cur_w = $orig_w;
$cur_h = $orig_h;

while ($cur_w / 2 > $render_size && $cur_h / 2 > $render_size) {
    $next_w = (int) ($cur_w / 2);
    $next_h = (int) ($cur_h / 2);
    $tmp = imagecreatetruecolor($next_w, $next_h);
    imagealphablending($tmp, false);
    imagesavealpha($tmp, true);
    $t = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
    imagefilledrectangle($tmp, 0, 0, $next_w, $next_h, $t);
    imagealphablending($tmp, true);
    imagecopyresampled($tmp, $cur, 0, 0, 0, 0, $next_w, $next_h, $cur_w, $cur_h);
    if ($cur !== $source) imagedestroy($cur);
    $cur   = $tmp;
    $cur_w = $next_w;
    $cur_h = $next_h;
}

// Final step to exact render size
$canvas = imagecreatetruecolor($render_size, $render_size);
imagealphablending($canvas, false);
imagesavealpha($canvas, true);
$transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
imagefilledrectangle($canvas, 0, 0, $render_size, $render_size, $transparent);
imagealphablending($canvas, true);

imagecopyresampled($canvas, $cur, 0, 0, 0, 0, $render_size, $render_size, $cur_w, $cur_h);
if ($cur !== $source) imagedestroy($cur);
imagedestroy($source);

// --- Save as WebP to cache (quality 100 — icons are tiny) ---
imagewebp($canvas, $cache_file, 100);
imagedestroy($canvas);

// --- Serve ---
header('Content-Type: image/webp');
header('Cache-Control: public, max-age=2592000'); // 30 days
header('X-Cache: MISS');
readfile($cache_file);
exit;
