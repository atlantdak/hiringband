<?php

declare(strict_types=1);

use App\CreateDraftResult;

/** @var string $site */
/** @var string $username */
/** @var string $formAction */
/** @var CreateDraftResult|null $result */

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WordPress Draft Creator</title>
    <style>
        body { font: 16px/1.45 system-ui, sans-serif; max-width: 36rem; margin: 3rem auto; padding: 0 1rem; color: #202124; }
        form { display: grid; gap: .9rem; }
        label { display: grid; gap: .3rem; font-weight: 600; }
        input, button { box-sizing: border-box; width: 100%; padding: .65rem; font: inherit; }
        button { cursor: pointer; font-weight: 700; }
        .result { margin: 1rem 0; padding: .8rem; border-radius: .3rem; background: #e8f5e9; }
        .error { background: #ffebee; }
    </style>
</head>
<body>
    <h1>WordPress Draft Creator</h1>
    <?php if ($result?->success): ?>
        <p class="result">Draft created successfully. Post ID: <?= $escape((string) $result->postId) ?></p>
    <?php elseif ($result !== null): ?>
        <p class="result error">
            <?= $escape($result->message) ?>
            <?= $result->httpStatus !== null ? '(HTTP ' . $escape((string) $result->httpStatus) . ')' : '' ?>
        </p>
    <?php endif; ?>
    <form method="post" action="<?= $escape($formAction) ?>" autocomplete="off">
        <label>WordPress installation URL
            <input name="site" type="url" required value="<?= $escape($site) ?>" placeholder="https://example.com">
            <small>Use the site root, including a subdirectory when applicable. Do not enter a REST endpoint.</small>
        </label>
        <label>Username
            <input name="username" type="text" required value="<?= $escape($username) ?>">
        </label>
        <label>WordPress Application Password
            <input name="password" type="password" required autocomplete="off" autocapitalize="none" spellcheck="false">
        </label>
        <button type="submit">Create draft</button>
    </form>
</body>
</html>
