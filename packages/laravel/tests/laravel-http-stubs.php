<?php

/**
 * Just enough of Illuminate's HTTP surface to drive FluxFilesController /
 * FluxFilesAuth in-process from test-laravel-smoke.php, which runs on core's
 * autoload alone (no illuminate/* installed — CI's smoke + adapter-core-floor
 * jobs never `composer install` this package). Each stub is declared only when
 * the real class is absent. Only the methods the adapter actually calls exist.
 */

declare(strict_types=1);

namespace Illuminate\Contracts\Auth {
    if (!interface_exists(Authenticatable::class)) {
        interface Authenticatable
        {
            /** @return mixed */
            public function getAuthIdentifier();
        }
    }
}

namespace Symfony\Component\HttpFoundation {
    if (!class_exists(Response::class)) {
        class Response
        {
            /** @var array<string,string> */
            public array $headers = [];
            public int $status = 200;
            /** @var mixed */
            public $content;

            /** @param mixed $content */
            public function __construct($content = '', int $status = 200)
            {
                $this->content = $content;
                $this->status = $status;
            }

            public function header(string $name, string $value): static
            {
                $this->headers[strtolower($name)] = $value;
                return $this;
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }
        }
    }
}

namespace Illuminate\Http {
    if (!class_exists(JsonResponse::class)) {
        class JsonResponse extends \Symfony\Component\HttpFoundation\Response
        {
            /** @return mixed */
            public function getData(bool $assoc = true)
            {
                return json_decode((string) json_encode($this->content), $assoc);
            }
        }
    }

    if (!class_exists(Response::class)) {
        class Response extends \Symfony\Component\HttpFoundation\Response
        {
        }
    }

    if (!class_exists(Request::class)) {
        class StubHeaderBag
        {
            /** @var array<string,string> */
            private array $h = [];

            /** @param array<string,string> $headers */
            public function __construct(array $headers)
            {
                foreach ($headers as $k => $v) {
                    $this->set($k, $v);
                }
            }

            public function get(string $key, ?string $default = null): ?string
            {
                return $this->h[strtolower($key)] ?? $default;
            }

            public function set(string $key, string $value): void
            {
                $this->h[strtolower($key)] = $value;
            }
        }

        class Request
        {
            public StubHeaderBag $headers;
            /** @var mixed */
            private $userObj;

            /**
             * @param array<string,mixed>  $input   body + query, merged (like Laravel's input())
             * @param array<string,string> $headers
             * @param array<string,mixed>  $files
             * @param mixed                $user
             */
            public function __construct(
                private string $method = 'GET',
                private array $input = [],
                array $headers = [],
                $user = null,
                private string $schemeAndHost = 'https://app.example.com',
                private array $files = []
            ) {
                $this->headers = new StubHeaderBag($headers);
                $this->userObj = $user;
            }

            /** @return mixed */
            public function user()
            {
                return $this->userObj;
            }

            public function bearerToken(): ?string
            {
                $h = (string) $this->headers->get('Authorization', '');
                return str_starts_with($h, 'Bearer ') ? substr($h, 7) : null;
            }

            /** @param mixed $default @return mixed */
            public function input(?string $key = null, $default = null)
            {
                return $key === null ? $this->input : ($this->input[$key] ?? $default);
            }

            /** @param mixed $default @return mixed */
            public function query(?string $key = null, $default = null)
            {
                return $this->input($key, $default);
            }

            /** @return array<string,mixed> */
            public function all(): array
            {
                return $this->input;
            }

            public function filled(string $key): bool
            {
                return isset($this->input[$key]) && $this->input[$key] !== '';
            }

            /** @param mixed $default @return mixed */
            public function header(string $key, $default = null)
            {
                return $this->headers->get($key, $default);
            }

            /** @param mixed $default @return mixed */
            public function server(string $key, $default = null)
            {
                return $default;
            }

            /** @return mixed */
            public function file(string $key)
            {
                return $this->files[$key] ?? null;
            }

            public function getMethod(): string
            {
                return strtoupper($this->method);
            }

            public function method(): string
            {
                return $this->getMethod();
            }

            public function path(): string
            {
                return 'api/fm';
            }

            public function getSchemeAndHttpHost(): string
            {
                return $this->schemeAndHost;
            }
        }
    }
}

namespace {
    if (!function_exists('response')) {
        /** @param mixed $content */
        function response($content = null, int $status = 200)
        {
            if (func_num_args() > 0) {
                return new \Illuminate\Http\Response($content, $status);
            }
            return new class {
                /** @param mixed $data */
                public function json($data, int $status = 200): \Illuminate\Http\JsonResponse
                {
                    return new \Illuminate\Http\JsonResponse($data, $status);
                }
            };
        }
    }

    if (!function_exists('app')) {
        /** Container stub: the manager is a per-test singleton in $GLOBALS. */
        function app(?string $abstract = null)
        {
            if ($abstract === \FluxFiles\Laravel\FluxFilesManager::class) {
                return $GLOBALS['LARAVEL_MANAGER'] ??= new \FluxFiles\Laravel\FluxFilesManager();
            }
            return new $abstract();
        }
    }

    if (!function_exists('auth')) {
        function auth()
        {
            return new class {
                /** @return mixed */
                public function user()
                {
                    return $GLOBALS['LARAVEL_AUTH_USER'] ?? null;
                }
            };
        }
    }

    /** A minimal logged-in user. */
    final class StubUser implements \Illuminate\Contracts\Auth\Authenticatable
    {
        public function __construct(public int|string $id)
        {
        }

        /** @return mixed */
        public function getAuthIdentifier()
        {
            return $this->id;
        }
    }
}
