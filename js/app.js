/* Allstar SPA — vanilla JS, no build step. Renders Login / Admin / Coach views by role. */
(() => {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────────
  const S = {
    role: null,                 // 'admin' | 'coach' | null
    csrf: '',
    leagueName: 'Allstar',
    state: null,                // latest state.php payload
    pollTimer: null,
    pollMs: 2000,
    selectedPicks: new Set(),   // round id-> picks (regular rounds; unordered)
    rankedPicks: [],            // ordered array (alternate rounds)
    activePicksRoundId: null,
    lastErr: '',
    confirmDialog: null,
    view: 'login',
    adminSubview: 'dashboard',  // dashboard | setup | codes | results | overrides | audit
    roundOverride: new Map(),   // round_num → bool (true=expanded, false=collapsed). Absent → default
    lastMaxRoundNum: 0,         // tracks the highest round_num seen
    lastFocusKey: '',           // "<round_num>:<state>" of the latest round — reset overrides when this changes
    lastStateSig: '',           // signature of last rendered state — skip re-render when unchanged
  };

  // ── DOM helpers ────────────────────────────────────────────────────────────
  const $ = (sel, parent = document) => parent.querySelector(sel);
  const $$ = (sel, parent = document) => Array.from(parent.querySelectorAll(sel));

  function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') el.className = v;
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else if (v === false || v == null) continue;
      else if (v === true) el.setAttribute(k, '');
      else el.setAttribute(k, v);
    }
    for (const c of children.flat()) {
      if (c == null || c === false) continue;
      el.append(c.nodeType ? c : document.createTextNode(c));
    }
    return el;
  }

  function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }
  function mount(node, ...children) { clear(node); for (const c of children) node.append(c); }

  function toast(msg, type = 'info', ms = 3500) {
    const t = $('#toast');
    const item = h('div', { class: `toast-item toast-${type}` }, msg);
    t.append(item);
    setTimeout(() => { item.classList.add('out'); setTimeout(() => item.remove(), 300); }, ms);
  }

  function confirmDialog(title, message, onYes, yesLabel = 'Confirm', danger = false) {
    if (S.confirmDialog) S.confirmDialog.remove();
    const overlay = h('div', { class: 'overlay' });
    overlay.append(
      h('div', { class: 'modal' },
        h('h3', {}, title),
        h('p', {}, message),
        h('div', { class: 'modal-actions' },
          h('button', { class: 'btn btn-secondary', onclick: () => overlay.remove() }, 'Cancel'),
          h('button', { class: `btn ${danger ? 'btn-danger' : 'btn-primary'}`,
            onclick: async () => { overlay.remove(); await onYes(); } }, yesLabel),
        )
      )
    );
    document.body.append(overlay);
    S.confirmDialog = overlay;
  }

  // ── API ────────────────────────────────────────────────────────────────────
  async function api(path, action, body = null, opts = {}) {
    const url = `api/${path}.php?action=${encodeURIComponent(action)}` + (opts.query ? '&' + opts.query : '');
    const init = {
      method: body ? 'POST' : 'GET',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
    };
    if (body) {
      init.body = JSON.stringify(body);
      if (S.csrf) init.headers['X-CSRF-Token'] = S.csrf;
    }
    const r = await fetch(url, init);
    let data;
    try { data = await r.json(); } catch { data = { error: 'Bad response' }; }
    if (!r.ok || data.error) {
      const e = new Error(data.error || `HTTP ${r.status}`);
      e.status = r.status;
      throw e;
    }
    return data;
  }

  // ── Polling ────────────────────────────────────────────────────────────────
  async function startPolling() {
    stopPolling();
    await pollOnce();
    S.pollTimer = setInterval(pollOnce, S.pollMs);
  }
  function stopPolling() { if (S.pollTimer) { clearInterval(S.pollTimer); S.pollTimer = null; } }

  function isEditingInline() {
    const a = document.activeElement;
    if (!a) return false;
    const tag = a.tagName;
    if (tag !== 'INPUT' && tag !== 'TEXTAREA' && tag !== 'SELECT') return false;
    if (a.closest('.overlay')) return false; // modals survive re-render
    return !!a.closest('#main');
  }

  // Stable signature of the state payload, used to skip the 2s re-render when
  // nothing user-visible has changed. last_seen_at advances on every poll a
  // coach makes, so we drop it from the comparison — its effect on the UI is
  // mediated by the (also-included) `logged_in` boolean which only flips when
  // a coach crosses the 30s-active threshold.
  const _STATE_NOISE_KEYS = new Set(['last_seen_at', 'updated_at']);
  function stateSignature(s) {
    if (!s) return '';
    return JSON.stringify(s, (k, v) => _STATE_NOISE_KEYS.has(k) ? undefined : v);
  }

  async function pollOnce() {
    try {
      const data = await api('state', 'poll');
      S.state = data;
      // Throttle when coach has already submitted in a non-finalized round
      if (data.role === 'coach') {
        const ballotIn = data.ballot && data.ballot.submitted && data.round && data.round.state !== 'finalized';
        const newMs = ballotIn ? 5000 : 2000;
        if (newMs !== S.pollMs) { S.pollMs = newMs; if (S.pollTimer) { clearInterval(S.pollTimer); S.pollTimer = setInterval(pollOnce, S.pollMs); } }
      }
      const sig = stateSignature(data);
      if (sig !== S.lastStateSig) {
        S.lastStateSig = sig;
        if (!isEditingInline()) render();
      }
    } catch (e) {
      if (e.status === 401) {
        stopPolling();
        S.role = null; S.state = null;
        S.view = 'login';
        S.lastStateSig = '';
        render();
      } else {
        const msg = e.message || '';
        if (msg !== S.lastErr) {
          S.lastErr = msg;
          if (!isEditingInline()) render();
        }
      }
    }
  }

  // ── Auth ───────────────────────────────────────────────────────────────────
  async function init() {
    try {
      const r = await api('auth', 'check');
      S.csrf = r.csrf_token || '';
      S.leagueName = r.league_name || 'Allstar';
      S.role = r.role || null;
      if (S.role) startPolling(); else render();
    } catch (e) {
      try {
        const r2 = await api('auth', 'check').catch(() => ({}));
        if (r2.csrf_token) S.csrf = r2.csrf_token;
      } catch {}
      S.role = null;
      render();
    }
  }

  async function adminLogin(pin) {
    if (!S.csrf) { const r = await api('auth', 'check'); S.csrf = r.csrf_token; S.leagueName = r.league_name; }
    const r = await api('auth', 'admin_login', { pin });
    S.csrf = r.csrf_token; S.role = 'admin';
    await startPolling();
  }
  async function coachLogin(vote_code, password) {
    if (!S.csrf) { const r = await api('auth', 'check'); S.csrf = r.csrf_token; S.leagueName = r.league_name; }
    const r = await api('auth', 'coach_login', { vote_code, password });
    S.csrf = r.csrf_token; S.role = 'coach';
    await startPolling();
  }
  async function logout() {
    await api('auth', 'logout', {});
    S.role = null; S.state = null; S.csrf = '';
    stopPolling();
    init();
  }

  // ── Render dispatcher ──────────────────────────────────────────────────────
  function render() {
    renderTopbar();
    const main = $('#main');
    if (!S.role) { mount(main, renderLogin()); return; }
    if (S.role === 'admin') { mount(main, renderAdmin()); return; }
    if (S.role === 'coach') { mount(main, renderCoach()); return; }
  }

  function renderTopbar() {
    const ctx = $('#topbar-context');
    const act = $('#topbar-actions');
    clear(ctx); clear(act);
    ctx.append(h('span', { class: 'league' }, S.leagueName));
    if (S.role && S.state && S.state.election) {
      ctx.append(
        h('span', { class: 'sep' }, '·'),
        h('span', { class: 'elec' }, S.state.election.name),
        h('span', { class: `pill pill-${S.state.election.status}` }, S.state.election.status),
      );
    }
    if (S.role) {
      if (S.role === 'coach' && S.state && S.state.voter_word) {
        act.append(h('span', { class: 'badge badge-coach' }, '🎟 ', S.state.voter_word));
      }
      if (S.role === 'admin' && S.state && S.state.election) {
        act.append(h('button', { class: 'btn btn-sm btn-secondary', onclick: switchElection }, 'Switch'));
      }
      act.append(h('button', { class: 'btn btn-sm btn-secondary', onclick: logout }, 'Logout'));
    }
  }

  async function switchElection() {
    try {
      const list = await api('elections', 'list');
      const overlay = h('div', { class: 'overlay' });
      const renderList = (elections) => h('div', { class: 'elist' }, ...elections.map(e =>
        h('div', { class: 'elist-row' },
          h('div', { class: 'elist-main', onclick: async () => {
            await api('elections', 'select', { id: e.id });
            overlay.remove(); pollOnce();
          } },
            h('div', { class: 'elist-name' }, e.name),
            h('div', { class: 'elist-meta' },
              h('span', { class: `pill pill-${e.status}` }, e.status),
              ` · code: ${e.vote_code} · ${e.player_count} players · ${e.code_count} codes`),
          ),
          h('div', { class: 'elist-actions' },
            e.status !== 'archived' ? h('button', {
              class: 'btn btn-sm btn-secondary',
              onclick: (ev) => { ev.stopPropagation(); archiveElectionFromList(e, overlay); },
              title: 'Archive (keep data, mark as done)',
            }, 'Archive') : null,
            h('button', {
              class: 'btn btn-sm btn-danger-outline',
              onclick: (ev) => { ev.stopPropagation(); deleteElectionFromList(e, overlay); },
              title: 'Delete permanently (cannot be undone)',
            }, 'Delete'),
          ),
        )
      ));
      const listEl = renderList(list.elections);
      const m = h('div', { class: 'modal modal-lg' },
        h('h3', {}, 'Switch election'),
        listEl,
        h('div', { class: 'modal-actions' },
          h('button', { class: 'btn btn-secondary', onclick: () => overlay.remove() }, 'Close'),
          h('button', { class: 'btn btn-primary', onclick: () => { overlay.remove(); openCreateElection(); } }, '+ New election'),
        )
      );
      overlay.append(m);
      document.body.append(overlay);
    } catch (e) { toast(e.message, 'error'); }
  }

  function archiveElectionFromList(e, overlay) {
    confirmDialog(`Archive "${e.name}"?`, 'Archived elections stay in the database but coaches can no longer log in to them. You can hard-delete them later if you want them gone entirely.',
      async () => {
        try { await api('elections', 'archive', { id: e.id }); overlay.remove(); switchElection(); toast('Archived.', 'success'); }
        catch (err) { toast(err.message, 'error'); }
      }, 'Archive');
  }

  function deleteElectionFromList(e, overlay) {
    const dlg = h('div', { class: 'overlay' });
    const confirmInput = h('input', { placeholder: e.name, autocomplete: 'off' });
    const submit = async () => {
      try {
        await api('elections', 'delete', { id: e.id, confirm_name: confirmInput.value });
        dlg.remove(); overlay.remove(); switchElection(); toast(`Deleted "${e.name}".`, 'success');
      } catch (err) { toast(err.message, 'error'); }
    };
    dlg.append(h('div', { class: 'modal' },
      h('h3', {}, `Delete "${e.name}"?`),
      h('p', {}, 'This permanently removes the election and all of its players, voter codes, ballots, rounds, and locked roster. ', h('strong', {}, 'This cannot be undone.')),
      h('p', { class: 'muted', style: { marginTop: '8px' } }, 'To confirm, type the election name exactly:'),
      confirmInput,
      h('div', { class: 'modal-actions' },
        h('button', { class: 'btn btn-secondary', onclick: () => dlg.remove() }, 'Cancel'),
        h('button', { class: 'btn btn-danger', onclick: submit }, 'Delete permanently'),
      ),
    ));
    document.body.append(dlg);
    setTimeout(() => confirmInput.focus(), 50);
  }

  // ── Login view ─────────────────────────────────────────────────────────────
  function renderLogin() {
    const wrap = h('div', { class: 'login-wrap' });
    const card = h('div', { class: 'login-card' });
    const tabs = h('div', { class: 'login-tabs' });
    let mode = 'coach';

    const tabCoach = h('button', { class: 'tab active', onclick: () => switchTab('coach') }, '🧢 Coach');
    const tabAdmin = h('button', { class: 'tab', onclick: () => switchTab('admin') }, '🔧 Admin');
    tabs.append(tabCoach, tabAdmin);

    const body = h('div', { class: 'login-body' });

    function switchTab(m) {
      mode = m;
      tabCoach.classList.toggle('active', m === 'coach');
      tabAdmin.classList.toggle('active', m === 'admin');
      drawBody();
    }

    function drawBody() {
      clear(body);
      const err = h('div', { class: 'login-err hidden' });
      if (mode === 'coach') {
        // If the URL has ?e=<code>, the election is pre-selected via shared link.
        const params = new URLSearchParams(window.location.search);
        const presetCode = (params.get('e') || '').trim().toLowerCase();

        const pw  = h('input', { type: 'password', placeholder: 'shared coach password', autocomplete: 'off' });
        const btn = h('button', { class: 'btn btn-primary btn-block' }, 'Enter Vote');

        if (presetCode) {
          btn.onclick = async () => {
            try { err.classList.add('hidden'); await coachLogin(presetCode, pw.value); }
            catch (e) { err.textContent = e.message; err.classList.remove('hidden'); }
          };
          pw.addEventListener('keydown', e => { if (e.key === 'Enter') btn.click(); });
          body.append(
            h('p', { class: 'sub' }, 'Enter the password for this election.'),
            err,
            h('div', { class: 'preset-code' },
              h('span', { class: 'preset-code-label' }, 'Election:'),
              h('span', { class: 'preset-code-value' }, presetCode),
              h('a', { href: '?', class: 'preset-code-change' }, '(change)'),
            ),
            h('label', {}, 'Password'), pw,
            btn,
          );
          setTimeout(() => pw.focus(), 50);
        } else {
          // Fetch active elections and let the user pick one — no auth required.
          const listWrap = h('div', { class: 'election-picker' }, h('div', { class: 'muted' }, 'Loading elections…'));
          api('elections', 'public_list').then(d => {
            clear(listWrap);
            const els = d.elections || [];
            if (!els.length) {
              listWrap.append(h('div', { class: 'muted' }, 'No active elections yet.'));
              return;
            }
            listWrap.append(h('div', { class: 'election-picker-label' }, 'Pick your election:'));
            for (const e of els) {
              listWrap.append(h('button', {
                class: 'btn btn-secondary election-pick-btn',
                onclick: () => { window.location.search = '?e=' + encodeURIComponent(e.vote_code); },
              }, e.name));
            }
          }).catch(() => { clear(listWrap); });

          body.append(
            h('p', { class: 'sub' }, 'Pick your election to continue.'),
            err,
            listWrap,
          );
        }
      } else {
        const pin = h('input', { type: 'password', placeholder: 'Admin PIN' });
        const btn = h('button', { class: 'btn btn-primary btn-block', onclick: async () => {
          try {
            err.classList.add('hidden');
            await adminLogin(pin.value);
          } catch (e) { err.textContent = e.message; err.classList.remove('hidden'); }
        } }, 'Sign in');
        body.append(
          h('p', { class: 'sub' }, 'Sign in to manage elections and rounds.'),
          err,
          h('label', {}, 'Admin PIN'), pin, btn,
        );
        pin.addEventListener('keydown', e => { if (e.key === 'Enter') btn.click(); });
        setTimeout(() => pin.focus(), 50);
      }
    }

    drawBody();
    card.append(
      h('h2', {}, S.leagueName),
      h('p', { class: 'tagline' }, 'Anonymous All-Star voting'),
      tabs,
      body,
    );
    wrap.append(card);
    return wrap;
  }

  // ── Coach view ─────────────────────────────────────────────────────────────
  function renderCoach() {
    const s = S.state || {};
    const root = h('div', { class: 'coach-wrap' });

    if (!s.election) {
      root.append(h('div', { class: 'empty' }, 'No election context.'));
      return root;
    }

    if (s.election.status === 'setup' || !s.round) {
      root.append(
        h('div', { class: 'waiting' },
          h('div', { class: 'spinner' }),
          h('h2', {}, 'Waiting for the next round'),
          h('p', {}, s.election.status === 'setup'
            ? 'The election has not started yet. The admin will activate it shortly.'
            : 'The admin will start the next round shortly.'),
        ),
        (s.players && s.players.length) ? h('div', { class: 'panel' },
          h('h3', { class: 'panel-h3' }, 'Roster'),
          renderReadonlyRoster(s),
        ) : null,
        renderLockedPanel(s, true),
      );
      return root;
    }

    const round = s.round;
    const ballot = s.ballot || { submitted: false, picks: [] };
    const players = s.players || [];
    const lockedIds = new Set((s.locked || []).map(l => l.player_id));

    // Header strip
    const headerBits = [
      h('span', { class: 'round-num' }, `Round ${round.round_num}`),
      h('span', { class: 'sep' }, '·'),
      h('span', {}, `Pick ${round.picks_per_coach}`),
      h('span', { class: 'sep' }, '·'),
      h('span', { class: 'micro' }, `${round.picks_to_lock} will be locked in`),
    ];

    // Finalized: show winners + tie indicator (no raw vote counts for coaches)
    if (round.state === 'finalized') {
      const winnersIds = new Set((round.winners || []).map(Number));
      const tieIds     = new Set((round.tie_player_ids || []).map(Number));
      const result = h('div', { class: 'results-card' },
        h('h2', {}, `Round ${round.round_num} Results`),
        round.has_tie_at_cutoff
          ? h('div', { class: 'tie-note' }, '⚠ Tie at cutoff. The admin may start another round to break it.')
          : null,
        h('h3', {}, 'Locked in this round'),
        h('div', { class: 'winners-list' },
          ...players.filter(p => winnersIds.has(p.id)).map(p => playerChip(p, 'winner')),
          winnersIds.size === 0 ? h('div', { class: 'muted' }, 'No new players locked.') : null,
        ),
        tieIds.size ? h('div', { class: 'tied-block' },
          h('h3', {}, 'Top players tied last round'),
          h('div', { class: 'muted', style: { marginBottom: '6px' } }, 'These players may or may not be the next round\'s top vote-getters.'),
          h('div', { class: 'winners-list' },
            ...players.filter(p => tieIds.has(p.id)).map(p => playerChip(p, 'tied'))
          ),
        ) : null,
      );
      root.append(
        h('div', { class: 'panel' }, h('div', { class: 'round-header' }, ...headerBits), result),
        h('div', { class: 'panel' },
          h('h3', { class: 'panel-h3' }, 'Roster'),
          renderReadonlyRoster(s),
        ),
        renderLockedPanel(s, false),
      );
      return root;
    }

    // Active / all_submitted
    const submittedNow = !!ballot.submitted;
    const isAlternate = round.round_type === 'alternate';

    // Initialize picks state from server-side draft/submitted on first render or round change
    if (S.activePicksRoundId !== round.id) {
      if (isAlternate) {
        S.rankedPicks = (ballot.picks || []).map(Number);
      } else {
        S.selectedPicks = new Set((ballot.picks || []).map(Number));
      }
      S.activePicksRoundId = round.id;
    }

    const grid = h('div', { class: 'ballot-grid' });

    if (isAlternate) {
      // Alternate round: flat list of just the candidate players, with rank badges.
      const candidateIds = new Set((round.candidate_ids || []).map(Number));
      const allP = s.players || [];
      const candPlayers = allP.filter(p => candidateIds.has(p.id));
      // Order: ranked players first (in rank order), then unranked candidates in original sort
      const rankIndex = new Map();
      S.rankedPicks.forEach((id, i) => rankIndex.set(id, i));
      candPlayers.sort((a, b) => {
        const ai = rankIndex.has(a.id) ? rankIndex.get(a.id) : Infinity;
        const bi = rankIndex.has(b.id) ? rankIndex.get(b.id) : Infinity;
        if (ai !== bi) return ai - bi;
        return 0;
      });

      grid.append(h('div', { class: 'ballot-section-h' }, `Candidates (${candPlayers.length})`));
      let n = 0;
      const ord = (i) => {
        const s = ['th','st','nd','rd'], v = i % 100;
        return i + (s[(v-20)%10] || s[v] || s[0]);
      };
      for (const p of candPlayers) {
        n += 1;
        const rankPos = rankIndex.has(p.id) ? rankIndex.get(p.id) + 1 : 0;
        const ranked = rankPos > 0;
        grid.append(h('div', {
          class: `ballot-cell available ${ranked ? 'ranked' : ''} ${submittedNow ? 'submitted' : ''}`,
          onclick: () => {
            if (submittedNow) return;
            const idx = S.rankedPicks.indexOf(p.id);
            if (idx >= 0) {
              S.rankedPicks.splice(idx, 1); // re-shift remaining ranks down
            } else {
              if (S.rankedPicks.length >= round.picks_per_coach) {
                toast(`You can only rank ${round.picks_per_coach}. Tap a ranked player to remove first.`, 'warn');
                return;
              }
              S.rankedPicks.push(p.id);
            }
            saveDraftDebounced(round.id);
            render();
          },
        },
          h('span', { class: 'ballot-cell-num' }, `${n}`),
          h('span', { class: 'ballot-cell-name' }, p.name),
          p.jersey ? h('span', { class: 'ballot-cell-jersey' }, `#${p.jersey}`) : null,
          ranked ? h('span', { class: 'ballot-cell-rank' }, `${ord(rankPos)}`) : null,
        ));
      }
    } else {
      // Regular round: existing sectioned roster grid
      const sections = rosterSections(s);
      let n = 0;
      for (const sec of sections) {
        if (sec.type === 'round') {
          grid.append(h('div', { class: 'ballot-section-h' }, `Round ${sec.rn}`));
          if (!sec.players.length) {
            grid.append(h('div', { class: 'ballot-section-empty' }, 'No players were locked in this round.'));
            continue;
          }
          for (const p of sec.players) {
            n += 1;
            grid.append(h('div', { class: 'ballot-cell locked' },
              h('span', { class: 'ballot-cell-num' }, `${n}`),
              h('span', { class: 'ballot-cell-name' }, p.name),
              p.jersey ? h('span', { class: 'ballot-cell-jersey' }, `#${p.jersey}`) : null,
            ));
          }
        } else { // available section — clickable
          if (!sec.players.length) continue;
          grid.append(h('div', { class: 'ballot-section-h' }, 'Available'));
          for (const p of sec.players) {
            n += 1;
            const checked = S.selectedPicks.has(p.id);
            const tied    = wasTiedLastRound(s, p);
            grid.append(h('div', {
              class: `ballot-cell available ${checked ? 'checked' : ''} ${submittedNow ? 'submitted' : ''}`,
              onclick: () => {
                if (submittedNow) return;
                if (S.selectedPicks.has(p.id)) S.selectedPicks.delete(p.id);
                else {
                  if (S.selectedPicks.size >= round.picks_per_coach) {
                    toast(`You can only pick ${round.picks_per_coach}`, 'warn');
                    return;
                  }
                  S.selectedPicks.add(p.id);
                }
                saveDraftDebounced(round.id);
                render();
              }
            },
              h('span', { class: 'ballot-cell-num' }, `${n}`),
              h('span', { class: 'ballot-cell-name' }, p.name),
              p.jersey ? h('span', { class: 'ballot-cell-jersey' }, `#${p.jersey}`) : null,
              tied    ? h('span', { class: 'ballot-cell-tied' }, '⚖ TIED') : null,
              checked ? h('span', { class: 'ballot-cell-tag check' }, '✓ Picked') : null,
            ));
          }
        }
      }
    }

    // Submit bar
    const pickedCount = isAlternate ? S.rankedPicks.length : S.selectedPicks.size;
    const submitOK = pickedCount === round.picks_per_coach && !submittedNow;
    const verb     = isAlternate ? 'ranked' : 'selected';
    const remaining = round.picks_per_coach - pickedCount;
    const submitBar = h('div', { class: 'submit-bar' },
      h('div', { class: 'pick-counter' },
        submittedNow
          ? h('span', { class: 'ok' }, '✓ Your ballot has been submitted. Waiting for results…')
          : (pickedCount === round.picks_per_coach
              ? `Ready: ${pickedCount} of ${round.picks_per_coach} ${verb}`
              : `${pickedCount} of ${round.picks_per_coach} ${verb} — ${remaining} more to ${isAlternate ? 'rank' : 'pick'}`)
      ),
      !submittedNow ? h('button', {
        class: 'btn btn-primary btn-lg',
        disabled: !submitOK,
        onclick: () => {
          const orderedIds = isAlternate ? [...S.rankedPicks] : Array.from(S.selectedPicks);
          confirmDialog(
            isAlternate ? 'Submit ranked ballot?' : 'Submit ballot?',
            isAlternate
              ? `You are about to submit your ranked picks: ${orderedIds.length} alternates in order. After submitting you cannot change them unless the admin resets your ballot.`
              : `You are about to lock in ${pickedCount} picks. After submitting you cannot change them unless the admin resets your ballot.`,
            async () => {
              try {
                await api('ballot', 'submit', { round_id: round.id, player_ids: orderedIds });
                toast('Ballot submitted.', 'success');
                pollOnce();
              } catch (e) { toast(e.message, 'error'); }
            },
            isAlternate ? 'Submit ranked ballot' : 'Submit ballot'
          );
        }
      }, isAlternate ? 'Submit Ranked Ballot' : 'Submit Ballot') : null,
    );

    root.append(
      h('div', { class: 'panel' },
        h('div', { class: 'round-header' }, ...headerBits),
        submittedNow
          ? h('div', { class: 'banner banner-info' }, '✓ Submitted. The screen will update when the admin finalizes the round.')
          : null,
        grid,
      ),
      submitBar,
      renderLockedPanel(s, false),
    );

    return root;
  }

  let _saveDraftTimer = null;
  function saveDraftDebounced(roundId) {
    if (_saveDraftTimer) clearTimeout(_saveDraftTimer);
    _saveDraftTimer = setTimeout(() => {
      const r = S.state && S.state.round;
      const ids = (r && r.round_type === 'alternate')
        ? [...S.rankedPicks]
        : Array.from(S.selectedPicks);
      api('ballot', 'save_draft', { round_id: roundId, player_ids: ids }).catch(() => {});
    }, 400);
  }

  // Returns the round_num of the most recently finalized round, or null if none.
  function lastFinalizedRoundNum(s) {
    const nums = Object.keys(s.round_tallies || {}).map(Number);
    return nums.length ? Math.max(...nums) : null;
  }

  // Returns true if this (unlocked) player was a tied top-vote-getter in the previous round.
  function wasTiedLastRound(s, p) {
    const rn = lastFinalizedRoundNum(s);
    if (rn == null) return false;
    const ids = (s.round_tied_ids && s.round_tied_ids[rn]) || [];
    return ids.includes(p.id);
  }

  // Sort players: locked first in lock order, then unlocked in original sort_order
  function orderForRoster(s) {
    const players = s.players || [];
    const lockedOrder = new Map();
    (s.locked || []).forEach((l, i) => lockedOrder.set(l.player_id, i));
    return [...players].sort((a, b) => {
      const aL = lockedOrder.has(a.id), bL = lockedOrder.has(b.id);
      if (aL && bL) return lockedOrder.get(a.id) - lockedOrder.get(b.id);
      if (aL) return -1;
      if (bL) return 1;
      return 0;
    });
  }

  // Build a sectioned representation of the roster:
  //   [{type:'round', rn, players}, {type:'round', rn, players}, ..., {type:'available', players}]
  // Includes a section for every finalized round (even when it had zero locks), so the
  // coach can see that a tied/skipped round happened.
  function rosterSections(s) {
    const players = s.players || [];
    const lockedByRn = {};
    for (const l of (s.locked || [])) {
      const rn = Number(l.locked_in_round);
      (lockedByRn[rn] ||= []).push(l.player_id);
    }
    const finalizedNums = Object.keys(s.round_tallies || {}).map(Number);
    const lockedNums    = Object.keys(lockedByRn).map(Number);
    const allRoundNums  = Array.from(new Set([...finalizedNums, ...lockedNums])).sort((a, b) => a - b);
    const sections = [];
    for (const rn of allRoundNums) {
      const ids = lockedByRn[rn] || [];
      const ps  = ids.map(id => players.find(p => p.id === id)).filter(Boolean);
      sections.push({ type: 'round', rn, players: ps });
    }
    const lockedSet = new Set((s.locked || []).map(l => l.player_id));
    const available = players.filter(p => !lockedSet.has(p.id));
    sections.push({ type: 'available', players: available });
    return sections;
  }

  // Read-only ballot-style list used between rounds.
  function renderReadonlyRoster(s) {
    const grid = h('div', { class: 'ballot-grid readonly' });
    const sections = rosterSections(s);
    let n = 0;
    for (const sec of sections) {
      if (sec.type === 'round') {
        grid.append(h('div', { class: 'ballot-section-h' }, `Round ${sec.rn}`));
        if (!sec.players.length) {
          grid.append(h('div', { class: 'ballot-section-empty' }, 'No players were locked in this round.'));
          continue;
        }
        for (const p of sec.players) {
          n += 1;
          const tied = wasTiedLastRound(s, p);
          grid.append(h('div', { class: 'ballot-cell locked' },
            h('span', { class: 'ballot-cell-num' }, `${n}`),
            h('span', { class: 'ballot-cell-name' }, p.name),
            p.jersey ? h('span', { class: 'ballot-cell-jersey' }, `#${p.jersey}`) : null,
            tied ? h('span', { class: 'ballot-cell-tied' }, '⚖ TIED') : null,
          ));
        }
      } else { // available
        if (!sec.players.length) continue;
        grid.append(h('div', { class: 'ballot-section-h' }, 'Available'));
        for (const p of sec.players) {
          n += 1;
          const tied = wasTiedLastRound(s, p);
          grid.append(h('div', { class: 'ballot-cell available' },
            h('span', { class: 'ballot-cell-num' }, `${n}`),
            h('span', { class: 'ballot-cell-name' }, p.name),
            p.jersey ? h('span', { class: 'ballot-cell-jersey' }, `#${p.jersey}`) : null,
            tied ? h('span', { class: 'ballot-cell-tied' }, '⚖ TIED') : null,
          ));
        }
      }
    }
    return grid;
  }

  function playerChip(p, kind = '', count = null) {
    return h('div', { class: `chip chip-${kind}` },
      h('span', { class: 'chip-name' }, p.name),
      p.jersey ? h('span', { class: 'chip-jersey' }, `#${p.jersey}`) : null,
      count != null ? h('span', { class: 'chip-count' }, `${count} ${count === 1 ? 'vote' : 'votes'}`) : null,
    );
  }

  function renderLockedPanel(s, compact, opts = {}) {
    const showCounts = !!opts.showCounts;
    const players = s.players || [];
    const locked  = s.locked || [];
    const mainLocked = locked.filter(l => l.alternate_rank == null);
    const altLocked  = locked.filter(l => l.alternate_rank != null)
                             .slice().sort((a, b) => (a.alternate_rank || 0) - (b.alternate_rank || 0));
    const max     = s.election && s.election.max_roster_size ? s.election.max_roster_size : null;
    const headingMain = (n) => max ? `Locked Roster (${n} / ${max})` : `Locked Roster (${n})`;

    if (!locked.length) return h('div', { class: 'roster-panel' },
      h('h3', {}, headingMain(0)),
      h('div', { class: 'muted' }, 'No players locked in yet.'),
    );

    const tallies = s.round_tallies || {};
    const byRound = {};
    for (const l of mainLocked) (byRound[l.locked_in_round] ||= []).push(l.player_id);
    const mainSections = Object.keys(byRound).sort((a,b)=>a-b).map(rn => {
      const tally = tallies[rn] || {};
      return h('div', { class: 'roster-section' },
        h('h4', {}, `Round ${rn}`),
        h('div', { class: 'chips' },
          ...byRound[rn].map(pid => {
            const p = players.find(x => x.id === pid);
            const c = showCounts ? (tally[pid] ?? null) : null;
            return p ? playerChip(p, 'winner', c) : playerChip({ name: `#${pid}`, jersey: null }, 'winner', c);
          })
        ),
      );
    });

    const ord = (i) => {
      const sf = ['th','st','nd','rd'], v = i % 100;
      return i + (sf[(v-20)%10] || sf[v] || sf[0]);
    };
    const altSection = altLocked.length ? h('div', { class: 'roster-section alt-section' },
      h('h4', {}, `Alternates (${altLocked.length})`),
      h('div', { class: 'chips' },
        ...altLocked.map(l => {
          const p = players.find(x => x.id === l.player_id) || { name: `#${l.player_id}`, jersey: null };
          const c = showCounts ? ((tallies[l.locked_in_round] || {})[l.player_id] ?? null) : null;
          return h('div', { class: 'chip chip-winner chip-alt' },
            h('span', { class: 'chip-alt-rank' }, `${ord(l.alternate_rank)} alt`),
            h('span', { class: 'chip-name' }, p.name),
            p.jersey ? h('span', { class: 'chip-jersey' }, `#${p.jersey}`) : null,
            c != null ? h('span', { class: 'chip-count' }, `${c} ${c === 1 ? 'pt' : 'pts'}`) : null,
          );
        })
      ),
    ) : null;

    return h('div', { class: 'roster-panel' },
      h('h3', {}, headingMain(mainLocked.length)),
      ...mainSections,
      altSection,
    );
  }

  // ── Admin view ─────────────────────────────────────────────────────────────
  function renderAdmin() {
    const s = S.state || {};

    // No election selected → full-width welcome card (skip the 220px-sidebar grid)
    if (!s.election) {
      const wrap = h('div', {});
      wrap.append(renderAdminEmpty());
      return wrap;
    }

    const root = h('div', { class: 'admin-wrap' });

    // Side nav
    const nav = h('aside', { class: 'side-nav' },
      navItem('dashboard', '📊 Dashboard'),
      navItem('codes',     '🎟 Signed In'),
      navItem('setup',     '⚙ Setup (players / rounds)'),
      navItem('results',   '🏆 Results'),
      navItem('overrides', '🛠 Overrides'),
      navItem('audit',     '📜 Audit Log'),
    );

    const content = h('div', { class: 'admin-content' });
    if (S.adminSubview === 'dashboard')      content.append(renderAdminDashboard());
    else if (S.adminSubview === 'codes')     content.append(renderAdminCodes());
    else if (S.adminSubview === 'setup')     content.append(renderAdminSetup());
    else if (S.adminSubview === 'results')   content.append(renderAdminResults());
    else if (S.adminSubview === 'overrides') content.append(renderAdminOverrides());
    else if (S.adminSubview === 'audit')     content.append(renderAdminAudit());

    root.append(nav, content);
    return root;
  }

  function navItem(key, label) {
    return h('button', {
      class: `nav-item ${S.adminSubview === key ? 'active' : ''}`,
      onclick: () => { S.adminSubview = key; render(); },
    }, label);
  }

  function renderAdminEmpty() {
    const wrap = h('div', { class: 'admin-home' });
    wrap.append(h('div', { class: 'page-h' },
      h('h2', {}, 'Elections'),
      h('div', { class: 'page-actions' },
        h('button', { class: 'btn btn-primary', onclick: openCreateElection }, '+ Create New Election'),
      ),
    ));
    const listWrap = h('div', { class: 'admin-elist' }, h('div', { class: 'muted' }, 'Loading…'));
    wrap.append(listWrap);

    api('elections', 'list').then(d => {
      clear(listWrap);
      const els = (d.elections || []).filter(e => e.status !== 'archived');
      if (!els.length) {
        listWrap.append(h('div', { class: 'muted' }, 'No elections yet. Click "+ Create New Election" above to get started.'));
        return;
      }
      for (const e of els) {
        listWrap.append(h('button', {
          class: 'admin-elist-row',
          onclick: async () => {
            try { await api('elections', 'select', { id: e.id }); pollOnce(); }
            catch (err) { toast(err.message, 'error'); }
          },
        },
          h('div', { class: 'admin-elist-name' }, e.name),
          h('div', { class: 'admin-elist-meta' },
            h('span', { class: `pill pill-${e.status}` }, e.status),
            ` · code: ${e.vote_code} · ${e.player_count} players · ${e.code_count} codes`,
          ),
        ));
      }
    }).catch(err => {
      clear(listWrap);
      listWrap.append(h('div', { class: 'banner banner-error' }, err.message));
    });

    return wrap;
  }

  function renderAdminDashboard() {
    const s = S.state;
    const e = s.election;
    const cr = s.current_round;
    const counts = s.counts || {};

    // Reset round-card expand/collapse overrides whenever the focus round
    // changes — either a new higher round_num appears, or the existing
    // highest round transitions state (e.g. active → finalized). This keeps
    // focus on the most recent round in any of those situations, while still
    // letting user clicks expand older rounds for review.
    const rounds = s.rounds || [];
    const maxRn = rounds.length ? Math.max(...rounds.map(r => r.round_num)) : 0;
    const maxRound = rounds.find(r => r.round_num === maxRn);
    const focusKey = maxRound ? `${maxRn}:${maxRound.state}` : '0:none';
    if (focusKey !== S.lastFocusKey) {
      S.roundOverride.clear();
      S.lastFocusKey = focusKey;
      S.lastMaxRoundNum = maxRn;
    }
    // Build through h() so null children are filtered instead of stringified to "null".
    return h('div', {},
      h('div', { class: 'page-h' },
        h('h2', {},
          e.name,
          h('button', {
            class: 'btn btn-sm btn-secondary copy-link',
            title: 'Copy a link coaches can use to skip entering the election code',
            onclick: () => copyCoachLink(e.vote_code),
          }, '🔗 Copy coach link'),
        ),
        h('div', { class: 'page-actions' },
          (() => {
            if (e.status === 'setup') {
              return h('button', { class: 'btn btn-success', onclick: () => activateElection() }, '▶ Activate Election');
            }
            if (e.status !== 'active') return null;
            // Active round in progress → Finalize
            if (cr && (cr.state === 'active' || cr.state === 'all_submitted')) {
              return h('button', {
                class: `btn ${cr.state === 'all_submitted' ? 'btn-success' : 'btn-warning'}`,
                onclick: () => finalizeRound(cr, cr.state !== 'all_submitted'),
              }, cr.state === 'all_submitted' ? `✓ Finalize Round ${cr.round_num}` : `⚠ Force-Finalize Round ${cr.round_num} (only ${counts.submitted}/${counts.signed_in})`);
            }
            // No active round (either no rounds yet or latest is finalized) → Start Next + Finalize All
            return null;
          })(),
          // Between-rounds buttons: start another, start an alternate round, or finalize the election
          e.status === 'active' && (!cr || cr.state === 'finalized') ? h('button', { class: 'btn btn-primary', onclick: () => startNext(cr) }, '▶ Start Next Round') : null,
          e.status === 'active' && (!cr || cr.state === 'finalized') && hasAnyFinalizedRound(s)
            ? h('button', { class: 'btn btn-primary-outline', onclick: () => openAlternateRoundModal() }, '★ Start Alternate Round') : null,
          e.status === 'active' && (!cr || cr.state === 'finalized') ? h('button', { class: 'btn btn-secondary', onclick: completeElection }, '✓ Finalize All Rounds') : null,
        ),
      ),

      // Counter cards
      h('div', { class: 'counters' },
        counterCard('Signed in', counts.signed_in, 'coaches so far'),
        counterCard('Logged in', counts.logged_in, 'in last 30s', 'live'),
        counterCard('Submitted', counts.submitted, counts.signed_in > 0 ? `of ${counts.signed_in} this round` : 'this round', cr && counts.signed_in > 0 && counts.submitted >= counts.signed_in ? 'ready' : null),
        counterCard('Outstanding', counts.outstanding, 'not yet voted', counts.outstanding > 0 ? 'pending' : 'done'),
        counterCard('Roster', `${counts.roster_locked ?? 0} / ${counts.roster_max ?? 0}`, 'locked in', (counts.roster_locked >= counts.roster_max) ? 'done' : 'pending'),
      ),

      // Rounds — every round shown as a collapsible card, newest first.
      // Default: only the most-recent round is expanded; older rounds collapsed.
      // User clicks override per-round; the override is cleared whenever a new round appears.
      (rounds.length === 0)
        ? h('div', { class: 'panel' }, h('div', { class: 'muted' }, 'No round yet — start the next round to open voting.'))
        : h('div', {}, ...rounds.slice().reverse().map(r => renderRoundCard(s, r))),

      // Codes status compact
      h('div', { class: 'panel' },
        h('div', { class: 'panel-h' }, h('h3', {}, 'Signed in')),
        renderCodesGrid(s.codes || []),
      ),

      // Locked roster
      h('div', { class: 'panel' },
        h('div', { class: 'panel-h' }, h('h3', {}, 'Locked Roster')),
        renderLockedPanel({
          players: s.players || [],
          locked: s.locked || [],
          election: s.election,
          round_tallies: s.round_tallies || {},
        }, true, { showCounts: true }),
      ),
    );
  }

  function allRoundsFinalized(s) {
    if (!s || !s.rounds || !s.rounds.length) return false;
    return s.rounds.every(r => r.state === 'finalized');
  }

  // Build a shareable URL like https://host/allstar/?e=majors2026 and copy to clipboard.
  function copyCoachLink(voteCode) {
    const base = window.location.origin + window.location.pathname.replace(/[^/]*$/, '');
    const url  = `${base}?e=${encodeURIComponent(voteCode)}`;
    const fallback = () => {
      const ta = document.createElement('textarea');
      ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.append(ta); ta.select();
      try { document.execCommand('copy'); toast(`Copied: ${url}`, 'success', 5000); }
      catch { toast(url, 'info', 8000); }
      ta.remove();
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url)
        .then(() => toast(`Copied: ${url}`, 'success', 5000))
        .catch(fallback);
    } else {
      fallback();
    }
  }

  // One collapsible card per round on the admin dashboard. Click the header
  // row to toggle. Default: current/active/pending rounds expanded;
  // finalized rounds collapsed (user clicks override the default).
  function renderRoundCard(s, round) {
    const rn = round.round_num;
    const isAlt = round.round_type === 'alternate';
    // Default: only the highest round_num is expanded — focus on the most
    // recent round regardless of whether it's active or freshly finalized.
    const defaultOpen = rn === S.lastMaxRoundNum;
    const isOpen = S.roundOverride.has(rn) ? S.roundOverride.get(rn) : defaultOpen;
    const tally  = (s.round_tallies && s.round_tallies[rn]) || {};
    const tiedSet   = new Set((s.round_tied_ids && s.round_tied_ids[rn]) || []);
    const lockedRows = (s.locked || []).filter(l => l.locked_in_round === rn);
    const lockedSet = new Set(lockedRows.map(l => l.player_id));
    // Map locked-this-round player_id → alternate_rank (for alternate rounds)
    const altRankByPid = new Map(lockedRows.map(l => [l.player_id, l.alternate_rank]));
    const players = s.players || [];

    const sortedIds = Object.keys(tally).map(Number).sort((a, b) => {
      const da = tally[a], db = tally[b];
      if (db !== da) return db - da;
      return a - b;
    });

    const ord = (i) => {
      const sf = ['th','st','nd','rd'], v = i % 100;
      return i + (sf[(v-20)%10] || sf[v] || sf[0]);
    };

    const header = h('div', {
      class: 'round-card-h',
      onclick: () => { S.roundOverride.set(rn, !isOpen); render(); },
    },
      h('span', { class: 'round-card-chevron' }, isOpen ? '▾' : '▸'),
      h('h3', {}, `Round ${rn}`),
      isAlt ? h('span', { class: 'pill pill-alt' }, '★ ALTERNATES') : null,
      h('span', { class: `pill pill-${round.state}` }, round.state.replace('_', ' ')),
      h('span', { class: 'micro' }, isAlt
        ? `Rank ${round.picks_per_coach}, lock ${round.picks_to_lock} alt${round.picks_to_lock === 1 ? '' : 's'}`
        : `Pick ${round.picks_per_coach}, lock ${round.picks_to_lock}`),
      round.has_tie_at_cutoff ? h('span', { class: 'pill pill-warn' }, '⚠ tie at cutoff') : null,
    );

    let body = null;
    if (isOpen) {
      if (round.state === 'pending') {
        body = h('div', { class: 'round-card-body muted' }, 'This round has not started yet.');
      } else if (sortedIds.length === 0) {
        body = h('div', { class: 'round-card-body muted' }, 'No ballots have been tallied yet.');
      } else {
        body = h('div', { class: 'round-card-body' },
          h('table', { class: 'tbl tally' },
            h('thead', {}, h('tr', {},
              h('th', {}, 'Player'),
              h('th', {}, 'Jersey'),
              h('th', {}, isAlt ? 'Borda pts' : 'Votes'),
              h('th', {}, 'Status'),
              h('th', {}, ''),
            )),
            h('tbody', {}, ...sortedIds.map(pid => {
              const p = players.find(pl => pl.id === pid) || { name: `#${pid}`, jersey: null };
              const isLocked = lockedSet.has(pid);
              const isTied   = tiedSet.has(pid);
              const altRank  = altRankByPid.get(pid);
              const statusCell = isLocked
                ? (isAlt && altRank
                    ? h('span', { class: 'pill pill-active' }, `✓ ${ord(altRank)} alternate`)
                    : h('span', { class: 'pill pill-active' }, '✓ locked this round'))
                : (isTied ? h('span', { class: 'pill pill-warn' }, '⚖ tied at cutoff') : null);
              // For alternate rounds, no inline Lock-in button — admin resolves
              // the tie by starting another alternate round between tied players.
              const actionCell = isAlt
                ? null
                : (isTied ? h('button', {
                    class: 'btn btn-sm btn-primary',
                    onclick: (ev) => { ev.stopPropagation(); lockTiedPlayer(p, rn); },
                  }, `Lock in for Round ${rn}`) : null);
              return h('tr', { class: isLocked ? 'row-locked' : (isTied ? 'row-tied' : '') },
                h('td', {}, p.name),
                h('td', {}, p.jersey || ''),
                h('td', {}, String(tally[pid])),
                h('td', {}, statusCell),
                h('td', { class: 'tally-action' }, actionCell),
              );
            }))
          ),
        );
      }
    }

    return h('div', { class: `panel round-card ${isOpen ? 'open' : 'closed'}` }, header, body);
  }

  function lockTiedPlayer(p, roundNum) {
    confirmDialog(
      `Lock in ${p.name}?`,
      `This will add ${p.name} to the locked roster for Round ${roundNum}. ` +
      `It bypasses the tie at cutoff — other tied players from that round will stay unlocked unless you lock them in separately.`,
      async () => {
        try {
          await api('rounds', 'manual_lock', { player_id: p.id, round_num: roundNum });
          pollOnce();
          toast(`${p.name} locked into Round ${roundNum}.`, 'success');
        } catch (e) { toast(e.message, 'error'); }
      },
      `Lock in for Round ${roundNum}`,
    );
  }

  function counterCard(label, value, hint, tone = '') {
    return h('div', { class: `counter-card tone-${tone || 'neutral'}` },
      h('div', { class: 'counter-label' }, label),
      h('div', { class: 'counter-value' }, String(value ?? 0)),
      h('div', { class: 'counter-hint' }, hint),
    );
  }

  function renderCodesGrid(codes) {
    if (!codes.length) return h('div', { class: 'muted' },
      'No coaches have signed in yet. Share the election URL and password with your coaches.',
    );
    return h('div', { class: 'codes-grid' },
      ...codes.map(c => h('div', { class: `code-chip code-${c.status}` },
        h('span', { class: 'code-word' }, c.word),
        h('span', { class: 'code-badge' }, c.status.replace('_', ' ')),
      ))
    );
  }

  // ── Admin: Signed In coaches ──────────────────────────────────────────────
  function renderAdminCodes() {
    const s = S.state;
    const codes = (s.codes || []).slice().sort((a, b) => (+a.word) - (+b.word));
    const cr = s.current_round;
    const wrap = h('div', {});
    wrap.append(h('div', { class: 'page-h' }, h('h2', {}, `Signed In (${codes.length})`)));

    if (!codes.length) {
      wrap.append(h('div', { class: 'panel' }, h('div', { class: 'muted' },
        'No coaches have signed in yet. Share the election URL and password with your coaches.')));
      return wrap;
    }

    const rows = codes.map(c => {
      const presence = c.revoked ? 'revoked'
                     : c.logged_in ? 'active'
                     : 'idle';
      const presenceLabel = { active: '🟢 Active', idle: '⚪ Idle', revoked: '🔴 Revoked' }[presence];
      const submittedCell = c.revoked
        ? h('span', { class: 'muted' }, '—')
        : c.submitted
          ? h('span', { class: 'tag-yes' }, '✓ Yes')
          : h('span', { class: 'muted' }, '— Not yet');
      const lastSeenCell = h('span', { class: c.logged_in ? 'tag-fresh' : 'muted' }, relativeTime(c.last_seen_at));
      const actionCell = c.revoked
        ? h('button', { class: 'btn btn-sm btn-secondary', onclick: () => unrevokeCode(c.id) }, 'Un-revoke')
        : h('button', { class: 'btn btn-sm btn-danger-outline', onclick: () => revokeCode(c.id, c.word) }, 'Revoke');
      return h('tr', { class: `row-${presence}` },
        h('td', { class: 'col-num' }, `#${c.word}`),
        h('td', {}, presenceLabel),
        h('td', {}, submittedCell),
        h('td', {}, lastSeenCell),
        h('td', { class: 'col-action' }, actionCell),
      );
    });

    wrap.append(h('div', { class: 'panel' },
      h('table', { class: 'tbl signed-in-tbl' },
        h('thead', {}, h('tr', {},
          h('th', { class: 'col-num' }, 'Coach'),
          h('th', {}, 'Status'),
          h('th', {}, cr ? `Submitted Round ${cr.round_num}` : 'Submitted'),
          h('th', {}, 'Last seen'),
          h('th', { class: 'col-action' }, ''),
        )),
        h('tbody', {}, ...rows),
      )
    ));
    return wrap;
  }

  // Relative time: "12s ago", "5m ago", "2h ago", "—" if null. Compares against now() each render.
  function relativeTime(ts) {
    if (!ts) return '—';
    const t = typeof ts === 'string' ? new Date(ts.replace(' ', 'T') + 'Z').getTime() : new Date(ts).getTime();
    if (!t || isNaN(t)) return '—';
    const sec = Math.max(0, Math.floor((Date.now() - t) / 1000));
    if (sec < 5)    return 'just now';
    if (sec < 60)   return `${sec}s ago`;
    if (sec < 3600) return `${Math.floor(sec / 60)}m ago`;
    if (sec < 86400) return `${Math.floor(sec / 3600)}h ago`;
    return `${Math.floor(sec / 86400)}d ago`;
  }

  function revokeCode(id, word) {
    confirmDialog('Revoke this coach?', `Revoking Coach #${word} will sign them out immediately and prevent them from submitting in any round.`,
      async () => { try { await api('codes', 'revoke', { voter_code_id: id }); pollOnce(); } catch (e) { toast(e.message, 'error'); } },
      'Revoke', true);
  }
  async function unrevokeCode(id) {
    try { await api('codes', 'unrevoke', { voter_code_id: id }); pollOnce(); } catch (e) { toast(e.message, 'error'); }
  }

  // ── Admin: Setup (players + rounds editor) ─────────────────────────────────
  function renderAdminSetup() {
    const s = S.state;
    const e = s.election;
    const inSetup = e.status === 'setup';
    const wrap = h('div', {});

    wrap.append(
      h('div', { class: 'page-h' },
        h('h2', {}, 'Setup'),
        h('div', { class: 'page-actions' },
          inSetup ? h('span', { class: 'pill pill-setup' }, 'In setup — editable')
                  : h('span', { class: 'pill pill-active' }, 'Active — limited edits'),
        ),
      ),

      // Election meta
      h('div', { class: 'panel' },
        h('div', { class: 'panel-h' }, h('h3', {}, 'Election')),
        renderElectionMeta(e),
      ),

      // Players
      h('div', { class: 'panel' },
        h('div', { class: 'panel-h' },
          h('h3', {}, `Players (${(s.players || []).filter(p => p.active).length})`),
          h('button', { class: 'btn btn-sm btn-primary', onclick: addPlayerPrompt }, '+ Add player'),
        ),
        renderPlayersTable(s.players || []),
        inSetup ? h('div', { class: 'help' },
          h('button', { class: 'btn btn-secondary', onclick: bulkAddPlayers }, '⇪ Bulk paste roster…'),
        ) : null,
      ),

      // Rounds (history) — populated dynamically as rounds run
      h('div', { class: 'panel' },
        h('div', { class: 'panel-h' }, h('h3', {}, 'Rounds')),
        (s.rounds && s.rounds.length)
          ? renderRoundsTable(s.rounds, inSetup)
          : h('div', { class: 'muted' }, inSetup
              ? 'No rounds yet. Activate the election, then start the first round when you\'re ready.'
              : 'No rounds yet. Click "Start Next Round" above.'),
      ),
    );

    return wrap;
  }

  function renderElectionMeta(e) {
    const nameIn = h('input', { value: e.name });
    const maxIn  = h('input', { type: 'number', min: 1, max: 100, value: e.max_roster_size ?? 12 });
    // Show the current coach password as plaintext. If the stored value is a
    // legacy bcrypt hash, blank the field and prompt to set a new one.
    const stored = (e.coach_password || '').toString();
    const isLegacyHash = /^\$2[aby]\$/.test(stored);
    const pwIn   = h('input', { type: 'text', autocomplete: 'off',
                                placeholder: isLegacyHash ? 'set a new password (current is a legacy hash)' : '',
                                value: isLegacyHash ? '' : stored });
    return h('div', { class: 'form-grid' },
      h('label', {}, 'Name'), nameIn,
      h('label', {}, 'Vote code'), h('input', { value: e.vote_code, disabled: true }),
      h('label', {}, 'Max roster size'), maxIn,
      h('label', {}, 'Coach password'), pwIn,
      h('div', {}, ''),
      h('button', { class: 'btn btn-primary btn-sm', onclick: async () => {
        try {
          const body = {
            id: e.id,
            name: nameIn.value,
            max_roster_size: parseInt(maxIn.value, 10) || 12,
          };
          // Send the password only if changed (and non-empty)
          if (pwIn.value && pwIn.value !== stored) body.coach_password = pwIn.value;
          await api('elections', 'update', body);
          toast('Saved.', 'success'); pollOnce();
        } catch (e) { toast(e.message, 'error'); }
      } }, 'Save'),
    );
  }

  function renderPlayersTable(players) {
    if (!players.length) return h('div', { class: 'muted' }, 'No players yet.');
    return h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {},
        h('th', {}, '#'), h('th', {}, 'Name'), h('th', {}, 'Jersey'), h('th', {}, ''),
      )),
      h('tbody', {},
        ...players.map((p, i) => h('tr', { class: p.active ? '' : 'inactive' },
          h('td', {}, String(i + 1)),
          h('td', {}, p.name + (p.active ? '' : ' (inactive)')),
          h('td', {}, p.jersey || ''),
          h('td', {},
            h('button', { class: 'btn btn-sm btn-secondary', onclick: () => editPlayerPrompt(p) }, 'Edit'),
            ' ',
            h('button', { class: 'btn btn-sm btn-danger-outline', onclick: () => removePlayer(p) }, 'Remove'),
          ),
        ))
      )
    );
  }

  function addPlayerPrompt() {
    const overlay = h('div', { class: 'overlay' });
    const name = h('input', { placeholder: 'Player name' });
    const jersey = h('input', { placeholder: 'Jersey (optional)' });
    overlay.append(h('div', { class: 'modal' },
      h('h3', {}, 'Add player'),
      h('label', {}, 'Name'), name,
      h('label', {}, 'Jersey'), jersey,
      h('div', { class: 'modal-actions' },
        h('button', { class: 'btn btn-secondary', onclick: () => overlay.remove() }, 'Cancel'),
        h('button', { class: 'btn btn-primary', onclick: async () => {
          if (!name.value.trim()) { name.focus(); return; }
          try { await api('players', 'add', { name: name.value.trim(), jersey: jersey.value.trim() || null }); overlay.remove(); pollOnce(); }
          catch (e) { toast(e.message, 'error'); }
        } }, 'Add'),
      )
    ));
    document.body.append(overlay);
    setTimeout(() => name.focus(), 50);
  }

  function editPlayerPrompt(p) {
    const overlay = h('div', { class: 'overlay' });
    const name = h('input', { value: p.name });
    const jersey = h('input', { value: p.jersey || '' });
    overlay.append(h('div', { class: 'modal' },
      h('h3', {}, 'Edit player'),
      h('label', {}, 'Name'), name,
      h('label', {}, 'Jersey'), jersey,
      h('div', { class: 'modal-actions' },
        h('button', { class: 'btn btn-secondary', onclick: () => overlay.remove() }, 'Cancel'),
        h('button', { class: 'btn btn-primary', onclick: async () => {
          try { await api('players', 'edit', { id: p.id, name: name.value.trim(), jersey: jersey.value.trim() || null }); overlay.remove(); pollOnce(); }
          catch (e) { toast(e.message, 'error'); }
        } }, 'Save'),
      )
    ));
    document.body.append(overlay);
  }

  function removePlayer(p) {
    confirmDialog('Remove player?', `Remove "${p.name}"?`, async () => {
      try { await api('players', 'remove', { id: p.id }); pollOnce(); } catch (e) { toast(e.message, 'error'); }
    }, 'Remove', true);
  }

  function bulkAddPlayers() {
    const overlay = h('div', { class: 'overlay' });
    const ta = h('textarea', { rows: 12, placeholder: 'One player per line.\nOptionally: "Name, Jersey"\ne.g.\nLuca Romero, 7\nMason Park, 12' });
    overlay.append(h('div', { class: 'modal modal-lg' },
      h('h3', {}, 'Bulk roster paste'),
      h('p', {}, 'Replaces the entire roster. Only available while the election is in setup.'),
      ta,
      h('div', { class: 'modal-actions' },
        h('button', { class: 'btn btn-secondary', onclick: () => overlay.remove() }, 'Cancel'),
        h('button', { class: 'btn btn-primary', onclick: async () => {
          const players = ta.value.split('\n').map(l => l.trim()).filter(Boolean).map(l => {
            const parts = l.split(',').map(s => s.trim());
            return { name: parts[0], jersey: parts[1] || null };
          });
          try { await api('players', 'bulk_set', { players }); overlay.remove(); pollOnce(); toast(`Saved ${players.length}.`, 'success'); }
          catch (e) { toast(e.message, 'error'); }
        } }, 'Replace roster'),
      )
    ));
    document.body.append(overlay);
    setTimeout(() => ta.focus(), 50);
  }

  function renderRoundsTable(rounds, editable) {
    if (!rounds.length) return h('div', { class: 'muted' }, 'No rounds.');
    return h('table', { class: 'tbl' },
      h('thead', {}, h('tr', {},
        h('th', {}, '#'), h('th', {}, 'Pick'), h('th', {}, 'Lock'), h('th', {}, 'State'),
      )),
      h('tbody', {}, ...rounds.map(r => h('tr', {},
        h('td', {}, String(r.round_num)),
        h('td', {}, String(r.picks_per_coach)),
        h('td', {}, String(r.picks_to_lock)),
        h('td', {}, h('span', { class: `pill pill-${r.state}` }, r.state.replace('_', ' '))),
      )))
    );
  }

  // ── Admin: Results ────────────────────────────────────────────────────────
  function renderAdminResults() {
    const wrap = h('div', {});
    const rounds = (S.state.rounds || []);
    const finalRounds = rounds.filter(r => r.state === 'finalized' || r.state === 'all_submitted' || r.state === 'active');
    if (!finalRounds.length) {
      wrap.append(h('div', { class: 'page-h' }, h('h2', {}, 'Results')));
      wrap.append(h('div', { class: 'panel' }, h('div', { class: 'muted' }, 'No rounds have run yet.')));
      return wrap;
    }
    wrap.append(h('div', { class: 'page-h' }, h('h2', {}, 'Results')));
    for (const r of finalRounds) {
      const block = h('div', { class: 'panel' });
      block.append(h('div', { class: 'panel-h' },
        h('h3', {}, `Round ${r.round_num}`),
        h('span', { class: `pill pill-${r.state}` }, r.state.replace('_', ' ')),
        r.has_tie_at_cutoff ? h('span', { class: 'pill pill-warn' }, '⚠ tie at cutoff') : null,
      ));
      const body = h('div', { class: 'tally-body' }, h('div', { class: 'muted' }, 'Loading…'));
      api('results', 'full', null, { query: `round_id=${r.id}` }).then(data => {
        clear(body);
        body.append(renderTallyTable(data, r));
      }).catch(e => { clear(body); body.append(h('div', { class: 'banner banner-error' }, e.message)); });
      block.append(body);
      wrap.append(block);
    }
    return wrap;
  }

  function renderTallyTable(data, r) {
    const tally = data.tally || [];
    const ptl = (data.round && data.round.picks_to_lock) || r.picks_to_lock;
    const tieIds = new Set((data.round && data.round.tie_player_ids) || []);
    const lockedSet = new Set(tally.filter(t => t.locked_in_round === r.round_num).map(t => t.player_id));
    return h('div', {},
      h('div', { class: 'tally-summary' }, `${data.submitted} of ${data.expected} ballots cast · pick ${data.round.picks_per_coach}, lock ${ptl}`),
      h('table', { class: 'tbl tally' },
        h('thead', {}, h('tr', {},
          h('th', {}, '#'), h('th', {}, 'Player'), h('th', {}, 'Jersey'), h('th', {}, 'Votes'), h('th', {}, 'Status'),
        )),
        h('tbody', {}, ...tally.map((t, i) => h('tr', {
          class: `${lockedSet.has(t.player_id) ? 'row-locked' : ''} ${tieIds.has(t.player_id) ? 'row-tied' : ''} ${t.locked_in_round != null && t.locked_in_round !== r.round_num ? 'row-prior-locked' : ''}`
        },
          h('td', {}, String(i + 1)),
          h('td', {}, t.name),
          h('td', {}, t.jersey || ''),
          h('td', {}, h('span', { class: 'votes' }, String(t.votes))),
          h('td', {},
            lockedSet.has(t.player_id) ? h('span', { class: 'pill pill-active' }, '✓ locked this round') :
            tieIds.has(t.player_id)    ? h('span', { class: 'pill pill-warn' }, '⚖ tied') :
            t.locked_in_round != null  ? h('span', { class: 'pill pill-prior' }, `locked R${t.locked_in_round}`) :
            (i < ptl                    ? h('span', { class: 'pill pill-secondary' }, 'top-N (pending)') : ''),
          ),
        ))),
      ),
      r.state === 'finalized' ? h('div', { class: 'tally-actions' },
        h('button', { class: 'btn btn-secondary btn-sm', onclick: () => editResultsPrompt(r, tally, ptl) }, '✏ Edit locked players (override)'),
      ) : null,
    );
  }

  function editResultsPrompt(r, tally, ptl) {
    const overlay = h('div', { class: 'overlay' });
    const selected = new Set(tally.filter(t => t.locked_in_round === r.round_num).map(t => t.player_id));
    const list = h('div', { class: 'override-list' },
      ...tally.filter(t => t.locked_in_round == null || t.locked_in_round === r.round_num).map(t => {
        const cb = h('input', { type: 'checkbox' });
        cb.checked = selected.has(t.player_id);
        cb.addEventListener('change', () => { if (cb.checked) selected.add(t.player_id); else selected.delete(t.player_id); });
        return h('label', { class: 'override-row' }, cb,
          h('span', {}, t.name), h('span', { class: 'micro' }, `${t.votes} votes`));
      })
    );
    overlay.append(h('div', { class: 'modal modal-lg' },
      h('h3', {}, `Override locked players — Round ${r.round_num}`),
      h('p', { class: 'micro' }, `Select up to ${ptl} players (or any number you choose for override resolution).`),
      list,
      h('div', { class: 'modal-actions' },
        h('button', { class: 'btn btn-secondary', onclick: () => overlay.remove() }, 'Cancel'),
        h('button', { class: 'btn btn-primary', onclick: async () => {
          try { await api('rounds', 'edit_results', { round_id: r.id, locked_player_ids: Array.from(selected) }); overlay.remove(); pollOnce(); toast('Updated.', 'success'); }
          catch (e) { toast(e.message, 'error'); }
        } }, 'Save'),
      )
    ));
    document.body.append(overlay);
  }

  // ── Admin: Overrides ──────────────────────────────────────────────────────
  function renderAdminOverrides() {
    const s = S.state;
    const cr = s.current_round;
    const wrap = h('div', {});
    wrap.append(h('div', { class: 'page-h' }, h('h2', {}, 'Overrides')));

    // Reset ballots
    wrap.append(h('div', { class: 'panel' },
      h('div', { class: 'panel-h' }, h('h3', {}, 'Reset a coach\'s ballot')),
      cr && (cr.state === 'active' || cr.state === 'all_submitted')
        ? h('div', { class: 'override-list' }, ...(s.codes || []).filter(c => c.submitted).map(c =>
            h('div', { class: 'override-row' },
              h('span', {}, c.word),
              h('span', { class: 'pill pill-success' }, 'submitted'),
              h('button', { class: 'btn btn-sm btn-danger-outline', onclick: () => resetBallot(cr.id, c.id, c.word) }, 'Reset'),
            )))
        : h('div', { class: 'muted' }, 'No active round, or no submitted ballots to reset.'),
    ));

    // Manual lock/unlock
    wrap.append(h('div', { class: 'panel' },
      h('div', { class: 'panel-h' }, h('h3', {}, 'Manual lock / unlock')),
      h('div', { class: 'override-list' }, ...(s.players || []).filter(p => p.active).map(p => {
        const isLocked = (s.locked || []).some(l => l.player_id === p.id);
        return h('div', { class: 'override-row' },
          h('span', {}, p.name + (p.jersey ? ` (#${p.jersey})` : '')),
          isLocked ? h('span', { class: 'pill pill-active' }, '✓ locked') : null,
          isLocked
            ? h('button', { class: 'btn btn-sm btn-secondary', onclick: () => manualUnlock(p) }, 'Unlock')
            : h('button', { class: 'btn btn-sm btn-primary', onclick: () => manualLock(p) }, 'Lock in'),
        );
      })),
    ));

    return wrap;
  }

  function resetBallot(roundId, vid, word) {
    confirmDialog('Reset ballot?', `This will delete the submitted ballot for "${word}" so they can re-submit. The action is logged.`,
      async () => { try { await api('rounds', 'reset_ballot', { round_id: roundId, voter_code_id: vid }); pollOnce(); toast('Reset.', 'success'); } catch (e) { toast(e.message, 'error'); } },
      'Reset', true);
  }
  function manualLock(p) {
    confirmDialog('Manually lock player?', `Lock "${p.name}" into the roster, bypassing vote results?`,
      async () => { try { await api('rounds', 'manual_lock', { player_id: p.id }); pollOnce(); } catch (e) { toast(e.message, 'error'); } });
  }
  function manualUnlock(p) {
    confirmDialog('Unlock player?', `Remove "${p.name}" from the locked roster?`,
      async () => { try { await api('rounds', 'manual_unlock', { player_id: p.id }); pollOnce(); } catch (e) { toast(e.message, 'error'); } },
      'Unlock', true);
  }

  // ── Admin: Audit log (lightweight) ────────────────────────────────────────
  function renderAdminAudit() {
    const wrap = h('div', {});
    wrap.append(h('div', { class: 'page-h' }, h('h2', {}, 'Audit Log')));
    wrap.append(h('div', { class: 'panel' }, h('div', { class: 'muted' },
      'Audit entries are written to the database table ', h('code', {}, 'audit_log'), '. ',
      'For now, use ', h('code', {}, 'SELECT * FROM audit_log WHERE election_id=… ORDER BY created_at DESC'), ' from the DB.'
    )));
    return wrap;
  }

  // ── Admin: Election lifecycle actions ─────────────────────────────────────
  async function activateElection() {
    try { await api('elections', 'activate', { id: S.state.election.id }); pollOnce(); toast('Activated.', 'success'); }
    catch (e) { toast(e.message, 'error'); }
  }
  function startNext(prevRound) {
    const nextRn = (prevRound && prevRound.round_num ? prevRound.round_num : 0) + 1;
    const defPpc = prevRound && prevRound.picks_per_coach ? prevRound.picks_per_coach : 4;
    const defPtl = prevRound && prevRound.picks_to_lock   ? prevRound.picks_to_lock   : 2;
    const overlay = h('div', { class: 'overlay' });
    const ppc = h('input', { type: 'number', min: 1, value: defPpc });
    const ptl = h('input', { type: 'number', min: 1, value: defPtl });
    const submit = async () => {
      const a = parseInt(ppc.value, 10), b = parseInt(ptl.value, 10);
      if (!(a >= 1) || !(b >= 1) || b > a) { toast('Lock must be ≥1 and ≤ Pick', 'error'); return; }
      try {
        const r = await api('rounds', 'start_next', { picks_per_coach: a, picks_to_lock: b });
        overlay.remove();
        pollOnce();
        toast(`Round ${r.round_num} started.`, 'success');
      } catch (e) { toast(e.message, 'error'); }
    };
    overlay.append(h('div', { class: 'modal' },
      h('h3', {}, `Start Round ${nextRn}`),
      h('p', { class: 'muted' }, prevRound
        ? 'Defaults below match the previous round — adjust as needed.'
        : 'Set how many picks each coach makes and how many of them will be locked in.'),
      h('div', { class: 'form-grid' },
        h('label', {}, 'Picks per coach'), ppc,
        h('label', {}, 'Picks to lock'),   ptl,
      ),
      h('div', { class: 'modal-actions' },
        h('button', { class: 'btn btn-secondary', onclick: () => overlay.remove() }, 'Cancel'),
        h('button', { class: 'btn btn-primary', onclick: submit }, `▶ Start Round ${nextRn}`),
      )
    ));
    document.body.append(overlay);
    setTimeout(() => ppc.focus(), 50);
  }
  function finalizeRound(cr, force) {
    confirmDialog(`Finalize Round ${cr.round_num}?`,
      force ? `Not all coaches have voted in Round ${cr.round_num}. Finalizing now will tally the submitted ballots only and lock the top players from this round into the roster. The election itself stays open — you can still start more rounds.`
            : `Tally Round ${cr.round_num}'s votes and lock the top players into the roster. The election itself stays open — you can still start more rounds.`,
      async () => {
        try {
          const r = await api('rounds', 'finalize', { round_id: cr.id, override: !!force });
          pollOnce();
          if (r.tie) toast(`Round ${cr.round_num} finalized with a tie at cutoff — start another round if you want to break it.`, 'warn', 6000);
          else      toast(`Round ${cr.round_num} finalized.`, 'success');
        } catch (e) { toast(e.message, 'error'); }
      }, force ? `Force-Finalize Round ${cr.round_num}` : `Finalize Round ${cr.round_num}`, !!force);
  }
  function completeElection() {
    confirmDialog('Finalize all rounds?', 'Mark this election as complete. Coaches will keep their access but no new rounds can be started.',
      async () => { try { await api('rounds', 'complete_election', {}); pollOnce(); } catch (e) { toast(e.message, 'error'); } });
  }

  function hasAnyFinalizedRound(s) {
    return (s.rounds || []).some(r => r.state === 'finalized');
  }

  // ── Admin: Start Alternate Round modal ─────────────────────────────────────
  async function openAlternateRoundModal() {
    let data;
    try { data = await api('rounds', 'alternate_candidates'); }
    catch (e) { toast(e.message, 'error'); return; }

    const allActive  = data.all_active || [];
    const priorVotes = new Set((data.with_prior_votes || []).map(Number));
    const priorTie   = new Set((data.prior_tie || []).map(Number));
    const isTieResolution = priorTie.size > 0;
    const defaultsChecked = isTieResolution ? priorTie : priorVotes;
    const defaultSlots = data.suggested_slots > 0
      ? data.suggested_slots
      : Math.min(3, allActive.length);

    const overlay = h('div', { class: 'overlay' });
    const slotsIn   = h('input', { type: 'number', min: 1, value: defaultSlots });
    const ppcIn     = h('input', { type: 'number', min: 1, value: defaultSlots });
    // Default ppc to match slots; if admin bumps slots later, follow it unless they've manually changed ppc
    let ppcTouched = false;
    ppcIn.addEventListener('input', () => { ppcTouched = true; });
    slotsIn.addEventListener('input', () => {
      const v = parseInt(slotsIn.value, 10);
      if (!ppcTouched && v >= 1) ppcIn.value = v;
    });
    const candList = h('div', { class: 'alt-cand-list' });
    const selectedCountEl = h('span', { class: 'alt-cand-count' }, '');
    const selected = new Set();
    for (const p of allActive) if (defaultsChecked.has(p.id)) selected.add(p.id);

    // Players that got votes in any prior round — always visually marked so they
    // stay distinguishable from other players the admin later adds.
    const priorMarker = isTieResolution ? priorTie : priorVotes;

    function refreshSelectedCount() {
      selectedCountEl.textContent = `${selected.size} of ${allActive.length} selected`;
    }

    function redrawCandList() {
      clear(candList);
      for (const p of allActive) {
        const checked = selected.has(p.id);
        const isPrior = priorMarker.has(p.id);
        const cls = ['alt-cand-row'];
        if (checked) cls.push('checked');
        if (isPrior) cls.push('prior');
        const row = h('label', { class: cls.join(' ') },
          h('input', {
            type: 'checkbox',
            onchange: (ev) => {
              if (ev.target.checked) selected.add(p.id); else selected.delete(p.id);
              redrawCandList();
              refreshSelectedCount();
            },
          }),
          h('span', { class: 'alt-cand-name' }, p.name),
          p.jersey ? h('span', { class: 'alt-cand-jersey' }, `#${p.jersey}`) : null,
          isPrior ? h('span', { class: 'alt-cand-prior-badge' },
            isTieResolution ? '⚖ tied last round' : '★ prior votes') : null,
        );
        if (checked) row.querySelector('input').checked = true;
        candList.append(row);
      }
    }
    redrawCandList();
    refreshSelectedCount();

    const selectAllBtn = h('button', {
      class: 'btn btn-sm btn-secondary',
      onclick: (ev) => {
        ev.preventDefault();
        if (selected.size === allActive.length) {
          selected.clear();
        } else {
          for (const p of allActive) selected.add(p.id);
        }
        redrawCandList();
        refreshSelectedCount();
      },
    }, 'Select all / none');

    const selectPriorBtn = priorMarker.size ? h('button', {
      class: 'btn btn-sm btn-secondary',
      onclick: (ev) => {
        ev.preventDefault();
        selected.clear();
        for (const p of allActive) if (priorMarker.has(p.id)) selected.add(p.id);
        redrawCandList();
        refreshSelectedCount();
      },
    }, isTieResolution ? 'Just tied players' : 'Just prior vote-getters') : null;

    const submit = async () => {
      const slots = parseInt(slotsIn.value, 10);
      const ppc   = parseInt(ppcIn.value, 10);
      if (!(slots >= 1)) { toast('Alternates to lock must be ≥ 1', 'error'); return; }
      if (!(ppc >= 1))   { toast('Picks per coach must be ≥ 1', 'error'); return; }
      if (slots > ppc)   { toast(`Can't lock more alternates (${slots}) than each coach ranks (${ppc})`, 'error'); return; }
      const candidate_ids = [...selected];
      if (candidate_ids.length < 2) { toast('Pick at least 2 candidates', 'error'); return; }
      if (ppc > candidate_ids.length) { toast(`Coaches can't rank ${ppc} players from only ${candidate_ids.length} candidates`, 'error'); return; }
      try {
        const r = await api('rounds', 'start_alternate', {
          picks_per_coach: ppc,
          picks_to_lock: slots,
          candidate_ids,
        });
        overlay.remove();
        pollOnce();
        toast(`Alternate round ${r.round_num} started.`, 'success');
      } catch (e) { toast(e.message, 'error'); }
    };

    overlay.append(h('div', { class: 'modal modal-lg' },
      h('h3', {}, isTieResolution ? 'Resolve alternate tie' : 'Start alternate round'),
      h('p', { class: 'muted' }, isTieResolution
        ? `The previous alternate round had a tie at cutoff. Coaches will re-rank the tied players to break it — you can also add other candidates if you want.`
        : `Coaches will rank the chosen candidates. The top players by Borda count become alternates in that order.`),
      h('div', { class: 'form-grid' },
        h('label', {}, 'Picks per coach'), ppcIn,
        h('label', {}, 'Alternates to lock in'), slotsIn,
      ),
      h('p', { class: 'micro' }, 'Each coach ranks "Picks per coach" players. The top "Alternates to lock in" by Borda count become alternates in that order. Picks per coach ≥ Alternates to lock in.'),
      h('div', { class: 'sep' }, `Candidates (${allActive.length} available)`),
      h('p', { class: 'muted', style: { marginTop: '4px' } },
        isTieResolution
          ? 'Tied players from the previous alternate round are pre-checked.'
          : 'Players who got votes in any finalized round are pre-checked. Add others as needed.'),
      h('div', { class: 'alt-cand-toolbar' },
        selectAllBtn,
        selectPriorBtn,
        selectedCountEl,
      ),
      candList,
      h('div', { class: 'modal-actions' },
        h('button', { class: 'btn btn-secondary', onclick: () => overlay.remove() }, 'Cancel'),
        h('button', { class: 'btn btn-primary', onclick: submit }, '★ Start Alternate Round'),
      ),
    ));
    document.body.append(overlay);
  }

  // ── Create election wizard ────────────────────────────────────────────────
  function openCreateElection() {
    const overlay = h('div', { class: 'overlay' });
    const name = h('input', { placeholder: 'e.g., Majors International' });
    const code = h('input', { placeholder: 'e.g., majors2026', autocomplete: 'off', autocapitalize: 'off' });
    const maxRoster = h('input', { type: 'number', min: 1, max: 100, value: 12 });
    const pw   = h('input', { type: 'text', placeholder: 'shared password for all coaches', autocomplete: 'off' });
    const players = h('textarea', { rows: 8, placeholder: 'Optional: one player per line ("Name, Jersey")' });

    overlay.append(h('div', { class: 'modal modal-lg' },
      h('h3', {}, '+ New election'),
      h('div', { class: 'form-grid' },
        h('label', {}, 'Name'), name,
        h('label', {}, 'Vote code'), code,
        h('label', {}, 'Max roster size'), maxRoster,
        h('label', {}, 'Coach password'), pw,
      ),
      h('p', { class: 'muted', style: { marginTop: '10px' } },
        'All coaches use the same Coach password to sign in. Each device that signs in gets the next available Coach number (1, 2, 3, …). Rounds are configured one at a time as you click "Start Next Round".'),
      h('div', { class: 'sep' }, 'Players (optional — can add later)'),
      players,
      h('div', { class: 'modal-actions' },
        h('button', { class: 'btn btn-secondary', onclick: () => overlay.remove() }, 'Cancel'),
        h('button', { class: 'btn btn-primary', onclick: async () => {
          const playerList = players.value.split('\n').map(l => l.trim()).filter(Boolean).map(l => {
            const parts = l.split(',').map(s => s.trim());
            return { name: parts[0], jersey: parts[1] || null };
          });
          try {
            await api('elections', 'create', {
              name: name.value.trim(),
              vote_code: code.value.trim().toLowerCase(),
              max_roster_size: parseInt(maxRoster.value, 10) || 12,
              coach_password: pw.value,
              players: playerList,
            });
            overlay.remove();
            toast('Election created.', 'success');
            pollOnce();
          } catch (e) { toast(e.message, 'error'); }
        } }, 'Create'),
      )
    ));
    document.body.append(overlay);
    setTimeout(() => name.focus(), 50);
  }

  // ── Boot ───────────────────────────────────────────────────────────────────
  init();
})();
