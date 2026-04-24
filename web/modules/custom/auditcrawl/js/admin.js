/**
 * AuditCrawlUI — branded toast + confirm + AJAX wiring.
 *
 * Same shape as the WordPress plugin's admin.js (toast, modal,
 * delegation) so the two plugins feel identical to use. Differences:
 *   - Reads config from drupalSettings.auditcrawl rather than WP's
 *     localized PHP constants.
 *   - Fetches Drupal's CSRF token from /session/token at first use.
 *   - Uses Drupal.behaviors + once() so handlers bind exactly once
 *     even when Drupal's AJAX rebuilds the page fragment.
 */
(function (Drupal, once) {

  // ─── CSRF ────────────────────────────────────────────────────
  // Drupal's URL generator already appends a per-route CSRF token to
  // URLs for routes with `_csrf_token: TRUE`. Our endpoints come
  // pre-tokenized via drupalSettings.auditcrawl.endpoints (emitted by
  // hook_page_attachments), so we POST to them as-is — adding our
  // own token here caused `?token=A&token=B` and a 403.

  // ─── Toast ───────────────────────────────────────────────────
  function ensureToastHost() {
    let host = document.getElementById('auditcrawl-toast-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'auditcrawl-toast-host';
      document.body.appendChild(host);
    }
    return host;
  }
  function toast(message, type) {
    const host = ensureToastHost();
    const t = document.createElement('div');
    t.className = 'auditcrawl-toast auditcrawl-toast--' + (type || 'info');
    t.textContent = message;
    host.appendChild(t);
    requestAnimationFrame(() => t.classList.add('is-visible'));
    setTimeout(() => {
      t.classList.remove('is-visible');
      setTimeout(() => t.remove(), 250);
    }, type === 'error' ? 6000 : 3500);
  }

  // ─── Confirm modal ───────────────────────────────────────────
  function confirmModal(message, opts) {
    opts = opts || {};
    return new Promise((resolve) => {
      const backdrop = document.createElement('div');
      backdrop.className = 'auditcrawl-modal-backdrop';
      const modal = document.createElement('div');
      modal.className = 'auditcrawl-modal';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');

      const title = document.createElement('h2');
      title.className = 'auditcrawl-modal__title';
      title.textContent = opts.title || 'Confirm';
      const body = document.createElement('p');
      body.className = 'auditcrawl-modal__body';
      body.textContent = message;
      const actions = document.createElement('div');
      actions.className = 'auditcrawl-modal__actions';
      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'button';
      cancelBtn.textContent = opts.cancelLabel || 'Cancel';
      const okBtn = document.createElement('button');
      okBtn.type = 'button';
      okBtn.className = 'button button--primary';
      okBtn.textContent = opts.confirmLabel || 'Confirm';
      if (opts.destructive) okBtn.className += ' auditcrawl-btn-danger';

      actions.appendChild(cancelBtn);
      actions.appendChild(okBtn);
      modal.appendChild(title);
      modal.appendChild(body);
      modal.appendChild(actions);
      backdrop.appendChild(modal);
      document.body.appendChild(backdrop);
      document.body.style.overflow = 'hidden';
      requestAnimationFrame(() => backdrop.classList.add('is-visible'));
      okBtn.focus();

      function close(result) {
        backdrop.classList.remove('is-visible');
        document.body.style.overflow = '';
        setTimeout(() => backdrop.remove(), 200);
        resolve(result);
      }
      cancelBtn.addEventListener('click', () => close(false));
      okBtn.addEventListener('click', () => close(true));
      backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(false); });
      document.addEventListener('keydown', function onKey(e) {
        if (e.key === 'Escape') { document.removeEventListener('keydown', onKey); close(false); }
        if (e.key === 'Enter')  { document.removeEventListener('keydown', onKey); close(true);  }
      });
    });
  }

  // ─── POST helper ─────────────────────────────────────────────
  async function post(endpoint, body) {
    const res = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {}),
    });
    let data = null;
    try { data = await res.json(); } catch (_) {}
    return { ok: res.ok, status: res.status, data: data || {} };
  }

  window.AuditCrawlUI = { toast, confirm: confirmModal, post };

  // ─── Delegated button wiring ─────────────────────────────────
  function stubCellMarkup(editUrl, opts) {
    let html = '<a href="' + editUrl + '" class="button button--small">Open draft</a>';
    if (opts.hasLicense && !opts.filled) {
      html += ' <button type="button" class="button button--small auditcrawl-generate-now">Generate now</button>';
    }
    if (opts.filled && opts.wordCount) {
      html += ' <span style="color:#059669;font-size:11px;">✓ ' + opts.wordCount + ' words</span>';
    }
    return html;
  }

  Drupal.behaviors.auditcrawlAdmin = {
    attach(context) {
      const settings = (window.drupalSettings && drupalSettings.auditcrawl) || {};
      const ep = settings.endpoints || {};

      once('auditcrawl-delegate', 'body', context).forEach((body) => {
        body.addEventListener('click', async (e) => {
          // Create draft
          const createBtn = e.target.closest('.auditcrawl-create-stub');
          if (createBtn && !createBtn.disabled) {
            const row = createBtn.closest('tr');
            const idx = row.getAttribute('data-strategy-index');
            createBtn.disabled = true;
            createBtn.textContent = 'Creating…';
            const res = await post(ep.createStub, { strategy_index: parseInt(idx, 10) });
            if (res.ok && res.data.editUrl) {
              const cell = row.querySelector('.auditcrawl-stub-status');
              cell.setAttribute('data-post-id', res.data.postId || 0);
              cell.innerHTML = stubCellMarkup(res.data.editUrl, { filled: false, hasLicense: settings.hasLicense });
            } else {
              createBtn.disabled = false;
              createBtn.textContent = 'Retry';
              toast('Failed: ' + (res.data.error || 'unknown error'), 'error');
            }
            return;
          }

          // Generate now
          const genBtn = e.target.closest('.auditcrawl-generate-now');
          if (genBtn && !genBtn.disabled) {
            const cell = genBtn.closest('.auditcrawl-stub-status');
            const postId = cell.getAttribute('data-post-id');
            if (!postId || postId === '0') { toast('No post ID — try refreshing.', 'error'); return; }
            const ok = await confirmModal('This will use 1 credit from your monthly allotment and takes ~30 seconds.', {
              title: 'Generate AI content?', confirmLabel: 'Generate', cancelLabel: 'Cancel',
            });
            if (!ok) return;
            genBtn.disabled = true;
            genBtn.textContent = 'Generating… (~30s)';
            const res = await post(ep.generateNow, { post_id: parseInt(postId, 10) });
            if (res.ok && res.data.editUrl) {
              cell.innerHTML = stubCellMarkup(res.data.editUrl, { filled: true, wordCount: res.data.wordCount || '?', hasLicense: settings.hasLicense });
              toast('Draft filled — ' + (res.data.wordCount || '?') + ' words.', 'success');
            } else {
              genBtn.disabled = false;
              genBtn.textContent = 'Retry';
              toast('Generation failed: ' + (res.data.error || 'unknown'), 'error');
            }
            return;
          }

          // License actions (rotate / move / portal) on Schedule page.
          const actionBtn = e.target.closest('[data-auditcrawl-action]');
          if (actionBtn && !actionBtn.disabled) {
            const action = actionBtn.getAttribute('data-auditcrawl-action');
            if (action === 'open-portal') {
              actionBtn.disabled = true;
              actionBtn.textContent = 'Opening Stripe…';
              const res = await post(ep.openPortal, {});
              if (res.ok && res.data.url) window.open(res.data.url, '_blank', 'noopener');
              else toast((res.data.error || 'Failed'), 'error');
              actionBtn.disabled = false;
              actionBtn.textContent = 'Manage subscription on Stripe →';
            } else if (action === 'rotate-license') {
              const ok = await confirmModal('Your old key stops working immediately. The new key is auto-saved here and emailed to you.', {
                title: 'Rotate license key?', confirmLabel: 'Rotate key', destructive: true,
              });
              if (!ok) return;
              actionBtn.disabled = true;
              actionBtn.textContent = 'Rotating…';
              const res = await post(ep.rotateLicense, {});
              if (res.ok) {
                toast('New key saved locally and emailed to you.', 'success');
                setTimeout(() => window.location.reload(), 900);
              } else {
                toast((res.data.error || 'Failed'), 'error');
                actionBtn.disabled = false;
                actionBtn.textContent = 'Rotate license key';
              }
            } else if (action === 'move-license') {
              const ok = await confirmModal('Bind this license to ' + settings.siteUrl + '. This counts as your one move per 25 days.', {
                title: 'Move license to this site?', confirmLabel: 'Move license', destructive: true,
              });
              if (!ok) return;
              actionBtn.disabled = true;
              actionBtn.textContent = 'Moving…';
              const res = await post(ep.moveLicense, {});
              if (res.ok) {
                toast('License moved to ' + (res.data.boundTo || '(unbound)') + '.', 'success');
                setTimeout(() => window.location.reload(), 900);
              } else {
                toast((res.data.error || 'Failed'), 'error');
                actionBtn.disabled = false;
                actionBtn.textContent = 'Move to this site';
              }
            }
          }
        });
      });
    },
  };

})(Drupal, once);
