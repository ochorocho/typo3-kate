<?php

declare(strict_types=1);

namespace Bmack\K8sHealth\Check;

final class Duration
{
    public static function format(int $seconds): string
    {
        $seconds = max(0, $seconds);
        return match (true) {
            $seconds < 120 => $seconds . ' s',
            $seconds < 7200 => intdiv($seconds, 60) . ' min',
            default => intdiv($seconds, 3600) . ' h',
        };
    }
}
