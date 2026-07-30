<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

final class CreateDraftRequest
{
    public readonly string $siteUrl;
    public readonly string $username;
    public readonly string $password;

    public function __construct(string $siteUrl, string $username, string $password)
    {
        $siteUrl = trim($siteUrl);
        $username = trim($username);

        if ($siteUrl === '') {
            throw new InvalidArgumentException('Site URL is required.');
        }
        if ($username === '') {
            throw new InvalidArgumentException('Username is required.');
        }
        if ($password === '') {
            throw new InvalidArgumentException('Password is required.');
        }
        if (filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Site URL must be a valid URL.');
        }

        $parts = parse_url($siteUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Site URL must include a scheme and host.');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Site URL scheme must be http or https.');
        }
        if (isset($parts['fragment'])) {
            throw new InvalidArgumentException('Site URL must not contain a fragment.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Site URL must not contain user information.');
        }
        if (isset($parts['query'])) {
            throw new InvalidArgumentException('Site URL must not contain a query string.');
        }

        $this->siteUrl = rtrim($siteUrl, '/');
        $this->username = $username;
        $this->password = $password;
    }
}
