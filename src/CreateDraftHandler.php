<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use Throwable;

final class CreateDraftHandler
{
    public function __construct(private readonly WordPressDraftCreator $creator)
    {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function handle(array $input): CreateDraftResult
    {
        try {
            return $this->creator->create(new CreateDraftRequest(
                is_string($input['site'] ?? null) ? $input['site'] : '',
                is_string($input['username'] ?? null) ? $input['username'] : '',
                is_string($input['password'] ?? null) ? $input['password'] : '',
            ));
        } catch (InvalidArgumentException $exception) {
            return CreateDraftResult::failure('validation', $exception->getMessage());
        } catch (Throwable) {
            return CreateDraftResult::failure('internal', 'An unexpected internal error occurred.');
        }
    }
}
