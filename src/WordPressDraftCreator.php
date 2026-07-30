<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;

final class WordPressDraftCreator
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RestEndpointResolver $endpointResolver,
    ) {
    }

    public function create(CreateDraftRequest $request): CreateDraftResult
    {
        $endpoint = $this->endpointResolver->resolve($request);
        if ($endpoint instanceof CreateDraftResult) {
            return $endpoint;
        }

        try {
            $response = $this->client->request('POST', $endpoint, [
                'auth' => [$request->username, $request->password, 'basic'],
                'json' => [
                    'title' => Config::DRAFT_TITLE,
                    'content' => Config::DRAFT_CONTENT,
                    'status' => Config::DRAFT_STATUS,
                ],
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
            return $this->transportFailure($exception);
        }

        $status = $response->getStatusCode();
        $data = null;
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $data = is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
        }

        if ($status >= 200 && $status < 300) {
            $id = $data['id'] ?? null;
            if (is_int($id) && $id > 0) {
                return CreateDraftResult::success($id);
            }

            return CreateDraftResult::failure(
                'upstream',
                'WordPress returned a successful response without a valid post ID.',
                $status,
            );
        }

        $wordpressCode = isset($data['code']) && is_string($data['code']) ? $data['code'] : null;
        $wordpressMessage = isset($data['message']) && is_string($data['message'])
            ? trim($data['message'])
            : '';

        [$type, $message] = match (true) {
            $status === 401 => [
                'authentication',
                'Authentication failed. Check the username, password, and WordPress authentication configuration.',
            ],
            $status === 403 => ['permission', 'The user is not allowed to create posts.'],
            $status === 404 => ['upstream', 'The WordPress posts endpoint was not found.'],
            $status >= 500 => ['upstream', 'The WordPress server returned an internal error.'],
            default => ['upstream', 'WordPress rejected the request.'],
        };

        if ($wordpressMessage !== '') {
            $message .= ' WordPress: ' . $wordpressMessage;
        }

        return CreateDraftResult::failure($type, $message, $status, $wordpressCode);
    }

    private function transportFailure(GuzzleException $exception): CreateDraftResult
    {
        $errno = null;
        if ($exception instanceof RequestException) {
            $contextErrno = $exception->getHandlerContext()['errno'] ?? null;
            $errno = is_int($contextErrno) ? $contextErrno : null;
        }

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return CreateDraftResult::failure(
                'timeout',
                'The request timed out. The server may have processed it, so the request was not retried.',
            );
        }

        if ($errno !== null && in_array($errno, Config::TLS_CURL_ERRNOS, true)) {
            return CreateDraftResult::failure(
                'network',
                'Unable to establish a secure connection to the WordPress site.',
            );
        }

        return CreateDraftResult::failure('network', 'Unable to connect to the WordPress site.');
    }
}
