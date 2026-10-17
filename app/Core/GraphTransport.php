<?php
declare(strict_types=1);

namespace App\Core;

interface GraphTransport
{
    /** @param array<int,string> $headers */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $maxResponseBytes
    ): GraphResponse;
}
