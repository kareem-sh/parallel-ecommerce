<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class NfrLogger
{
    public static function success(string $message, array $context = []): void
    {
        Log::channel('nfr')->info($message, $context);
        Log::channel('success')->info($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        Log::channel('nfr')->warning($message, $context);
        Log::channel('error')->warning($message, $context);
    }
}
