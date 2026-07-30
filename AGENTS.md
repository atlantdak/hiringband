# Agent workflow

This is a small PHP 8.1+ project. Keep changes modular and readable; prefer DRY, KISS, and SOLID
without adding frameworks, containers, or unnecessary abstractions.

```bash
composer install
composer check
```

For manual HTTP checks, start a separate local server:

```bash
composer serve
```

This is a long-running manual command. Stop it with `Ctrl+C`; do not wait for it to finish.

Then run:

```bash
sh tests/http-smoke.sh
```

Treat `password` as a WordPress Application Password for a standard installation. Never log,
persist, echo, or place credentials in query parameters. Do not repopulate the password field.
Preserve the parameter name and literal HTTP Basic Authentication. Do not retry the final POST
after a timeout.
