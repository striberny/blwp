<?php

/**
 * Thrown when the API credentials are missing or incomplete.
 *
 * Raised by config/api-config.php when secrets.php is absent, or when it does not
 * define both api_key and shared_secret — so a misconfiguration fails immediately
 * and loudly instead of surfacing later as a confusing upstream 401/403.
 */
class MissingApiKey extends InvalidArgumentException
{
}