(function () {
  "use strict";

  var API = window.FORUMZ_API || "/api/forumz";
  var sessionProfile = null;

  function esc(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function fmtDate(iso) {
    try {
      return new Date(iso).toLocaleString(undefined, {
        year: "numeric",
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    } catch (_e) {
      return iso;
    }
  }

  function boldify(text) {
    return esc(text).replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
  }

  function apiBase(root) {
    return (root && root.getAttribute("data-api")) || API;
  }

  function fetchJson(url, opts) {
    opts = opts || {};
    opts.credentials = "same-origin";
    return fetch(url, opts).then(function (r) {
      return r.json();
    });
  }

  function authorLink(p, root) {
    var slug = p.authorSlug || "";
    var avatar = p.authorAvatar ? esc(p.authorAvatar) + " " : "";
    if (slug) {
      var href = "#";
      if (root && root.getAttribute("data-mambers-auth") === "1") {
        var membersBase = root.getAttribute("data-members-url") || "/members";
        href = membersBase.replace(/\/$/, "") + "/" + encodeURIComponent(slug);
      }
      return (
        '<a href="' + esc(href) + '" class="forumz-author-link" data-profile="' +
        esc(slug) +
        '">' +
        avatar +
        esc(p.author) +
        "</a>"
      );
    }
    return avatar + esc(p.author);
  }

  function renderPost(p, root) {
    var badges = (p.authorBadges || [])
      .map(function (b) {
        return '<span class="forumz-badge">' + esc(b) + "</span>";
      })
      .join("");
    return (
      '<article class="forumz-post">' +
      '<header class="forumz-post-meta">' +
      '<strong class="forumz-author">' +
      authorLink(p, root) +
      "</strong>" +
      badges +
      '<time datetime="' +
      esc(p.created) +
      '">' +
      esc(fmtDate(p.created)) +
      "</time>" +
      "</header>" +
      '<div class="forumz-post-body">' +
      boldify(p.body) +
      "</div>" +
      "</article>"
    );
  }

  function sessionBarHtml(root) {
    if (sessionProfile) {
      var profileHref = (root && root.getAttribute("data-mambers-auth") === "1")
        ? ((root.getAttribute("data-members-url") || "/members").replace(/\/$/, "") + "/me")
        : null;
      return (
        '<div class="forumz-session is-logged-in">' +
        '<span>' +
        esc(sessionProfile.avatar || "🪐") +
        " Logged in as <strong>" +
        esc(sessionProfile.displayName) +
        "</strong> @" +
        esc(sessionProfile.slug) +
        "</span>" +
        (sessionProfile.source === "grav-login" || (root && root.getAttribute("data-mambers-auth") === "1")
          ? ""
          : '<button type="button" class="btn forumz-btn-logout">Logout</button>') +
        (profileHref
          ? '<a class="btn forumz-btn-edit-profile" href="' + esc(profileHref) + '">Your profile</a>'
          : '<button type="button" class="btn forumz-btn-edit-profile">Edit profile</button>') +
        "</div>"
      );
    }
    if (root && root.getAttribute("data-mambers-auth") === "1") {
      var loginUrl = root.getAttribute("data-login-url") || "/login";
      var registerUrl = root.getAttribute("data-register-url") || "/user_register";
      return (
        '<div class="forumz-session forumz-session--mambers">' +
        "<span>Post as a <strong>Mambers</strong> account — one identity across Forumz, Messenger, and profiles.</span>" +
        '<a class="btn primary" href="' + esc(loginUrl) + '">Login</a> ' +
        '<a class="btn" href="' + esc(registerUrl) + '">Register</a>' +
        "</div>"
      );
    }
    return (
      '<div class="forumz-session">' +
      "<span>Guest mode — register a callsign for linked profiles & badges.</span>" +
      '<button type="button" class="btn primary forumz-btn-login">Login</button> ' +
      '<button type="button" class="btn forumz-btn-register">Register</button>' +
      "</div>"
    );
  }

  function authDialogHtml(mode) {
    var isReg = mode === "register";
    return (
      '<div class="forumz-auth">' +
      "<h3>" +
      (isReg ? "Forge a callsign" : "Login") +
      "</h3>" +
      '<form class="forumz-form forumz-form--auth" data-auth="' +
      mode +
      '">' +
      (isReg
        ? '<label><span>Slug</span><input name="slug" pattern="[a-z0-9_-]{3,32}" required placeholder="chief" /></label>' +
          '<label><span>Display name</span><input name="displayName" maxlength="80" required /></label>' +
          '<label><span>Bio</span><textarea name="bio" rows="2" maxlength="500" placeholder="Gravver since…"></textarea></label>' +
          '<label><span>Avatar (emoji)</span><input name="avatar" maxlength="4" placeholder="🌿" /></label>'
        : '<label><span>Slug</span><input name="slug" required placeholder="chief" /></label>') +
      '<label><span>Passphrase</span><input type="password" name="passphrase" minlength="6" required /></label>' +
      '<label class="forumz-honey" aria-hidden="true"><span>Website</span><input name="website" tabindex="-1" /></label>' +
      '<div class="forumz-actions"><button type="submit" class="btn primary">' +
      (isReg ? "Register →" : "Login →") +
      '</button><p class="forumz-status" role="status"></p></div></form></div>'
    );
  }

  function profileCard(p) {
    var badges = (p.badges || [])
      .map(function (b) {
        return '<span class="forumz-badge">' + esc(b) + "</span>";
      })
      .join("");
    return (
      '<article class="forumz-profile-card">' +
      '<div class="forumz-profile-avatar">' +
      esc(p.avatar || "🪐") +
      "</div>" +
      "<h3>" +
      esc(p.displayName) +
      "</h3>" +
      '<p class="forumz-profile-slug">@' +
      esc(p.slug) +
      "</p>" +
      '<p class="forumz-profile-bio">' +
      esc(p.bio || "") +
      "</p>" +
      '<div class="forumz-profile-badges">' +
      badges +
      "</div>" +
      '<p class="forumz-profile-stats">' +
      (p.stats ? p.stats.threads + " threads · " + p.stats.posts + " posts" : "") +
      "</p>" +
      '<button type="button" class="btn forumz-btn-view-profile" data-profile="' +
      esc(p.slug) +
      '">View profile</button></article>'
    );
  }

  function renderThreadRow(t, board) {
    var replies =
      typeof t.replyCount === "number"
        ? t.replyCount
        : Math.max(0, (t.posts && t.posts.length - 1) || 0);
    var lock = t.locked ? " 🔒" : "";
    return (
      '<li class="forumz-thread-row' +
      (t.pinned ? " is-pinned" : "") +
      '">' +
      '<a href="#" class="forumz-thread-link" data-board="' +
      esc(board) +
      '" data-thread="' +
      esc(t.id) +
      '">' +
      '<span class="forumz-thread-title">' +
      esc(t.title) +
      lock +
      "</span>" +
      '<span class="forumz-thread-meta">' +
      esc(t.author || "anon") +
      " · " +
      esc(fmtDate(t.updated)) +
      " · " +
      replies +
      " repl" +
      (replies === 1 ? "y" : "ies") +
      (t.pinned ? " · pinned" : "") +
      "</span></a></li>"
    );
  }

  function boardShell(root) {
    var board = root.getAttribute("data-board") || "general";
    var hideHeader = root.getAttribute("data-hide-header") === "1";
    var header = hideHeader
      ? ""
      : '<header class="forumz-header">' +
        '<p class="eyebrow">GravForumz™ · Flat-File Discourse</p>' +
        '<h2 class="forumz-title">Disturbances in the Forum</h2>' +
        '<p class="forumz-dek">Boards, threads, profiles, moderation — zero MySQL.</p></header>';
    return (
      sessionBarHtml(root) +
      header +
      '<div class="forumz-auth-host"></div>' +
      '<div class="forumz-board" data-board-view="' +
      esc(board) +
      '">' +
      '<ol class="forumz-thread-list"></ol>' +
      '<section class="forumz-new-thread"><h3>Start a thread</h3>' +
      '<form class="forumz-form forumz-form--thread">' +
      '<label class="forumz-guest-author"><span>Callsign (guest)</span><input name="author" maxlength="80" placeholder="Or login above…" /></label>' +
      '<label><span>Subject</span><input name="title" maxlength="160" required /></label>' +
      '<label><span>Opening transmission</span><textarea name="body" rows="5" maxlength="8000" required></textarea></label>' +
      '<label class="forumz-honey" aria-hidden="true"><span>Website</span><input name="website" tabindex="-1" /></label>' +
      '<div class="forumz-actions"><button type="submit" class="btn primary">Deploy thread →</button>' +
      '<p class="forumz-status" role="status"></p></div></form></section></div>' +
      '<section class="forumz-thread-view" hidden>' +
      '<button type="button" class="btn forumz-back">← Back to board</button>' +
      '<div class="forumz-thread-detail"></div>' +
      '<form class="forumz-form forumz-form--reply"><h3>Reply</h3>' +
      '<label class="forumz-guest-author"><span>Callsign (guest)</span><input name="author" maxlength="80" /></label>' +
      '<label><span>Reply</span><textarea name="body" rows="4" maxlength="8000" required></textarea></label>' +
      '<label class="forumz-honey" aria-hidden="true"><span>Website</span><input name="website" tabindex="-1" /></label>' +
      '<div class="forumz-actions"><button type="submit" class="btn primary">Beam reply →</button>' +
      '<p class="forumz-status" role="status"></p></div></form></section>'
    );
  }

  function setStatus(form, msg, isError) {
    var el = form && form.querySelector(".forumz-status");
    if (!el) return;
    el.textContent = msg || "";
    el.classList.toggle("is-error", !!isError);
  }

  function updateGuestFields(root) {
    root.querySelectorAll(".forumz-guest-author").forEach(function (label) {
      label.style.display = sessionProfile ? "none" : "";
      var input = label.querySelector("input");
      if (input) input.required = !sessionProfile;
    });
  }

  function refreshSessionBar(root) {
    var bar = root.querySelector(".forumz-session");
    if (bar) {
      var tmp = document.createElement("div");
      tmp.innerHTML = sessionBarHtml(root);
      bar.replaceWith(tmp.firstElementChild);
      wireSession(root);
    }
    updateGuestFields(root);
  }

  function loadSession(root) {
    var base = apiBase(root || document.querySelector("[data-forumz]"));
    return fetchJson(base + "/session").then(function (data) {
      sessionProfile = data.loggedIn
        ? Object.assign({}, data.profile || {}, { source: data.source || null })
        : null;
      return sessionProfile;
    });
  }

  function wireSession(root) {
    var loginBtn = root.querySelector(".forumz-btn-login");
    var regBtn = root.querySelector(".forumz-btn-register");
    var logoutBtn = root.querySelector(".forumz-btn-logout");
    var editBtn = root.querySelector(".forumz-btn-edit-profile");
    var authHost = root.querySelector(".forumz-auth-host");

    function showAuth(mode) {
      if (!authHost) return;
      authHost.innerHTML = authDialogHtml(mode);
      var form = authHost.querySelector(".forumz-form--auth");
      form.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var fd = new FormData(form);
        var endpoint = mode === "register" ? "/register" : "/login";
        setStatus(form, "Working…");
        fetchJson(apiBase(root) + endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({
            slug: String(fd.get("slug") || "").trim(),
            displayName: String(fd.get("displayName") || "").trim(),
            bio: String(fd.get("bio") || "").trim(),
            avatar: String(fd.get("avatar") || "").trim(),
            passphrase: String(fd.get("passphrase") || ""),
            website: String(fd.get("website") || ""),
          }),
        })
          .then(function (data) {
            if (!data.ok) {
              setStatus(form, data.error || "Failed.", true);
              return;
            }
            sessionProfile = data.profile || null;
            authHost.innerHTML = "";
            refreshSessionBar(root);
            setStatus(form, data.message || "OK.");
          })
          .catch(function () {
            setStatus(form, "Auth failed.", true);
          });
      });
    }

    if (loginBtn) loginBtn.addEventListener("click", function () { showAuth("login"); });
    if (regBtn) regBtn.addEventListener("click", function () { showAuth("register"); });
    if (logoutBtn) {
      logoutBtn.addEventListener("click", function () {
        fetchJson(apiBase(root) + "/logout", { method: "POST" }).then(function () {
          sessionProfile = null;
          refreshSessionBar(root);
        });
      });
    }
    if (editBtn && sessionProfile) {
      editBtn.addEventListener("click", function () {
        if (!authHost) return;
        authHost.innerHTML =
          '<div class="forumz-auth"><h3>Edit profile</h3>' +
          '<form class="forumz-form forumz-form--edit">' +
          '<label><span>Display name</span><input name="displayName" value="' + esc(sessionProfile.displayName) + '" maxlength="80" required /></label>' +
          '<label><span>Bio</span><textarea name="bio" rows="2" maxlength="500">' + esc(sessionProfile.bio || "") + "</textarea></label>" +
          '<label><span>Avatar</span><input name="avatar" maxlength="4" value="' + esc(sessionProfile.avatar || "") + '" /></label>' +
          '<label><span>New passphrase (optional)</span><input type="password" name="passphrase" minlength="6" /></label>' +
          '<div class="forumz-actions"><button type="submit" class="btn primary">Save</button>' +
          '<p class="forumz-status" role="status"></p></div></form></div>';
        authHost.querySelector(".forumz-form--edit").addEventListener("submit", function (ev) {
          ev.preventDefault();
          var fd = new FormData(ev.target);
          fetchJson(apiBase(root) + "/profile", {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              displayName: String(fd.get("displayName") || "").trim(),
              bio: String(fd.get("bio") || "").trim(),
              avatar: String(fd.get("avatar") || "").trim(),
              passphrase: String(fd.get("passphrase") || ""),
            }),
          }).then(function (data) {
            if (data.ok && data.profile) {
              sessionProfile = data.profile;
              authHost.innerHTML = "";
              refreshSessionBar(root);
            }
          });
        });
      });
    }
  }

  function wireProfileLinks(root) {
    root.querySelectorAll("[data-profile]").forEach(function (el) {
      el.addEventListener("click", function (ev) {
        ev.preventDefault();
        showProfileModal(root, el.getAttribute("data-profile"));
      });
    });
  }

  function showProfileModal(root, slug) {
    fetchJson(apiBase(root) + "/profile?user=" + encodeURIComponent(slug)).then(function (data) {
      if (!data.ok || !data.profile) return;
      var p = data.profile;
      var host = root.querySelector(".forumz-auth-host") || root;
      host.innerHTML =
        '<div class="forumz-profile-view">' +
        profileCard(p) +
        '<button type="button" class="btn forumz-btn-close-profile">Close</button></div>';
      host.querySelector(".forumz-btn-close-profile").addEventListener("click", function () {
        host.innerHTML = "";
      });
    });
  }

  function loadBoard(root, board, limit) {
    var api = apiBase(root);
    var list = root.querySelector(".forumz-thread-list");
    if (!list) return;
    list.innerHTML = '<li class="forumz-empty">Loading threads…</li>';
    fetchJson(api + "/threads?board=" + encodeURIComponent(board) + "&limit=" + encodeURIComponent(limit))
      .then(function (data) {
        if (!data.ok) throw new Error();
        var threads = data.threads || [];
        list.innerHTML = threads.length
          ? threads.map(function (t) { return renderThreadRow(t, board); }).join("")
          : '<li class="forumz-empty">No threads yet.</li>';
        wireThreadLinks(root, board);
      })
      .catch(function () {
        list.innerHTML = '<li class="forumz-empty">Forum warp drive offline.</li>';
      });
  }

  function wireThreadLinks(root, board) {
    root.querySelectorAll(".forumz-thread-link").forEach(function (a) {
      a.addEventListener("click", function (ev) {
        ev.preventDefault();
        openThread(root, board, a.getAttribute("data-thread"));
      });
    });
  }

  function openThread(root, board, threadId) {
    var api = apiBase(root);
    var boardView = root.querySelector("[data-board-view]");
    var threadView = root.querySelector(".forumz-thread-view");
    var detail = root.querySelector(".forumz-thread-detail");
    if (!threadView || !detail) return;
    detail.innerHTML = "<p>Loading…</p>";
    if (boardView) boardView.hidden = true;
    threadView.hidden = false;
    threadView.setAttribute("data-active-thread", threadId);
    threadView.setAttribute("data-active-board", board);
    fetchJson(api + "/thread?board=" + encodeURIComponent(board) + "&thread=" + encodeURIComponent(threadId))
      .then(function (data) {
        if (!data.ok || !data.thread) throw new Error();
        var t = data.thread;
        detail.innerHTML =
          '<header class="forumz-thread-head"><h3>' + esc(t.title) + (t.locked ? " 🔒" : "") + "</h3>" +
          '<p class="forumz-thread-byline">by ' + authorLink({ author: t.author, authorSlug: t.authorSlug }, root) + " · " + esc(fmtDate(t.created)) + "</p></header>" +
          '<div class="forumz-posts">' + (t.posts || []).map(function (p) { return renderPost(p, root); }).join("") + "</div>";
        wireProfileLinks(root);
      })
      .catch(function () {
        detail.innerHTML = '<p class="forumz-empty">Could not load thread.</p>';
      });
  }

  function initBoard(root) {
    var board = root.getAttribute("data-board") || "general";
    var limit = root.getAttribute("data-limit") || "20";
    root.innerHTML = boardShell(root);
    loadSession(root).then(function () {
      refreshSessionBar(root);
      loadBoard(root, board, limit);
    });

    var newForm = root.querySelector(".forumz-form--thread");
    if (newForm) {
      newForm.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var fd = new FormData(newForm);
        setStatus(newForm, "Deploying…");
        fetchJson(apiBase(root) + "/thread", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            board: board,
            author: String(fd.get("author") || "").trim(),
            title: String(fd.get("title") || "").trim(),
            body: String(fd.get("body") || "").trim(),
            website: String(fd.get("website") || ""),
          }),
        }).then(function (data) {
          if (!data.ok) { setStatus(newForm, data.error || "Rejected.", true); return; }
          setStatus(newForm, data.message || "Deployed.");
          newForm.reset();
          loadBoard(root, board, limit);
        });
      });
    }

    var backBtn = root.querySelector(".forumz-back");
    if (backBtn) {
      backBtn.addEventListener("click", function () {
      var bv = root.querySelector("[data-board-view]");
      var tv = root.querySelector(".forumz-thread-view");
      if (bv) bv.hidden = false;
      if (tv) tv.hidden = true;
    });
    }

    var replyForm = root.querySelector(".forumz-form--reply");
    if (replyForm) {
      replyForm.addEventListener("submit", function (ev) {
        ev.preventDefault();
        var tv = root.querySelector(".forumz-thread-view");
        var threadId = tv ? tv.getAttribute("data-active-thread") || "" : "";
        var activeBoard = tv ? tv.getAttribute("data-active-board") || board : board;
        var fd = new FormData(replyForm);
        setStatus(replyForm, "Beaming…");
        fetchJson(apiBase(root) + "/reply", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            board: activeBoard,
            thread: threadId,
            author: String(fd.get("author") || "").trim(),
            body: String(fd.get("body") || "").trim(),
            website: String(fd.get("website") || ""),
          }),
        }).then(function (data) {
          if (!data.ok) { setStatus(replyForm, data.error || "Rejected.", true); return; }
          setStatus(replyForm, data.message || "Beamed.");
          replyForm.reset();
          openThread(root, activeBoard, threadId);
          loadBoard(root, board, limit);
        });
      });
    }
    wireSession(root);
  }

  function initThreadOnly(root) {
    var board = root.getAttribute("data-board") || "general";
    var thread = root.getAttribute("data-thread") || "";
    root.innerHTML = '<div class="forumz-thread-embed"></div>';
    fetchJson(apiBase(root) + "/thread?board=" + encodeURIComponent(board) + "&thread=" + encodeURIComponent(thread))
      .then(function (data) {
        var detail = root.querySelector(".forumz-thread-embed");
        if (!data.ok || !detail) return;
        var t = data.thread;
        detail.innerHTML =
          '<header class="forumz-thread-head"><h3>' + esc(t.title) + "</h3></header>" +
          '<div class="forumz-posts">' + (t.posts || []).map(function (p) { return renderPost(p, root); }).join("") + "</div>";
        wireProfileLinks(root);
      });
  }

  function initProfile(root) {
    var slug = root.getAttribute("data-user") || "";
    fetchJson(apiBase(root) + "/profile?user=" + encodeURIComponent(slug)).then(function (data) {
      if (!data.ok || !data.profile) {
        root.innerHTML = '<p class="forumz-empty">Profile not found.</p>';
        return;
      }
      root.innerHTML = '<div class="forumz-profile-embed">' + profileCard(data.profile) + "</div>";
    });
  }

  function initProfiles(root) {
    var limit = root.getAttribute("data-limit") || "12";
    var title = root.getAttribute("data-title") || "";
    fetchJson(apiBase(root) + "/profiles?limit=" + encodeURIComponent(limit)).then(function (data) {
      var profiles = data.profiles || [];
      root.innerHTML =
        (title ? '<h3 class="forumz-section-title">' + esc(title) + "</h3>" : "") +
        '<div class="forumz-profiles-grid">' +
        (profiles.length ? profiles.map(profileCard).join("") : '<p class="forumz-empty">No profiles yet.</p>') +
        "</div>";
      root.querySelectorAll(".forumz-btn-view-profile").forEach(function (btn) {
        btn.addEventListener("click", function () {
          showProfileModal(root, btn.getAttribute("data-profile"));
        });
      });
    });
  }

  document.querySelectorAll("[data-forumz]").forEach(function (root) {
    var mode = root.getAttribute("data-mode") || "board";
    if (mode === "thread") initThreadOnly(root);
    else if (mode === "profile") initProfile(root);
    else if (mode === "profiles") initProfiles(root);
    else initBoard(root);
  });
})();
