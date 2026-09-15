<?php
/**
 * Shared application log.
 *
 * This used to be defined three times — once in cron/fetch_all.php, once in
 * api/v1/fetch.php, once in api/v1/register.php — each with its own hard-coded prefix. A
 * new entry point that forgot to define it would fatal the moment utils.php tried to log.
 * bootstrap.php loads it for every entry point now.
 *
 * Log discipline: a healthy run writes nothing. Anything that fires on every tick belongs
 * in cron-state.json, not in this file. See docs/operations.md.
 */

if (!function_exists('blwp_log')) {

    // Rotate once the file passes this size, keeping a single previous generation.
    if (!defined('BLWP_LOG_MAX_BYTES')) {
        define('BLWP_LOG_MAX_BYTES', 1048576); // 1 MB
    }

    /**
     * Append one line to the shared log, rotating first if the file has grown too large.
     *
     * Never throws. Logging must not be able to break a fetch halfway through.
     */
    function blwp_log($message)
    {
        try {
            if (!is_dir(BLWP_LOGS_DIR)) {
                @mkdir(BLWP_LOGS_DIR, 0775, true);
            }

            $log_file = BLWP_LOGS_DIR . '/api.log';

            if (is_file($log_file) && filesize($log_file) >= BLWP_LOG_MAX_BYTES) {
                // One generation only. A retry loop stuck on the same error would otherwise
                // grow this file without any bound.
                @rename($log_file, $log_file . '.1');
            }

            // Entry points that share this file set their own tag, e.g. [FETCH].
            $prefix = defined('BLWP_LOG_PREFIX') && BLWP_LOG_PREFIX !== ''
                ? '[' . BLWP_LOG_PREFIX . '] '
                : '';

            @file_put_contents(
                $log_file,
                '[' . date('Y-m-d H:i:s') . "] {$prefix}{$message}\n",
                FILE_APPEND
            );
        } catch (Throwable $e) {
            // Nothing sensible to do: the only remaining option would be to write to the log
            // that just failed.
        }
    }

    /**
     * Log only when verbose logging is on.
     *
     * Used for the "everything is normal" output — the per-tick counts, the files that were
     * saved, the validations that passed. Set log_verbose = true in api-config.php to get it
     * back while diagnosing something.
     */
    function blwp_log_verbose($message)
    {
        if (defined('BLWP_LOG_VERBOSE') && BLWP_LOG_VERBOSE) {
            blwp_log($message);
        }
    }
}
