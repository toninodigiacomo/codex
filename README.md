# Codex

A personal ebook/comic library server (Ubooquity-style) — same hosting
approach as [My Lost Treasure]: `php:8.2-apache` via `compose.yml`, no
custom Docker image.

**Status: early scaffold.** Only the sign-in screen exists so far, as a
static page, to validate the visual direction before building the rest.

## Design

Structure and component grammar adapted from a "Industry" design-system
mockup (blueprint cards: square corners, hairline borders, "+" corner
registration marks, modular grid) — recolored to match My Lost Treasure's
dark navy / amber palette and Space Mono / Literata typography instead of
the source system's light steel-blue theme. All tokens live in
`public/css/style.css` (`--color-*`, `--font-*`, `--space-*`, `--radius-*`,
`--shadow-*`) — component classes (`.btn`, `.card`, `.field`/`.input`,
`.nav`, `.tag`, `.table`, `.dialog`, `.blueprint` + `.corner`) read from
those variables, nothing is hardcoded.

The mockup this was adapted from also specs four more screens not built
yet: a library-browsing view (sidebar with shelves/libraries/tags, search,
grid/list toggle, "continue reading" row), a reader, a server admin panel,
and a mobile layout.

## Local hosting

```bash
docker compose up -d
```

Same pattern as My Lost Treasure: `public/` read-only except
`public/assets/` (read-write, for covers/uploads later), `data/`
read-write, PHP upload limits raised for large scans (`docker/uploads.ini`).

## License

Personal project.
