<?php

declare(strict_types=1);

namespace App;

final class CreateDraftResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?int $postId,
        public readonly ?string $errorType,
        public readonly string $message,
        public readonly ?int $httpStatus,
        public readonly ?string $wordpressCode,
    ) {
    }

    public static function success(int $postId): self
    {
        return new self(true, $postId, null, 'Draft created successfully.', null, null);
    }

    public static function failure(
        string $type,
        string $message,
        ?int $httpStatus = null,
        ?string $wordpressCode = null,
    ): self {
        return new self(false, null, $type, $message, $httpStatus, $wordpressCode);
    }
}
