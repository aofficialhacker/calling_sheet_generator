# Repository Guidelines

These guidelines help contributors work effectively on `calling_sheet_generator10`, a PHP web app intended to run under XAMPP (`htdocs`). Keep changes focused and consistent with the existing codebase.

## Project Structure & Module Organization

- `public/` or project root: Web entry points (e.g., `index.php`), assets.
- `app/` or `src/`: Core PHP logic (generators, helpers, controllers).
- `templates/` or `views/`: Rendered HTML/PHP templates.
- `assets/` or `public/assets/`: CSS, JS, images.
- `tests/`: Automated tests (PHPUnit) if present.
- `config/`: Environment and app settings.

Adjust paths to match existing folders (check the repository root under XAMPP `htdocs`).

## Build, Test, and Development Commands

- Run locally: Place the repo under `C:\xampp\htdocs\calling_sheet_generator10` and start Apache in XAMPP. Visit `http://localhost/calling_sheet_generator10/`.
- PHP built-in server (alternative): `php -S localhost:8000 -t public` (or repo root if no `public/`).
- Install dependencies (Composer if used): `composer install`.
- Run tests (PHPUnit): `vendor\bin\phpunit` or `php vendor/bin/phpunit`.

## Coding Style & Naming Conventions

- PHP 7.4+ syntax; 4-space indentation; strict types when feasible (`declare(strict_types=1);`).
- Naming: PascalCase for classes, camelCase for methods/variables, UPPER_SNAKE_CASE for constants.
- Files: one class per file; names match class (e.g., `CallingSheetGenerator.php`).
- Lint/format: `composer cs-fix` (if configured) or PSR-12 via PHP CS Fixer. Keep HTML/JS/CSS minimal and scoped to page.

## Testing Guidelines

- Framework: PHPUnit. Place tests under `tests/`, mirroring source structure (e.g., `tests/Generator/CallingSheetGeneratorTest.php`).
- Naming: suffix test classes with `Test` and methods with `test...`.
- Coverage: add tests for new logic and bug fixes; avoid changing unrelated tests.
- Run: `vendor\bin\phpunit --testdox` for readable output.

## Commit & Pull Request Guidelines

- Commits: small, atomic, imperative mood (e.g., "Add CSV export to generator"). Reference issues (`#123`) when relevant.
- Messages: subject ≤ 72 chars; body explains motivation and approach.
- PRs: include purpose, screenshots of UI changes, reproduction steps, and configuration notes (PHP version, XAMPP version). Link issues and describe testing performed.

## Security & Configuration Tips

- Never commit secrets or `.env` files. Use sample config (e.g., `.env.example`).
- Validate and sanitize all user inputs; escape output in templates.
- Pin PHP/composer dependency versions and review diffs on updates.

