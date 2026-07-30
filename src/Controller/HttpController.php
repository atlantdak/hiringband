<?php

declare(strict_types=1);

namespace App\Controller;

use App\CreateDraftHandler;
use App\CreateDraftResult;
use JsonException;

final class HttpController
{
    public function __construct(
        private readonly CreateDraftHandler $handler,
        private readonly string $viewPath,
    ) {
    }

    public function run(): void
    {
        header('Cache-Control: no-store');

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $this->renderForm();
            return;
        }

        if ($method !== 'POST') {
            header('Allow: GET, POST');
            $this->jsonResponse(405, $this->errorPayload('method', 'Unsupported HTTP method.'));
            return;
        }

        if ($this->isJsonRequest()) {
            $this->handleJson();
            return;
        }

        $input = [
            'site' => $_POST['site'] ?? '',
            'username' => $_POST['username'] ?? '',
            'password' => $_POST['password'] ?? '',
        ];
        $this->renderForm($input, $this->handler->handle($input));
    }

    private function handleJson(): void
    {
        try {
            $decoded = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new JsonException();
            }
        } catch (JsonException) {
            $this->jsonResponse(400, $this->errorPayload('validation', 'Invalid JSON request body.'));
            return;
        }

        $result = $this->handler->handle($decoded);
        if ($result->success) {
            $this->jsonResponse(201, ['success' => true, 'post_id' => $result->postId]);
            return;
        }

        $this->jsonResponse($this->localHttpStatus($result), [
            'success' => false,
            'error' => [
                'type' => $result->errorType,
                'message' => $result->message,
                'http_status' => $result->httpStatus,
                'wordpress_code' => $result->wordpressCode,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function renderForm(array $input = [], ?CreateDraftResult $result = null): void
    {
        $site = is_string($input['site'] ?? null) ? $input['site'] : '';
        $username = is_string($input['username'] ?? null) ? $input['username'] : '';
        $scriptName = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
        $decodedScriptName = rawurldecode($scriptName);
        $formAction = preg_match('/\A\/(?!\/)[^\\\\?#\x00-\x1F]*\z/', $decodedScriptName) === 1
            ? $scriptName
            : '/index.php';
        require $this->viewPath;
    }

    private function isJsonRequest(): bool
    {
        return strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0])) === 'application/json';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorPayload(string $type, string $message): array
    {
        return [
            'success' => false,
            'error' => [
                'type' => $type,
                'message' => $message,
                'http_status' => null,
                'wordpress_code' => null,
            ],
        ];
    }

    private function localHttpStatus(CreateDraftResult $result): int
    {
        return match ($result->errorType) {
            'validation' => 400,
            'authentication' => 401,
            'permission' => 403,
            'timeout' => 504,
            'network', 'discovery', 'upstream' => 502,
            default => 500,
        };
    }
}
