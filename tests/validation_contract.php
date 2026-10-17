<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\AppError;
use App\Core\V;

$schema = V::obj([
    'ownerId' => V::union([V::uuid(), V::literal('unassigned'), V::literal('me')])->optional(),
]);
foreach (['me', 'unassigned', '01a14699-80ab-7000-a493-b338e2d89a0b'] as $ownerId) {
    $parsed = $schema->parseOrFail(['ownerId' => $ownerId], 'query');
    if (($parsed['ownerId'] ?? null) !== $ownerId) throw new RuntimeException('Union schema changed a valid owner filter');
}
if ($schema->parseOrFail([], 'query') !== []) throw new RuntimeException('An omitted optional union was not omitted');

try {
    $schema->parseOrFail(['ownerId' => 'not-an-owner'], 'query');
    throw new RuntimeException('Union schema accepted an invalid owner filter');
} catch (AppError $e) {
    if ($e->status !== 400) throw $e;
}

echo "Union validation and optional-field contracts passed\n";
