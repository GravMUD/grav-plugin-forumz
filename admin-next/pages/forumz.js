/**
 * GravForumz — Admin2 moderation + boards
 */
(function () {
  const TAG = window.__GRAV_PAGE_TAG || 'grav-forumz--page';
  const BTN = 'inline-flex items-center rounded-md border border-border bg-muted/40 px-3 py-1.5 text-xs font-semibold hover:bg-accent';
  const BTN_PRI = 'inline-flex items-center rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:opacity-90';

  function apiCfg() {
    return {
      serverUrl: window.__GRAV_API_SERVER_URL || window.__GRAV_CONFIG__?.serverUrl || '',
      apiPrefix: window.__GRAV_API_PREFIX || window.__GRAV_CONFIG__?.apiPrefix || '/api/v1',
      token: window.__GRAV_API_TOKEN || null,
    };
  }

  function apiUrl(path) {
    const c = apiCfg();
    return `${c.serverUrl}${c.apiPrefix}${path.startsWith('/') ? path : `/${path}`}`;
  }

  async function api(path, options) {
    const c = apiCfg();
    const headers = { Accept: 'application/json', ...(options?.headers || {}) };
    if (!(options?.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    if (c.token) headers['X-API-Token'] = c.token;
    const res = await fetch(apiUrl(path), { ...options, headers, credentials: 'include' });
    const json = await res.json();
    const data = json.data !== undefined ? json.data : json;
    if (!res.ok) throw new Error(data.detail || data.error || data.message || `HTTP ${res.status}`);
    return data;
  }

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  class ForumzAdminPage extends HTMLElement {
    connectedCallback() {
      if (this._booted) return;
      this._booted = true;
      this._tab = 'queue';
      this.className = 'block h-full min-h-[28rem] text-foreground';
      this.innerHTML = `
        <div class="flex h-full min-h-[28rem] flex-col gap-4 p-6">
          <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border pb-4">
            <div>
              <h2 class="text-lg font-bold">Forumz Lite</h2>
              <p class="mt-1 max-w-xl text-sm text-muted-foreground">Flat-file boards · .mforum scanner · Mambers bridge · moderation queue.</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" data-tab="queue" class="${BTN_PRI}">Queue</button>
              <button type="button" data-tab="profiles" class="${BTN}">Profiles</button>
              <button type="button" data-tab="boards" class="${BTN}">Boards</button>
            </div>
          </header>
          <div data-stats class="flex flex-wrap gap-4 rounded-lg border border-border bg-muted/20 px-4 py-3 text-sm"></div>
          <div data-panel-queue class="overflow-y-auto rounded-lg border border-border bg-muted/10 p-3"></div>
          <div data-panel-profiles class="hidden overflow-y-auto rounded-lg border border-border bg-muted/10 p-3"></div>
          <div data-panel-boards class="hidden overflow-y-auto rounded-lg border border-border bg-muted/10 p-3"></div>
          <p data-status class="text-xs text-muted-foreground"></p>
        </div>`;

      this.querySelectorAll('[data-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
          this._tab = btn.getAttribute('data-tab');
          this.querySelectorAll('[data-tab]').forEach((b) => {
            b.className = b.getAttribute('data-tab') === this._tab ? BTN_PRI : BTN;
          });
          this.querySelector('[data-panel-queue]').classList.toggle('hidden', this._tab !== 'queue');
          this.querySelector('[data-panel-profiles]').classList.toggle('hidden', this._tab !== 'profiles');
          this.querySelector('[data-panel-boards]').classList.toggle('hidden', this._tab !== 'boards');
        });
      });

      this.refresh().catch((e) => this.setStatus(e.message, true));
    }

    setStatus(msg, err) {
      const el = this.querySelector('[data-status]');
      if (el) {
        el.textContent = msg;
        el.className = err ? 'text-xs text-destructive' : 'text-xs text-muted-foreground';
      }
    }

    async refresh() {
      const stats = await api('/forumz/admin/stats');
      const statsEl = this.querySelector('[data-stats]');
      statsEl.innerHTML = `
        <span><strong>${stats.threads ?? 0}</strong> threads</span>
        <span><strong>${stats.posts ?? 0}</strong> posts</span>
        <span><strong>${stats.profiles ?? 0}</strong> profiles</span>
        <span><strong>${stats.pending ?? 0}</strong> pending</span>
        <span><strong>${stats.boards ?? 0}</strong> boards</span>`;

      const queue = await api('/forumz/admin/queue');
      const queueEl = this.querySelector('[data-panel-queue]');
      const items = queue.items || queue.queue || [];
      queueEl.innerHTML = items.length
        ? `<ul class="space-y-3">${items.map((item) => this.queueItem(item)).join('')}</ul>`
        : '<p class="text-sm text-muted-foreground">Queue empty — tribbles approved.</p>';
      queueEl.querySelectorAll('[data-mod]').forEach((btn) => {
        btn.addEventListener('click', () => {
          this.moderate(JSON.parse(decodeURIComponent(btn.getAttribute('data-mod'))))
            .then(() => this.refresh())
            .catch((e) => this.setStatus(e.message, true));
        });
      });

      const profiles = await api('/forumz/admin/profiles');
      const profEl = this.querySelector('[data-panel-profiles]');
      const profs = profiles.profiles || [];
      profEl.innerHTML = profs.length
        ? `<ul class="space-y-2 text-sm">${profs.map((p) => `
            <li class="rounded border border-border p-2">
              <strong>${esc(p.displayName || p.slug)}</strong> · @${esc(p.slug)}
              ${p.bridged ? ' · bridged' : ''}
              ${p.banned ? ' · <span class="text-destructive">banned</span>' : ''}
              <div class="mt-1 flex gap-2">
                ${p.banned
                  ? `<button type="button" data-mod='${encodeURIComponent(JSON.stringify({ action: 'unban_profile', slug: p.slug }))}' class="${BTN}">Unban</button>`
                  : `<button type="button" data-mod='${encodeURIComponent(JSON.stringify({ action: 'ban_profile', slug: p.slug }))}' class="${BTN}">Ban</button>`}
              </div>
            </li>`).join('')}</ul>`
        : '<p class="text-sm text-muted-foreground">No profiles yet.</p>';
      profEl.querySelectorAll('[data-mod]').forEach((btn) => {
        btn.addEventListener('click', () => {
          this.moderate(JSON.parse(decodeURIComponent(btn.getAttribute('data-mod'))))
            .then(() => this.refresh())
            .catch((e) => this.setStatus(e.message, true));
        });
      });

      const boards = await api('/forumz/admin/boards');
      const boardEl = this.querySelector('[data-panel-boards]');
      const blist = boards.boards || [];
      boardEl.innerHTML = `
        <div class="mb-4 rounded-lg border border-border bg-muted/20 p-4">
          <h3 class="mb-3 text-sm font-bold" data-board-form-title>New board</h3>
          <form data-board-form class="grid gap-3 text-sm md:grid-cols-2">
            <label class="block">Board id
              <input name="id" required pattern="[a-z0-9_-]{1,32}" class="mt-1 w-full rounded border border-border bg-background px-2 py-1.5 font-mono text-xs" placeholder="general">
            </label>
            <label class="block">Title
              <input name="title" required class="mt-1 w-full rounded border border-border bg-background px-2 py-1.5" placeholder="General Discussion">
            </label>
            <label class="block md:col-span-2">Description
              <input name="description" class="mt-1 w-full rounded border border-border bg-background px-2 py-1.5">
            </label>
            <label class="block">Post policy
              <select name="postPolicy" class="mt-1 w-full rounded border border-border bg-background px-2 py-1.5">
                <option value="open">open</option>
                <option value="registered">registered</option>
                <option value="moderators">moderators</option>
              </select>
            </label>
            <div class="flex flex-wrap items-end gap-2">
              <button type="submit" data-board-save class="${BTN_PRI}">Save board</button>
              <button type="button" data-board-reset class="${BTN}">Clear</button>
            </div>
          </form>
        </div>
        ${blist.length
          ? `<ul class="space-y-2 text-sm">${blist.map((b) => this.boardItem(b)).join('')}</ul>`
          : '<p class="text-sm text-muted-foreground">No boards yet — create one above.</p>'}`;

      const form = boardEl.querySelector('[data-board-form]');
      const formTitle = boardEl.querySelector('[data-board-form-title]');
      const resetBtn = boardEl.querySelector('[data-board-reset]');

      const resetForm = () => {
        form.reset();
        form.querySelector('[name="id"]').removeAttribute('readonly');
        formTitle.textContent = 'New board';
        delete form.dataset.editId;
      };

      resetBtn.addEventListener('click', resetForm);

      form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const fd = new FormData(form);
        const payload = {
          id: String(fd.get('id') || '').trim(),
          title: String(fd.get('title') || '').trim(),
          description: String(fd.get('description') || '').trim(),
          postPolicy: String(fd.get('postPolicy') || 'open'),
          create: !form.dataset.editId,
        };
        api('/forumz/admin/boards/save', { method: 'POST', body: JSON.stringify(payload) })
          .then(() => { resetForm(); return this.refresh(); })
          .catch((e) => this.setStatus(e.message, true));
      });

      boardEl.querySelectorAll('[data-board-edit]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const b = JSON.parse(decodeURIComponent(btn.getAttribute('data-board-edit')));
          form.querySelector('[name="id"]').value = b.id;
          form.querySelector('[name="id"]').setAttribute('readonly', 'readonly');
          form.querySelector('[name="title"]').value = b.title || '';
          form.querySelector('[name="description"]').value = b.description || '';
          form.querySelector('[name="postPolicy"]').value = b.postPolicy || 'open';
          form.dataset.editId = b.id;
          formTitle.textContent = `Edit board · ${b.id}`;
        });
      });

      boardEl.querySelectorAll('[data-board-delete]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const b = JSON.parse(decodeURIComponent(btn.getAttribute('data-board-delete')));
          if (!confirm(`Remove board "${b.id}" from config${b.mforumOnly ? ' (.mforum file can be removed too)' : ''}?`)) return;
          const deleteData = confirm('Also delete all threads and posts for this board?');
          const deleteMforumFile = b.mforumOnly && confirm('Delete the .mforum definition file on disk?');
          api('/forumz/admin/boards/delete', {
            method: 'POST',
            body: JSON.stringify({ id: b.id, deleteData, deleteMforumFile }),
          })
            .then(() => this.refresh())
            .catch((e) => this.setStatus(e.message, true));
        });
      });

      this.setStatus('Forumz refreshed.');
    }

    queueItem(item) {
      const actions = [];
      if (item.type === 'thread') {
        actions.push({ action: 'approve_thread', board: item.boardId, thread: item.threadId });
        actions.push({ action: 'reject_thread', board: item.boardId, thread: item.threadId });
        actions.push({ action: 'pin_thread', board: item.boardId, thread: item.threadId });
        actions.push({ action: 'lock_thread', board: item.boardId, thread: item.threadId });
      } else {
        actions.push({ action: 'approve_post', board: item.boardId, thread: item.threadId, postId: item.postId });
        actions.push({ action: 'reject_post', board: item.boardId, thread: item.threadId, postId: item.postId });
      }
      return `<li class="rounded border border-border p-2 text-sm">
        <strong>${esc(item.title || item.type)}</strong>
        <div class="text-xs text-muted-foreground">${esc(item.boardId)} / ${esc(item.threadId || '')}</div>
        <div class="mt-2 flex flex-wrap gap-2">${actions.map((a) =>
          `<button type="button" data-mod="${encodeURIComponent(JSON.stringify(a))}" class="${BTN}">${esc(a.action.replace('_', ' '))}</button>`
        ).join('')}</div>
      </li>`;
    }

    async moderate(payload) {
      await api('/forumz/admin/moderate', { method: 'POST', body: JSON.stringify(payload) });
      this.setStatus('Moderation applied.');
    }

    boardItem(b) {
      return `<li class="rounded border border-border p-2">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <strong>${esc(b.title)}</strong> · <code>${esc(b.id)}</code>
            <span class="text-muted-foreground"> · ${esc(b.postPolicy)} · ${esc(b.source)} · ${b.threadCount ?? 0} threads</span>
            ${b.path ? `<div class="text-xs text-muted-foreground">${esc(b.path)}</div>` : ''}
            ${b.description ? `<p class="mt-1 text-muted-foreground">${esc(b.description)}</p>` : ''}
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button" data-board-edit="${encodeURIComponent(JSON.stringify(b))}" class="${BTN}">Edit</button>
            <button type="button" data-board-delete="${encodeURIComponent(JSON.stringify({ id: b.id, mforumOnly: !!b.mforumOnly }))}" class="${BTN}">Delete</button>
          </div>
        </div>
      </li>`;
    }
  }

  if (!customElements.get(TAG)) {
    customElements.define(TAG, ForumzAdminPage);
  }
})();
