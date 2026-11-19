/*
 * The office page. Two chats: the Everyone team meeting, and a private chat with
 * whichever agent you pick. Every message comes from the server and is kept there for
 * good; the page only shows it. Desks move only when an agent is really working.
 */
const dataEl = document.getElementById('office-data');

if (dataEl) {
    const data = JSON.parse(dataEl.textContent);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let reads = data.reads || {};
    let runs = [];

    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
    const when = (iso) => {
        const d = new Date(iso), now = new Date();
        const hm = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return d.toDateString() === now.toDateString() ? 'Today ' + hm
            : d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' }) + ' ' + hm;
    };

    /**
     * The agents write in light Markdown. Turned into safe HTML: everything is escaped
     * first, then only bold, italics, code, headings, lists and http(s) links come back.
     */
    const inline = (t) => t
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    const markdown = (raw) => {
        const out = [];
        let list = null;
        const close = () => { if (list) { out.push(`</${list}>`); list = null; } };
        for (const line of esc(raw).split('\n')) {
            const bullet = line.match(/^\s*[-•*]\s+(.*)$/);
            const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
            const heading = line.match(/^\s*#{1,4}\s+(.*)$/);
            if (bullet || numbered) {
                const kind = bullet ? 'ul' : 'ol';
                if (list !== kind) { close(); out.push(`<${kind}>`); list = kind; }
                out.push(`<li>${inline((bullet || numbered)[1])}</li>`);
                continue;
            }
            close();
            if (heading) { out.push(`<p class="md-h">${inline(heading[1])}</p>`); continue; }
            out.push(line.trim() === '' ? '<p class="md-gap"></p>' : `<p>${inline(line)}</p>`);
        }
        close();
        return out.join('');
    };

    /** One entry per chat box on the page; a box's channel can change (the private one). */
    const boxes = [...document.querySelectorAll('[data-channel]')].map((el) => ({
        el,
        box: el.querySelector('[data-msgs]'),
        get channel() { return el.dataset.channel; },
        msgs: data.history[el.dataset.channel] || [],
        forceEnd: true,
    }));
    const privateBox = boxes.find((b) => b.el.hasAttribute('data-private-chat'));
    const lastId = (b) => (b.msgs.length ? b.msgs[b.msgs.length - 1].id : 0);

    function render(b) {
        const atEnd = b.box.scrollHeight - b.box.scrollTop - b.box.clientHeight < 40;
        const priv = b.channel !== 'everyone';
        const receipt = (m) => {
            const names = !priv && m.mine ? (reads[m.id] || []) : [];
            return names.length ? `<span class="t" title="${esc(names.join(', '))}">Read by ${names.length} · ${esc(names.slice(0, 4).join(', '))}${names.length > 4 ? '…' : ''}</span>` : '';
        };
        const mine = runs.filter((r) => r.channel === b.channel);
        const working = mine.filter((r) => !r.waiting);
        const waiting = mine.length - working.length;

        const files = (m) => (m.files || []).length
            ? `<span class="files">${m.files.map((f) => f.image
                ? `<a href="${esc(f.url)}" target="_blank" rel="noopener" class="file-img"><img src="${esc(f.url)}" alt="${esc(f.name)}" loading="lazy"></a>`
                : `<a href="${esc(f.url)}" target="_blank" rel="noopener" class="file">${esc(f.name)} <small>${Math.max(1, Math.round(f.size / 1024))} KB</small></a>`).join('')}</span>`
            : '';
        b.box.innerHTML = (b.msgs.map((m) => `<div class="m ${m.mine ? 'u' : 'a'}">${priv && !m.mine ? '' : `<span class="who">${esc(m.author)}</span>`}<div class="body">${m.mine ? esc(m.body).replace(/\n/g, '<br>') : markdown(m.body)}</div>${files(m)}<span class="t">${esc(when(m.at))}</span>${receipt(m)}</div>`).join('')
            + (waiting ? `<div class="m a typing"><span>${waiting} more waiting for their turn</span></div>` : '')
            + working.map((r) => `<div class="m a typing ${r.stuck ? 'stuck' : ''}"><span class="who">${esc(r.name)}</span>${r.stuck ? '' : '<span class="dots" aria-hidden="true"><i></i><i></i><i></i></span>'}<span>${esc(r.stuck ? `Stuck on: ${r.step || 'working'} (${Math.round(r.seconds / 60)} min)` : (r.step || 'Working') + '…')}</span></div>`).join(''))
            || '<div class="empty">No messages yet. Write the first one.</div>';

        b.el.querySelector('[data-count]').textContent = `${b.msgs.length} messages · ${b.msgs.filter((m) => m.mine).length} from you`;
        const jump = b.el.querySelector('[data-jump]');
        if (atEnd || b.forceEnd) {
            b.box.scrollTop = b.box.scrollHeight; b.forceEnd = false; b.seen = b.msgs.length;
            if (jump) jump.hidden = true;
        } else if (jump && b.msgs.length > (b.seen || 0)) {
            jump.hidden = false;
        }
    }

    function renderRuns() {
        document.querySelectorAll('[data-desk]').forEach((desk) => {
            const run = runs.filter((r) => r.agent === desk.dataset.desk).at(-1);
            desk.classList.toggle('working', !!run && !run.stuck && !run.waiting);
            desk.classList.toggle('stuck', !!run && run.stuck);
            desk.querySelector('[data-step]').textContent = run
                ? (run.stuck ? `Stuck on: ${run.step || 'working'}` : run.waiting ? 'Waiting for their turn' : `${run.step || 'Working'}…`)
                : 'Ready';
        });
        document.querySelectorAll('[data-pick]').forEach((pick) => {
            pick.classList.toggle('working', runs.some((r) => r.agent === pick.dataset.pick && !r.waiting && r.channel === pick.dataset.channelFor));
        });

        const active = runs.filter((r) => !r.waiting);
        const waiting = runs.length - active.length;
        document.getElementById('now').innerHTML = runs.length
            ? '<span class="now-l live">Right now</span>' + (waiting ? `<span class="now-i">${waiting} waiting for their turn</span>` : '')
                + active.map((r) => `<span class="now-i ${r.stuck ? 'stuck' : ''}"><b>${esc(r.name)}</b> ${esc((r.step || 'working').toLowerCase())} <span class="bar"><span style="width:${Math.round((r.step_number / Math.max(r.step_total, 1)) * 100)}%"></span></span></span>`).join('')
            : '<span class="now-l">Right now</span><span>Nobody is working.</span>';

        boxes.forEach(render);
    }

    let polling = false;
    async function poll() {
        if (polling) return;
        polling = true;
        try {
            const params = new URLSearchParams();
            boxes.forEach((b) => params.append(`after[${b.channel}]`, lastId(b)));
            const res = await fetch(`${data.stateUrl}?${params}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const state = await res.json();
            boxes.forEach((b) => {
                const fresh = state.messages[b.channel] || [];
                const known = new Set(b.msgs.map((m) => m.id));
                b.msgs.push(...fresh.filter((m) => !known.has(m.id)));
            });
            reads = state.reads || reads;
            runs = state.runs;
            renderRuns();
        } catch (e) {
            /* The next poll tries again; nothing is lost. */
        } finally {
            polling = false;
        }
    }

    /** Pick who to talk to alone: that agent's whole private history opens in the box. */
    async function pick(agent) {
        const button = document.querySelector(`[data-pick="${agent}"]`);
        if (!privateBox || !button) return;
        const channel = button.dataset.channelFor;
        document.querySelectorAll('[data-pick]').forEach((p) => p.setAttribute('aria-checked', String(p === button)));
        privateBox.el.querySelector('[data-private-name]').textContent = button.dataset.label;
        privateBox.el.querySelector('[data-text]').placeholder = `Write to ${button.dataset.name} alone…`;
        privateBox.el.dataset.channel = channel;
        privateBox.msgs = [];
        privateBox.box.innerHTML = '<div class="empty">Opening your chat…</div>';
        history.replaceState(null, '', `?with=${encodeURIComponent(agent)}#chats`);
        try {
            const res = await fetch(`${data.historyUrl}?channel=${encodeURIComponent(channel)}`, { headers: { Accept: 'application/json' } });
            const json = await res.json();
            if (privateBox.channel === json.channel) {
                privateBox.msgs = json.messages;
                privateBox.forceEnd = true;
                render(privateBox);
            }
        } catch (e) {
            privateBox.box.innerHTML = '<div class="empty">Could not open this chat. Your messages are safe; try again.</div>';
        }
    }

    document.querySelectorAll('[data-pick]').forEach((p) => p.addEventListener('click', () => pick(p.dataset.pick)));
    document.querySelectorAll('[data-desk]').forEach((desk) => desk.addEventListener('click', (e) => {
        if (!privateBox) return;
        e.preventDefault();
        pick(desk.dataset.desk);
        privateBox.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        privateBox.el.querySelector('[data-text]').focus({ preventScroll: true });
    }));

    boxes.forEach((b) => {
        const form = b.el.querySelector('[data-compose]');
        const text = form.querySelector('[data-text]');
        const button = form.querySelector('button[type=submit]');
        const picker = form.querySelector('[data-files]');
        const shelf = form.querySelector('[data-attached]');
        const errorBox = form.querySelector('[data-error]');
        const jump = b.el.querySelector('[data-jump]');
        let chosen = [];
        let sending = false;

        const showError = (message) => { errorBox.textContent = message; errorBox.hidden = !message; };
        const grow = () => { text.style.height = 'auto'; text.style.height = Math.min(text.scrollHeight, 200) + 'px'; };
        const showChosen = () => {
            shelf.innerHTML = chosen.map((f, i) => `<span class="chip-file">${esc(f.name)} <button type="button" data-drop="${i}" aria-label="Remove ${esc(f.name)}">×</button></span>`).join('');
            shelf.querySelectorAll('[data-drop]').forEach((x) => x.addEventListener('click', () => { chosen.splice(Number(x.dataset.drop), 1); showChosen(); }));
        };

        b.box.addEventListener('scroll', () => {
            if (b.box.scrollHeight - b.box.scrollTop - b.box.clientHeight < 40) { b.seen = b.msgs.length; if (jump) jump.hidden = true; }
        });
        if (jump) jump.addEventListener('click', () => { b.forceEnd = true; render(b); });

        picker.addEventListener('change', () => {
            const tooBig = [...picker.files].filter((f) => f.size > 10 * 1024 * 1024);
            chosen = [...chosen, ...[...picker.files].filter((f) => f.size <= 10 * 1024 * 1024)].slice(0, 5);
            picker.value = '';
            showChosen();
            showError(tooBig.length ? `${tooBig.map((f) => f.name).join(', ')}: more than 10 MB, not attached.` : '');
        });

        const send = async () => {
            if (sending) return;
            const body = text.value.trim();
            if (!body && !chosen.length) { text.focus(); return; }
            sending = true;
            button.disabled = true;
            form.classList.add('is-sending');
            showError('');
            try {
                const payload = new FormData();
                payload.append('channel', b.channel);
                payload.append('body', body);
                chosen.forEach((f) => payload.append('files[]', f));
                const res = await fetch(data.chatUrl, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: payload,
                    credentials: 'same-origin',
                });
                if (res.status === 419) throw new Error('Your session expired. Reload the page (your text is still here: copy it first).');
                if (res.status === 401) throw new Error('You were signed out. Sign in again.');
                if (res.status === 429) throw new Error('Too many messages in a minute. Wait a moment and press Enter again.');
                if (!res.ok) {
                    let reason = '';
                    try { reason = (await res.json()).message || ''; } catch (e) { /* not JSON */ }
                    throw new Error(reason || `The office answered with an error (${res.status}).`);
                }
                // Cleared only once the server has saved it.
                text.value = '';
                grow();
                chosen = [];
                showChosen();
                b.forceEnd = true;
                await poll();
            } catch (err) {
                showError(`Not sent. ${err.message || 'Check your connection and press Enter again.'}`);
            } finally {
                sending = false;
                button.disabled = false;
                form.classList.remove('is-sending');
                text.focus();
            }
        };

        // Enter sends; Shift+Enter makes a new line. Composing text (IME) is left alone.
        text.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
                e.preventDefault();
                send();
            }
        });
        text.addEventListener('input', grow);
        form.addEventListener('submit', (e) => { e.preventDefault(); send(); });
    });

    document.querySelectorAll('[data-big]').forEach((btn) => btn.addEventListener('click', () => {
        const chat = btn.closest('.chat');
        chat.classList.toggle('big');
        btn.textContent = chat.classList.contains('big') ? 'Smaller' : 'Bigger';
    }));

    // Lock a name only after a second, deliberate click.
    document.querySelectorAll('.name-form').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (form.dataset.confirmed) return;
            e.preventDefault();
            const name = form.querySelector('input[name=name]').value.trim();
            if (!name) return;
            form.querySelector('.confirm')?.remove();
            form.insertAdjacentHTML('beforeend', `<span class="confirm">Lock <b>${esc(name)}</b> forever? It can't be changed here afterwards.</span>`);
            const button = form.querySelector('button');
            button.textContent = 'Yes, lock it';
            form.dataset.confirmed = '1';
            form.querySelector('input[name=name]').addEventListener('input', () => {
                delete form.dataset.confirmed; button.textContent = 'Lock name'; form.querySelector('.confirm')?.remove();
            }, { once: true });
        });
    });

    boxes.forEach(render);
    poll();
    const tick = () => setTimeout(async () => { await poll(); tick(); }, document.hidden ? 10000 : 2000);
    tick();
}
