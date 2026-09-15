<?php
/**
 * BLWP Bootstrap - Environment detection and path configuration
 *
 * This file sets up the paths for both dev and production environments.
 * Production: /home/deploy/blwp/ (flat structure)
 * Dev: blwp/backend/ (subfolder structure)
 */

// Determine environment and set directory paths
if (file_exists('/home/deploy/blwp/config/api-config.php')) {
    // Production: flat structure under /home/deploy/blwp/
    define('BLWP_CONFIG_DIR', '/home/deploy/blwp/config');
    define('BLWP_LIB_DIR', '/home/deploy/blwp/lib');
    define('BLWP_INC_DIR', '/home/deploy/blwp/inc');
    define('BLWP_LOGS_DIR', '/home/deploy/blwp/logs');
    define('BLWP_DATA_PATH', '/var/www/api.fcbinside.de/htdocs/data');
    define('BLWP_ENV', 'production');
} else {
    // Development: backend subfolder structure
    $backend = __DIR__;  // This file is in blwp/backend/
    define('BLWP_CONFIG_DIR', $backend . '/config');
    define('BLWP_LIB_DIR', $backend . '/lib');
    define('BLWP_INC_DIR', $backend . '/inc');
    define('BLWP_LOGS_DIR', $backend . '/logs');
    define('BLWP_DATA_PATH', realpath($backend . '/../api/data'));
    define('BLWP_ENV', 'development');
}

// Legacy compatibility (deprecated - use directory-specific constants instead)
define('BLWP_PRIVATE_PATH', dirname(BLWP_CONFIG_DIR));
define('BLWP_CONFIG_PATH', BLWP_CONFIG_DIR);
define('BLWP_LIB_PATH', BLWP_LIB_DIR);
define('BLWP_INC_PATH', BLWP_INC_DIR);
define('BLWP_LOG_PATH', BLWP_LOGS_DIR);

// Logging is part of the bootstrap rather than something each entry point defines for itself.
// It used to be redefined in every entry point — three copies to keep in sync, and a fatal
// error further down whenever a new one forgot to define it.
require_once BLWP_LIB_DIR . '/logging.php';
