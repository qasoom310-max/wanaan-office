import './office.js';
import { esc, markdown } from './chat-format';

// "Copy for Claude Code" and any other copy button: copies, then says so.
document.addEventListener('click', async (e) => {
    const button = e.target.closest('[data-copy]');
    if (!button) return;
    const label = button.textContent;
    try {
        await navigator.clipboard.writeText(button.dataset.copy);
        button.textContent = 'Copied ✓';
    } catch (err) {
        window.prompt('Copy this:', button.dataset.copy);
    }
    setTimeout(() => { button.textContent = label; }, 2000);
});

// The discussion on each IT board request: the owner and the agent who asked talk it
// over on the card. Messages are loaded when the discussion is opened, and checked
// again while the agent is answering.
(() => {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const threads = document.querySelectorAll('details.thread');
    if (!threads.length || !csrfMeta) return;

    const render = (thread, data) => {
        const list = thread.querySelector('.thread-list');
        list.replaceChildren();
        if (!data.messages.length) {
            const empty = document.createElement('div');
            empty.className = 'muted';
            empty.textContent = 'Nothing said yet. Ask why, push back, or suggest a change.';
            list.append(empty);
        }
        for (const message of data.messages) {
            // The same bubbles and formatting as the office chats; everything is escaped first.
            const item = document.createElement('div');
            item.className = 'm ' + (message.mine ? 'u' : 'a');
            const at = new Date(message.at).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
            item.innerHTML = `<span class="who">${esc(message.author)}</span>`
                + `<div class="body">${message.mine ? esc(message.body).replace(/\n/g, '<br>') : markdown(message.body)}</div>`
                + `<span class="t">${esc(at)}</span>`;
            list.append(item);
        }
        list.scrollTop = list.scrollHeight;
        const working = thread.querySelector('.thread-working');
        working.hidden = !data.working;
        if (data.working) working.querySelector('span:last-child').textContent = data.working.agent + ': ' + (data.working.step || 'working') + '…';
        const count = thread.querySelector('summary .muted');
        if (count) count.textContent = '· ' + data.messages.length;
    };

    const load = async (thread) => {
        const response = await fetch('/office/history?channel=' + encodeURIComponent(thread.dataset.channel), { headers: { Accept: 'application/json' } });
        if (!response.ok) return null;
        const data = await response.json();
        render(thread, data);
        return data;
    };

    // While the agent answers, look again every few seconds (for up to ten minutes).
    const follow = async (thread, startedAt = Date.now()) => {
        const data = await load(thread);
        if (data && data.working) thread.dataset.reloadWhenDone = '1';
        if (data && data.working && Date.now() - startedAt < 600000) {
            setTimeout(() => follow(thread, startedAt), 3000);
        } else if (data && data.working === null && thread.dataset.reloadWhenDone) {
            // The agent may have changed the request: show the new title and status.
            location.hash = thread.closest('.row').id;
            location.reload();
        }
    };

    for (const thread of threads) {
        if (thread.open) follow(thread);
        thread.addEventListener('toggle', () => { if (thread.open) follow(thread); });

        const form = thread.querySelector('.thread-form');
        if (!form) continue;
        const box = form.querySelector('textarea');
        box.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
        });
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const body = box.value.trim();
            if (!body) return;
            const button = form.querySelector('button');
            button.disabled = true;
            const sent = new FormData();
            sent.append('channel', thread.dataset.channel);
            sent.append('body', body);
            try {
                const response = await fetch('/office/chat', { method: 'POST', body: sent, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfMeta.content } });
                if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'Not sent');
                box.value = '';
                thread.dataset.reloadWhenDone = '1';
                follow(thread);
            } catch (err) {
                alert('Not sent: ' + err.message + '. Your text is still in the box.');
            } finally {
                button.disabled = false;
            }
        });
    }

    // After a reload, reopen the discussion we were in.
    const target = location.hash && document.querySelector(location.hash + ' details.thread');
    if (target) { target.open = true; target.scrollIntoView({ block: 'center' }); }
})();
