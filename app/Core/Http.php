<?php
declare(strict_types=1);

namespace App\Core;

/** One HTTP request, read from the PHP superglobals (or built by the test harness). */
final class Request
{
    public string $id;
    /** @var array<string,string> lower-case header name => value */
    public array $headers = [];
    /** @var array<string,string> */
    public array $cookies = [];
    /** @var array<string,mixed> */
    public array $query = [];
    public ?string $rawBody = null;
    public ?Actor $actor = null;
    public ?Session $session = null;
    /** Idempotency reservation of this request (see Idempotency). @var array{key:string,tenantId:string,userId:?string,requestId:?string,ip:?string}|null */
    public ?array $idem = null;
    private mixed $json = null;
    private bool $jsonParsed = false;

    public function __construct(
        public string $method,
        /** Path below the application base, always starting with "/". */
        public string $path,
        public string $basePath = '',
        array $headers = [],
        array $query = [],
        array $cookies = [],
        ?string $rawBody = null,
        public string $ip = '0.0.0.0',
        public string $scheme = 'http',
        public string $host = 'localhost',
    ) {
        foreach ($headers as $k => $v) $this->headers[strtolower($k)] = (string) $v;
        $this->query = $query;
        $this->cookies = $cookies;
        $this->rawBody = $rawBody;
        $supplied = $this->headers['x-request-id'] ?? '';
        $this->id = preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $supplied) ? $supplied : Ids::uuid();
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        $base = self::detectBase($path);
        if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
        if ($path === '' || $path[0] !== '/') $path = '/' . $path;
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $k => $h) if (isset($_SERVER[$k])) $headers[$h] = (string) $_SERVER[$k];
        if (!isset($headers['authorization']) && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) if (strtolower((string) $k) === 'authorization') $headers['authorization'] = (string) $v;
        }
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($headers['x-forwarded-proto'] ?? '') === 'https' && Config::get('trust_proxy'));
        $req = new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $base,
            $headers,
            $_GET,
            $_COOKIE,
            null,
            self::clientIp($headers),
            $https ? 'https' : 'http',
            (string) ($headers['host'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'),
        );
        return $req;
    }

    /** The folder the app lives in as the browser sees it: "" on a domain root (cPanel sub-domain), "/Anibros-CRM-multiclient/public" on XAMPP. */
    private static function detectBase(string $path): string
    {
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        if ($dir === '.' || $dir === '\\') $dir = '';
        $cands = array_unique([$dir, (string) preg_replace('#/public$#', '', $dir)]);
        usort($cands, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($cands as $c) {
            if ($c === '' || $path === $c || str_starts_with($path, $c . '/')) return $c;
        }
        return '';
    }

    private static function clientIp(array $headers): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $hops = (int) Config::get('trust_proxy', 0);
        if ($hops > 0) {
            // exactly N trusted reverse-proxy hops: the Nth address from the right cannot be spoofed by the client
            $chain = array_values(array_filter(array_map('trim', explode(',', (string) ($headers['x-forwarded-for'] ?? '')))));
            $chain[] = $remote;
            $i = count($chain) - 1 - $hops;
            if ($i >= 0 && filter_var($chain[$i], FILTER_VALIDATE_IP)) return $chain[$i];
        }
        return $remote;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function body(): string
    {
        return $this->rawBody ??= (string) file_get_contents('php://input');
    }

    /** Parsed JSON body (null when absent / not JSON). Throws 400 on malformed JSON. */
    public function json(): mixed
    {
        if ($this->jsonParsed) return $this->json;
        $this->jsonParsed = true;
        $raw = $this->body();
        if ($raw === '') return $this->json = null;
        try {
            $this->json = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw Errors::badRequest('Malformed JSON body');
        }
        return $this->json;
    }

    public function userAgent(): ?string
    {
        return $this->header('user-agent');
    }

    public function origin(): ?string
    {
        $o = $this->header('origin');
        if ($o !== null && $o !== '' && $o !== 'null') return $o;
        if ($o === 'null') return 'null';
        $ref = $this->header('referer');
        if ($ref) {
            $p = parse_url($ref);
            if (!$p || empty($p['scheme']) || empty($p['host'])) return 'invalid';
            return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        }
        return null;
    }

    /** scheme://host of this request as the browser addressed it. */
    public function selfOrigin(): string
    {
        return $this->scheme . '://' . $this->host;
    }

    /** Public URL of the application (config app_url, else derived from this request). Never ends with "/". */
    public function appUrl(): string
    {
        $c = Config::get('app_url');
        if (is_string($c) && $c !== '') return rtrim($c, '/');
        return $this->selfOrigin() . $this->basePath;
    }
}

/** A JSON reply: `{ data }` or `{ data, page }` with a status and optional headers. */
final class Reply
{
    /** @param array{limit:int,next:?string,hasMore:bool}|null $page @param array<string,string> $headers */
    public function __construct(public mixed $data, public ?int $status = null, public ?array $page = null, public array $headers = [])
    {
    }
}

/** A non-JSON reply (CSV export, files, XLSX, scripts). Bypasses the `{ data }` envelope. */
final class RawReply
{
    /** @param array<string,string> $headers */
    public function __construct(public string $body, public string $contentType, public array $headers = [], public int $status = 200)
    {
    }
}

/** Response under construction: status, headers, cookies, body. */
final class Response
{
    public int $status = 200;
    /** @var array<string,string> */
    public array $headers = [];
    /** @var array<int,array{0:string,1:string,2:array}> */
    public array $cookies = [];
    public string $body = '';

    public function header(string $k, string $v): void
    {
        $this->headers[$k] = $v;
    }

    /** @param array{expires?:int,path?:string,secure?:bool,httponly?:bool,samesite?:string} $opts */
    public function cookie(string $name, string $value, array $opts = []): void
    {
        $this->cookies[] = [$name, $value, $opts + ['path' => '/', 'httponly' => false, 'secure' => false, 'samesite' => 'Lax']];
    }

    public function clearCookie(string $name, array $opts = []): void
    {
        $this->cookies[] = [$name, '', ['expires' => 1] + $opts + ['path' => '/', 'httponly' => false, 'secure' => false, 'samesite' => 'Lax']];
    }

    public function json(int $status, mixed $payload, string $type = 'application/json'): void
    {
        $this->status = $status;
        $this->headers['Content-Type'] = $type . '; charset=utf-8';
        $this->body = Json::enc($payload);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) header($k . ': ' . $v);
        foreach ($this->cookies as [$n, $v, $o]) setcookie($n, $v, $o);
        if ($this->status !== 204 && $this->status !== 304) {
            header('Content-Length: ' . strlen($this->body));
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo $this->body;
        }
    }
}

/** What a route handler receives. */
final class Ctx
{
    public function __construct(
        public array $params,
        public array $query,
        public mixed $body,
        public ?Actor $actor,
        public Request $req,
        public Response $res,
        public ?Session $session = null,
    ) {
    }
}
