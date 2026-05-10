<?php

if (!defined('ABSPATH')) {
    exit;
}

class OLX_BA_Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    private static function log(string $level, string $message, array $context): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, [
                'source' => 'olx-ba-sync',
                'context' => $context,
            ]);
        }
    }
}
