<?php
declare(strict_types=1);

namespace App\Core;

final class GraphHttp
{
    private static ?GraphTransport $transport = null;

    public static function transport(): GraphTransport
    {
        return self::$transport ??= new CurlGraphTransport();
    }

    public static function setTransport(?GraphTransport $transport): void
    {
        self::$transport = $transport;
    }
}
