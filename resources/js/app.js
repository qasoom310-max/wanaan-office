import './office.js';

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
