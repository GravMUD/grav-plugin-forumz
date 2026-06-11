# Forumz

**Lite (MIT)** — flat-file forum boards, **user profiles**, and moderation for **standard Grav** and GravMUD sites.

**Version 1.0.1** — slug `forumz`. Works without GravMUD. Optional `.mud` fences when **grav-mud-alpha** is installed.

Companion to **Mambers** (identity) and **Commentz** (blog comments) — Forumz = boards + threads + profiles.

## Requires

- Grav 1.7.x / 2.0

## Optional

- **login** — Grav Login for registered boards
- **admin2** + **api** — Admin2 Forumz panel at `/plugin/forumz`
- **mambers** — identity bridge across Social Stack
- **grav-mud-alpha** — `:::forum` fences in `.mud` pages
- **grav-mud-admin** — EvvyTink moderation UI

## Quick start

```yaml
# user/config/plugins/forumz.yaml
enabled: true
auto_approve: true
api_route: api/forumz
```

Board via config or `.mforum` file under `user/pages/**/boards/`.

Embed on any page (HTML):

```html
<div class="forumz" data-forumz data-mode="board" data-board="general" data-api="/api/forumz"></div>
```

GravMUD `.mud` page (requires grav-mud-alpha):

```mud
:::forum{board="general" limit="20"}
:::
```

Demo: `/forum` · Admin2: `/plugin/forumz` (Boards + moderation) · EvvyTink: `/mud-admin` → Forumz

## Public API

| Route | Method | Purpose |
|-------|--------|---------|
| `/api/forumz/boards` | GET | List boards |
| `/api/forumz/threads?board=` | GET | List threads |
| `/api/forumz/thread` | GET, POST | Read / create thread |
| `/api/forumz/reply` | POST | Append reply |
| `/api/forumz/profile?user=` | GET | Public profile |
| `/api/forumz/session` | GET | Current session |
| `/api/forumz/register` | POST | Create profile + login |
| `/api/forumz/login` | POST | Login |
| `/api/forumz/logout` | POST | Logout |

Grav 2 API bridge: `/api/v1/forumz/*` when Grav API plugin is enabled.

## Build & GPM ship

```powershell
.\scripts\build-forumz-gpm.ps1
.\scripts\publish-forumz-github.ps1
```

See `GPM-SUBMISSION.md` · `GPM-ISSUE-BODY.md`

## Storage

```
user/data/forumz/
  profiles/
  _sessions/
  {board-id}/
```

## License

MIT — Forumz Lite. See `LICENSE.md`.
