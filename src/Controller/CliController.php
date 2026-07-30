<?php

declare(strict_types=1);

namespace App\Controller;

use App\CreateDraftHandler;
use App\CreateDraftRequest;
use InvalidArgumentException;

final class CliController
{
    public function __construct(private readonly CreateDraftHandler $handler)
    {
    }

    public function run(): int
    {
        $options = getopt('', ['site:', 'username:', 'password:', 'help']);
        if ($options === false) {
            return $this->fail('Unable to read command-line options.');
        }

        if (isset($options['help'])) {
            fwrite(STDOUT, <<<'HELP'
Create a WordPress draft post through the REST API.

Usage:
  php index.php --site="https://example.com" --username="admin"

Enter the WordPress Application Password when prompted.
The parameter remains named password and is sent unchanged using HTTP Basic Authentication.

Password priority: --password, WP_PASSWORD, then the hidden terminal prompt.
The prompt is recommended because --password may be visible in shell history and process lists.

HELP);
            return 0;
        }

        $site = is_string($options['site'] ?? null) ? $options['site'] : '';
        $username = is_string($options['username'] ?? null) ? $options['username'] : '';

        try {
            CreateDraftRequest::validateSiteUrl($site);
            CreateDraftRequest::validateUsername($username);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage());
        }

        $password = $this->password($options);
        if ($password === null) {
            return $this->fail(
                'WordPress Application Password is required. Use an interactive terminal, --password, or WP_PASSWORD.',
            );
        }

        $result = $this->handler->handle([
            'site' => $site,
            'username' => $username,
            'password' => $password,
        ]);

        if ($result->success) {
            fwrite(STDOUT, sprintf("Draft created successfully. Post ID: %d\n", $result->postId));
            return 0;
        }

        $details = $result->httpStatus !== null ? sprintf(' HTTP %d.', $result->httpStatus) : '';
        $code = $result->wordpressCode !== null ? sprintf(' WordPress code: %s.', $result->wordpressCode) : '';

        return $this->fail($result->message . $details . $code);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function password(array $options): ?string
    {
        if (array_key_exists('password', $options)) {
            return is_string($options['password']) ? $options['password'] : '';
        }

        $environmentPassword = getenv('WP_PASSWORD');
        if (is_string($environmentPassword)) {
            return $environmentPassword;
        }

        return $this->readHiddenPassword();
    }

    private function readHiddenPassword(): ?string
    {
        if (!defined('STDIN') || !function_exists('stream_isatty') || !stream_isatty(STDIN)) {
            return null;
        }

        fwrite(STDERR, 'Application Password: ');
        $echoDisabled = false;

        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                return null;
            }

            exec('stty -echo 2>/dev/null', $output, $status);
            $echoDisabled = $status === 0;
            if (!$echoDisabled) {
                return null;
            }
            $password = fgets(STDIN);
        } finally {
            if ($echoDisabled) {
                exec('stty echo 2>/dev/null');
            }
            fwrite(STDERR, PHP_EOL);
        }

        return is_string($password) ? rtrim($password, "\r\n") : null;
    }

    private function fail(string $message): int
    {
        fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
        return 1;
    }
}
