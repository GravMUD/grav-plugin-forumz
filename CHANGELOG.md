# Changelog — Forumz

## 1.0.1 — 2026-06-12 (Admin2 board CRUD)

### Added

- **Admin2 board management** — create, edit, delete boards at `/plugin/forumz` → Boards tab
- Persists admin boards to **`user/config/plugins/forumz.yaml`**
- Optional delete: thread data wipe + `.mforum` file removal

## 1.0.0 — 2026-06-12 (Lite MIT · GPM)

### Added

- **Forumz Lite (MIT)** — free GPM release; renamed from `mud-forumz` / MUD Forumz
- **Standard Grav support** — boards API, profiles, bundled CSS/JS; no GravMUD required
- **`.mforum` board scanner** — `user/pages/**/*.mforum` with YAML config overrides
- **Mambers identity bridge** (optional) — registered boards + Grav Login session
- **Admin2 panel** (optional) — `/plugin/forumz` · moderation queue · profiles · boards
- **GravMUD fences** (optional) — `:::forum` embeds when **grav-mud-alpha** is installed
- **Bundled assets** — `assets/forumz.css` + `assets/forumz.js`

### Migration from mud-forumz

- Plugin slug: `mud-forumz` → **`forumz`**
- Data dir: `user/data/mud-forumz/` → **`user/data/forumz/`** (copy existing data)
- Config: `user/config/plugins/forumz.yaml`
- API default: **`/api/forumz`** (Grav 2 bridge: `/api/v1/forumz`)
- CSS/JS hooks: `.forumz-*` · `data-forumz`

### Optional integrations

- **grav-mud-alpha** — `.mud` fence compile (`:::forum`)
- **grav-mud-admin** — EvvyTink moderation at `/mud-admin` → Forumz
- **mambers** — Social Stack identity bridge

### Planned (Pro / later)

- Attachments upload
- Dedicated `/forum` shell route + embed.js on any `.md` page
