I would like to add my new plugin to the Grav Repository.

**Repository:** https://github.com/GravMUD/grav-plugin-forumz
**Release:** https://github.com/GravMUD/grav-plugin-forumz/releases/tag/1.0.1
**Direct install:** https://github.com/GravMUD/grav-plugin-forumz/releases/download/1.0.1/grav-plugin-forumz.zip
**Plugin name:** Forumz
**Plugin slug:** forumz
**License:** MIT (Lite edition)
**Grav target:** Grav 1.7 / 2.0 · optional Admin2 + API + Login
**Site / docs:** https://forumz.gravmud.site
**Discussions:** https://github.com/GravMUD/grav-plugin-forumz/discussions

---

## Summary

**Forumz** adds **flat-file forum boards** to Grav — boards, threads, replies, user profiles, and optional moderation — with **no database**. Lite (MIT) runs on standard Grav sites via JSON API + bundled CSS/JS embed. Optional **grav-mud-alpha** unlocks `:::forum` fences in `.mud` pages; optional **Mambers** bridges Grav Login identity across the Social Stack.

Renamed from legacy `mud-forumz` — same engine, Grav-first positioning.

---

## Dependencies

- grav >= 1.7.0

Optional:

- login (registered boards / Mambers bridge)
- admin2 + api (Admin2 panel at `/plugin/forumz` — moderation + board CRUD)
- mambers (identity bridge)
- grav-mud-admin (EvvyTink moderation + board CRUD at `/mud-admin`)
- grav-mud-alpha (`.mud` fence embeds only)

---

## Suggested maintainer test plan (~10 min)

```bash
bin/gpm direct-install https://github.com/GravMUD/grav-plugin-forumz/releases/download/1.0.1/grav-plugin-forumz.zip
bin/grav cache
```

1. Enable plugin in Admin → Forumz
2. Admin2 → `/plugin/forumz` → **Boards** tab → create a test board (or add board in `user/config/plugins/forumz.yaml` / `user/pages/**/boards/*.mforum`)
3. Create page with embed:

```html
<div class="forumz" data-forumz data-mode="board" data-board="general" data-api="/api/forumz"></div>
```

4. `GET /api/forumz/boards` → JSON list
5. Register/login via API or UI · post thread · reply
6. (Optional) Set `auto_approve: false` → moderation queue in Admin2 / EvvyTink

---

## Notes

- Storage: `user/data/forumz/` (JSON flat files)
- v1.0.1 adds Admin2 + EvvyTink **board CRUD** (create/edit/delete boards in yaml)
- Pro roadmap (commercial): attachments, advanced moderation — not required for Lite index entry
- Migrating from beta slug `mud-forumz`: rename plugin folder + data dir + config key

Thank you!
