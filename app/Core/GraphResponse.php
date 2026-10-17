<?php
declare(strict_types=1);

namespace App\Core;

final class GraphResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $error = null
    ) {
    }
}
