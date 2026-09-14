<?php

declare(strict_types=1);

namespace Recranet\DirectAdminBorg\Http;

use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * A DirectAdmin plugin request, expressed as an HttpFoundation Request.
 *
 * DirectAdmin does not run plugin scripts under a webserver: it executes them
 * as plain processes and passes the request in the environment — QUERY_STRING
 * for GET, POST for the body — with the values HTML-entity encoded. Bodies
 * larger than the environment value limit (~125 KB) arrive on stdin instead,
 * flagged by POST="stdin=true".
 *
 * Rebuilding a real Request means the rest of the code gets InputBag's typed,
 * total accessors instead of poking at raw strings.
 */
final class PluginRequest
{
    public const LEVEL_ADMIN = 'admin';
    public const LEVEL_RESELLER = 'reseller';
    public const LEVEL_USER = 'user';

    private function __construct(
        public readonly string $level,
        public readonly string $username,
        public readonly Request $request,
    ) {
    }

    public static function fromEnvironment(string $level): self
    {
        $query = self::parse(self::env('QUERY_STRING'));
        $body = self::parse(self::readBody());

        $server = [
            'REQUEST_METHOD' => strtoupper(self::env('REQUEST_METHOD') ?: 'GET'),
            'REMOTE_ADDR'    => self::env('REMOTE_ADDR') ?: '127.0.0.1',
            'REQUEST_URI'    => self::env('REQUEST_URI') ?: '/',
        ];

        return new self(
            $level,
            self::env('USERNAME'),
            new Request($query, $body, [], [], [], $server)
        );
    }

    /**
     * Build a request directly, for tests.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    public static function fromArrays(string $level, string $username, array $query, array $body, string $method = 'GET'): self
    {
        return new self($level, $username, new Request($query, $body, [], [], [], ['REQUEST_METHOD' => $method]));
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return \is_string($value) ? $value : '';
    }

    private static function readBody(): string
    {
        $raw = self::env('POST');

        // DirectAdmin sets this instead of exporting the body once it exceeds
        // the environment value size limit.
        if (trim($raw) === 'stdin=true') {
            $stdin = @file_get_contents('php://stdin');

            return $stdin === false ? '' : $stdin;
        }

        return $raw;
    }

    /**
     * DirectAdmin HTML-entity encodes exported values, so &amp; has to become &
     * again before the string can be parsed as a query string.
     *
     * @return array<int|string,mixed>
     */
    private static function parse(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parsed = [];
        parse_str(html_entity_decode($raw, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), $parsed);

        return $parsed;
    }

    public function query(): InputBag
    {
        return $this->request->query;
    }

    public function body(): InputBag
    {
        return $this->request->request;
    }

    public function isAdmin(): bool
    {
        return $this->level === self::LEVEL_ADMIN;
    }

    public function isPost(): bool
    {
        return $this->request->isMethod('POST') || $this->body()->count() > 0;
    }

    public function action(): string
    {
        return $this->body()->getString('action') ?: $this->query()->getString('action');
    }

    /** A value from the query string, falling back to the body. */
    public function param(string $key, string $default = ''): string
    {
        $value = $this->query()->getString($key);

        return $value !== '' ? $value : ($this->body()->getString($key) ?: $default);
    }

    /** @return string[] */
    public function bodyList(string $key): array
    {
        $value = $this->body()->all()[$key] ?? [];

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $v) => $v !== ''));
    }

    public function bodyBool(string $key): bool
    {
        return $this->body()->getBoolean($key);
    }

    /** Base URL of this plugin for the current access level. */
    public function baseUrl(): string
    {
        return match ($this->level) {
            self::LEVEL_ADMIN    => '/CMD_PLUGINS_ADMIN/borg',
            self::LEVEL_RESELLER => '/CMD_PLUGINS_RESELLER/borg',
            default              => '/CMD_PLUGINS/borg',
        };
    }

    /** @param array<string,scalar> $params */
    public function url(array $params = []): string
    {
        $url = $this->baseUrl() . '/index.html';

        return $params === [] ? $url : $url . '?' . http_build_query($params);
    }
}
