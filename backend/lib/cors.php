<?php
/**
 * CORS Handler - Validates origin against allowed domains
 * Include this at the top of any endpoint that needs CORS
 */

function handle_cors() {
    // List of allowed origins (add your production domains here)
    $allowed_origins = [
        // Production
        'https://fcbinside.de',
        'https://www.fcbinside.de',
        'https://nurdieraute.de',
        // Add more sites as needed:
        // 'https://site2.de',
        // 'https://www.site2.de',

        // Development
        'http://testwp.test',
        'http://localhost',
        'http://localhost:3000',
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Check if origin is allowed
    if (in_array($origin, $allowed_origins, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, X-Secret-Key");
        header("Access-Control-Max-Age: 86400"); // Cache preflight for 24 hours
    }

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Auto-execute when included
handle_cors();
