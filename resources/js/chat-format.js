/*
 * Chat text, shared by the office chats and the IT board discussions.
 */
export const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

/**
 * The agents write in light Markdown. Turned into safe HTML: everything is escaped
 * first, then only bold, italics, code, headings, lists and http(s) links come back.
 */
const inline = (t) => t
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
export const markdown = (raw) => {
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

