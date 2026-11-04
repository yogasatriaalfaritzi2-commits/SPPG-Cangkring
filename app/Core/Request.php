<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     */
    public function __construct(
        private string $requestMethod,
        private string $requestPath,
        private array $query = [],
        private array $post = [],
        private array $server = []
    ) {
    }

    public static function capture(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        $path = parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        $base = App::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim($path, '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if (($path === '/' || $path === '/index.php') && isset($_GET['url']) && is_string($_GET['url'])) {
            $path = '/' . trim($_GET['url'], '/');
        }

        return new self($method, $path, $_GET, $_POST, $server);
    }

    public function method(): string
    {
        return strtoupper($this->requestMethod);
    }

    public function path(): string
    {
        return $this->requestPath;
    }

    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        return $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_string($value) ? trim($value) : $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function boolean(string $key): bool
    {
        $value = $this->input($key);

        return $value === '1' || $value === 'true' || $value === 'on' || $value === 1 || $value === true;
    }

    /**
     * Mengambil satu berkas unggahan yang sudah ternormalisasi.
     * Mengembalikan null bila tidak ada berkas yang dikirim pada key tersebut.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $key): ?array
    {
        $entry = $_FILES[$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        $error = (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmpName = $entry['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '' || $error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return [
            'name' => (string) ($entry['name'] ?? ''),
            'type' => (string) ($entry['type'] ?? ''),
            'tmp_name' => $tmpName,
            'error' => $error,
            'size' => (int) ($entry['size'] ?? 0),
        ];
    }

    public function userAgent(): string
    {
        $agent = (string) ($this->server['HTTP_USER_AGENT'] ?? '');

        return trim($agent) === '' ? 'tidak diketahui' : $agent;
    }

    public function ip(): string
    {
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
