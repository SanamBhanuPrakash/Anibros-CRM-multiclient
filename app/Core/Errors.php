<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Error taxonomy (same stable codes and statuses as the Node API; RFC 9457 problem documents on the wire).
 */
final class AppError extends \RuntimeException
{
    /**
     * @param array<int,array{path:string,message:string,code?:string}>|null $errors
     * @param array<string,string> $headers
     * @param array<string,mixed> $extensions
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly ?array $errors = null,
        public readonly array $headers = [],
        public readonly array $extensions = [],
    ) {
        parent::__construct($detail ?? $title);
    }
}

final class Errors
{
    public static function badRequest(string $detail, array $ext = []): AppError
    {
        return new AppError(400, 'BAD_REQUEST', 'Bad request', $detail, null, [], $ext);
    }

    /** @param array<int,array{path:string,message:string,code?:string}> $errors */
    public static function validation(array $errors, string $detail = 'One or more fields are invalid'): AppError
    {
        return new AppError(400, 'VALIDATION_FAILED', 'Validation failed', $detail, $errors);
    }

    public static function unauthenticated(string $detail = 'Authentication is required'): AppError
    {
        return new AppError(401, 'UNAUTHENTICATED', 'Authentication required', $detail);
    }

    public static function emailNotVerified(): AppError
    {
        return new AppError(403, 'EMAIL_NOT_VERIFIED', 'Email verification required', 'Verify your email address before accessing the workspace');
    }

    public static function forbidden(string $detail = 'You do not have permission to perform this action'): AppError
    {
        return new AppError(403, 'FORBIDDEN', 'Forbidden', $detail);
    }

    /** Deliberately identical for "missing" and "belongs to another tenant" so existence never leaks. */
    public static function notFound(string $what = 'Resource'): AppError
    {
        return new AppError(404, 'NOT_FOUND', 'Not found', "$what not found");
    }

    public static function conflict(string $detail, array $ext = [], array $headers = []): AppError
    {
        return new AppError(409, 'CONFLICT', 'Conflict', $detail, null, $headers, $ext);
    }

    public static function preconditionFailed(string $detail = 'The record was changed by someone else; reload and try again'): AppError
    {
        return new AppError(412, 'PRECONDITION_FAILED', 'Precondition failed', $detail);
    }

    public static function unprocessable(string $detail, array $ext = []): AppError
    {
        return new AppError(422, 'UNPROCESSABLE', 'Unprocessable', $detail, null, [], $ext);
    }

    public static function invalidTransition(string $detail): AppError
    {
        return new AppError(409, 'INVALID_TRANSITION', 'Invalid transition', $detail);
    }

    public static function slotUnavailable(string $detail): AppError
    {
        return new AppError(409, 'SLOT_UNAVAILABLE', 'Slot unavailable', $detail);
    }

    public static function rateLimited(float|int $retryAfterSeconds, string $detail = 'Too many requests'): AppError
    {
        return new AppError(429, 'RATE_LIMITED', 'Too many requests', $detail, null, ['Retry-After' => (string) max(1, (int) ceil($retryAfterSeconds))]);
    }

    public static function payloadTooLarge(string $detail = 'Request body too large'): AppError
    {
        return new AppError(413, 'PAYLOAD_TOO_LARGE', 'Payload too large', $detail);
    }

    public static function idempotencyConflict(string $detail = 'Idempotency-Key was reused with a different request'): AppError
    {
        return new AppError(422, 'IDEMPOTENCY_CONFLICT', 'Idempotency key reuse', $detail);
    }

    public static function busy(string $detail = 'The service is temporarily overloaded; retry shortly'): AppError
    {
        return new AppError(503, 'SERVICE_BUSY', 'Service busy', $detail, null, ['Retry-After' => '2']);
    }

    public static function dependencyUnavailable(string $detail): AppError
    {
        return new AppError(503, 'DEPENDENCY_UNAVAILABLE', 'Dependency unavailable', $detail, null, ['Retry-After' => '5']);
    }

    public static function internal(): AppError
    {
        return new AppError(500, 'INTERNAL', 'Internal server error', 'An unexpected error occurred');
    }

    /**
     * Convert any thrown value into an AppError, or null for a genuine defect (the caller logs it with its stack and answers 500).
     * Maps MySQL errors the way the Node API mapped PostgreSQL errors.
     */
    public static function fromThrowable(\Throwable $e): ?AppError
    {
        if ($e instanceof AppError) return $e;
        if ($e instanceof \PDOException) {
            $errno = (int) ($e->errorInfo[1] ?? 0);
            $msg = (string) ($e->errorInfo[2] ?? $e->getMessage());
            $constraint = null;
            if (preg_match("/for key '([^']+)'/", $msg, $m)) $constraint = preg_replace('/^[a-z_0-9]+\./i', '', $m[1]);
            elseif (preg_match('/CONSTRAINT `([^`]+)`/', $msg, $m)) $constraint = $m[1];
            $ext = $constraint ? ['constraint' => $constraint] : [];
            switch ($errno) {
                case 1062: // duplicate entry
                    return Errors::conflict('A record with the same unique value already exists', $ext);
                case 1451: // cannot delete or update a parent row
                case 1452: // cannot add or update a child row (a foreign key to another tenant's row ends here too)
                    return Errors::conflict('The operation conflicts with related records', $ext);
                case 1048: // column cannot be null
                case 1364: // field has no default value
                    return Errors::unprocessable('A required value is missing');
                case 4025: // CHECK constraint failed (MariaDB)
                case 3819: // CHECK constraint violated (MySQL)
                    return Errors::unprocessable('A value violates a data rule', $ext);
                case 1292: // incorrect datetime value
                case 1366: // incorrect integer value
                case 1265: // data truncated
                case 1406: // data too long
                case 1264: // out of range
                    return Errors::badRequest('A value has an invalid format');
                case 1213: // deadlock
                    return Errors::conflict('The request conflicted with a concurrent change; please retry', [], ['Retry-After' => '1']);
                case 1205: // lock wait timeout
                    return Errors::busy('The resource is locked by another operation; retry shortly');
                case 3024: // query execution was interrupted (max_statement_time)
                case 1969:
                    return Errors::dependencyUnavailable('The request took too long to complete');
                case 1040: // too many connections
                case 1203: // user has exceeded max_user_connections
                    return Errors::busy();
                case 1369: // WITH CHECK OPTION failed (a write that would leave the caller's workspace)
                case 1356:
                    return Errors::forbidden();
                case 1142:
                case 1143:
                case 1044:
                    return null; // grants problem: a real defect/configuration issue, never hide it
            }
            if (($e->errorInfo[0] ?? '') === '44000') return Errors::forbidden(); // view WITH CHECK OPTION
            return null;
        }
        return null;
    }
}
