/*
 * The office page: chats that never lose a message, and desks that move only when an
 * agent is really working. Everything shown comes from the server; the page polls it.
 */
const dataEl = document.getElementById('office-data');

if (dataEl) {
    const data = JSON.parse(dataEl.textContent);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const chats = {};

    const esc = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
    const when = (iso) => {
        const d = new Date(iso), now = new Date();
        const hm = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        return d.toDateString() === now.toDateString() ? 'Today ' + hm
            : d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' }) + ' ' + hm;
    };

    document.querySelectorAll('[data-channel]').forEach((el) => {
        const channel = el.dataset.channel;
        chats[channel] = { el, box: el.querySelector('[data-msgs]'), msgs: data.history[channel] || [], typing: [] };
    });

    const lastId = (c) => (c.msgs.length ? c.msgs[c.msgs.length - 1].id : 0);

    function renderChat(channel) {
        const c = chats[channel];
        const atEnd = c.box.scrollHeight - c.box.scrollTop - c.box.clientHeight < 40;
        const priv = channel !== 'everyone';
        const html = c.msgs.map((m) => `<div class="m ${m.mine ? 'u' : 'a'}">${priv && !m.mine ? '' : `<span class="who">${esc(m.author)}</span>`}${esc(m.body)}<span class="t">${esc(when(m.at))}</span></div>`).join('')
            + c.typing.map((r) => `<div class="m a typing ${r.stuck ? 'stuck' : ''}"><span class="who">${esc(r.name)}</span>${r.stuck ? '' : '<span class="dots" aria-hidden="true"><i></i><i></i><i></i></span>'}<span>${esc(r.stuck ? `Stuck on: ${r.step || 'working'} (${Math.round(r.seconds / 60)} min)` : (r.step || 'Working') + '…')}</span></div>`).join('');
        c.box.innerHTML = html || '<div class="empty">No messages yet. Write the first one.</div>';
        const mine = c.msgs.filter((m) => m.mine).length;
        c.el.querySelector('[data-count]').textContent = `${c.msgs.length} messages · ${mine} from you`;
        if (atEnd || c.forceEnd) { c.box.scrollTop = c.box.scrollHeight; c.forceEnd = false; }
    }

    function renderRuns(runs) {
        document.querySelectorAll('[data-desk]').forEach((desk) => {
            const run = runs.filter((r) => r.agent === desk.dataset.desk).at(-1);
            desk.classList.toggle('working', !!run && !run.stuck);
            desk.classList.toggle('stuck', !!run && run.stuck);
            desk.querySelector('[data-step]').textContent = run ? (run.stuck ? `Stuck on: ${run.step || 'working'}` : `${run.step || 'Working'}…`) : 'Ready';
        });

        const now = document.getElementById('now');
        now.innerHTML = runs.length
            ? '<span class="now-l live">Right now</span>' + runs.map((r) => `<span class="now-i ${r.stuck ? 'stuck' : ''}"><b>${esc(r.name)}</b> ${esc((r.step || 'working').toLowerCase())} <span class="bar"><span style="width:${Math.round((r.step_number / Math.max(r.step_total, 1)) * 100)}%"></span></span></span>`).join('')
            : '<span class="now-l">Right now</span><span>Nobody is working.</span>';

        Object.entries(chats).forEach(([channel, c]) => {
            c.typing = runs.filter((r) => r.channel === channel);
            renderChat(channel);
        });
    }

    let polling = false;
    async function poll() {
        if (polling) return;
        polling = true;
        try {
            const params = new URLSearchParams();
            Object.entries(chats).forEach(([channel, c]) => params.append(`after[${channel}]`, lastId(c)));
            const res = await fetch(`${data.stateUrl}?${params}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const state = await res.json();
            Object.entries(state.messages).forEach(([channel, msgs]) => {
                const c = chats[channel];
                if (!c || !msgs.length) return;
                const known = new Set(c.msgs.map((m) => m.id));
                c.msgs.push(...msgs.filter((m) => !known.has(m.id)));
            });
            renderRuns(state.runs);
        } catch (e) {
            /* The next poll tries again; nothing on the page is lost. */
        } finally {
            polling = false;
        }
    }

    document.querySelectorAll('[data-compose]').forEach((form) => {
        const channel = form.closest('[data-channel]').dataset.channel;
        const text = form.querySelector('textarea');
        const button = form.querySelector('button');

        text.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const body = text.value.trim();
            if (!body) return;
            button.disabled = true;
            form.querySelector('.send-err')?.remove();
            try {
                const res = await fetch(data.chatUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ channel, body }),
                });
                if (!res.ok) throw new Error(String(res.status));
                // Cleared only once the server has saved it.
                text.value = '';
                chats[channel].forceEnd = true;
                await poll();
            } catch (err) {
                form.insertAdjacentHTML('beforeend', '<span class="send-err">Not sent. Your text is still here; try again.</span>');
            } finally {
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('[data-big]').forEach((b) => b.addEventListener('click', () => {
        const chat = b.closest('.chat');
        chat.classList.toggle('big');
        b.textContent = chat.classList.contains('big') ? 'Smaller' : 'Bigger';
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

    Object.keys(chats).forEach((channel) => { chats[channel].forceEnd = true; renderChat(channel); });
    poll();
    const tick = () => setTimeout(async () => { await poll(); tick(); }, document.hidden ? 10000 : 2000);
    tick();
}
