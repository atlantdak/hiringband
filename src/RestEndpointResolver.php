<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;

final class RestEndpointResolver
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function resolve(CreateDraftRequest $request): string|CreateDraftResult
    {
        $candidates = [
            [$request->siteUrl . '/wp-json/', $request->siteUrl . '/wp-json' . Config::POSTS_ROUTE],
            [$request->siteUrl . '/?rest_route=/', $request->siteUrl . '/?rest_route=' . Config::POSTS_ROUTE],
        ];
        $transportFailures = 0;
        $sawTimeout = false;
        $sawTlsError = false;

        foreach ($candidates as [$root, $postsEndpoint]) {
            try {
                $response = $this->client->request('GET', $root, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => Config::USER_AGENT,
                    ],
                    'connect_timeout' => Config::CONNECT_TIMEOUT,
                    'timeout' => Config::REQUEST_TIMEOUT,
                    'http_errors' => false,
                    'allow_redirects' => false,
                ]);
            } catch (GuzzleException $exception) {
                ++$transportFailures;
                $errno = $this->curlErrno($exception);
                $sawTimeout = $sawTimeout || $errno === CURLE_OPERATION_TIMEDOUT;
                $sawTlsError = $sawTlsError || $this->isTlsError($errno);
                continue;
            }

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                continue;
            }

            try {
                $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (
                is_array($data)
                && isset($data['routes'])
                && is_array($data['routes'])
                && array_key_exists(Config::POSTS_ROUTE, $data['routes'])
            ) {
                return $postsEndpoint;
            }
        }

        if ($transportFailures === count($candidates)) {
            if ($sawTimeout) {
                return CreateDraftResult::failure('timeout', 'Request timed out.');
            }
            if ($sawTlsError) {
                return CreateDraftResult::failure(
                    'network',
                    'Unable to establish a secure connection to the WordPress site.',
                );
            }

            return CreateDraftResult::failure('network', 'Unable to connect to the WordPress site.');
        }

        return CreateDraftResult::failure(
            'discovery',
            'WordPress REST API or the posts endpoint could not be found. Check the site URL and permalink configuration.',
        );
    }

    private function curlErrno(GuzzleException $exception): ?int
    {
        if (!$exception instanceof RequestException) {
            return null;
        }

        $errno = $exception->getHandlerContext()['errno'] ?? null;
        return is_int($errno) ? $errno : null;
    }

    private function isTlsError(?int $errno): bool
    {
        return $errno !== null && in_array($errno, Config::TLS_CURL_ERRNOS, true);
    }
}
