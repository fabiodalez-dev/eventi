import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';

export function richInputs() {
    document.querySelectorAll('[data-rich-input]').forEach((root) => {
        const input = document.getElementById(root.dataset.target);
        if (!input || root.dataset.ready) return;
        const toolbar = document.createElement('div');
        toolbar.className = 'flex flex-wrap gap-1 border-b border-line p-2';
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', 'Formattazione del testo');
        const content = document.createElement('div');
        root.className = 'rounded border border-line bg-surface';
        root.append(toolbar, content);
        const editor = new Editor({
            element: content,
            extensions: [StarterKit.configure({ heading: { levels: [2, 3] }, code: false, codeBlock: false, horizontalRule: false, link: { openOnClick: false, protocols: ['http', 'https', 'mailto'] } })],
            content: root.dataset.content || '',
            editorProps: { attributes: { role: 'textbox', 'aria-multiline': 'true', 'aria-label': root.dataset.label, 'aria-describedby': input.getAttribute('aria-describedby') || '', 'aria-invalid': input.getAttribute('aria-invalid') || 'false', class: 'min-h-40 p-3 outline-none focus-visible:ring-2 focus-visible:ring-accent [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:list-decimal [&_ol]:pl-6 [&_a]:underline [&_h2]:text-xl [&_h2]:font-bold [&_h3]:font-bold' } },
            onUpdate: ({ editor }) => { input.value = editor.isEmpty ? '' : editor.getHTML(); },
        });
        const actions = [
            ['Grassetto', () => editor.chain().focus().toggleBold().run(), 'bold'],
            ['Corsivo', () => editor.chain().focus().toggleItalic().run(), 'italic'],
            ['Sottolineato', () => editor.chain().focus().toggleUnderline().run(), 'underline'],
            ['Titolo', () => editor.chain().focus().toggleHeading({ level: 2 }).run(), 'heading'],
            ['Elenco', () => editor.chain().focus().toggleBulletList().run(), 'bulletList'],
            ['Elenco numerato', () => editor.chain().focus().toggleOrderedList().run(), 'orderedList'],
            ['Link', () => { linkForm.hidden = !linkForm.hidden; linkInput.disabled = linkForm.hidden; if (!linkForm.hidden) linkInput.focus(); }, 'link'],
            ['Rimuovi link', () => editor.chain().focus().unsetLink().run()],
            ['Annulla', () => editor.chain().focus().undo().run()],
            ['Ripeti', () => editor.chain().focus().redo().run()],
        ];
        const linkForm = document.createElement('div');
        linkForm.hidden = true;
        linkForm.className = 'p-3';
        const linkInput = document.createElement('input');
        linkInput.type = 'url';
        linkInput.disabled = true;
        linkInput.placeholder = 'https://esempio.it';
        linkInput.setAttribute('aria-label', 'Indirizzo del link');
        const applyLink = document.createElement('button');
        applyLink.type = 'button';
        applyLink.textContent = 'Inserisci link';
        applyLink.className = 'min-h-12 px-3 underline';
        applyLink.addEventListener('click', () => {
            const value = linkInput.value.trim();
            if (!/^https?:\/\//i.test(value) || !linkInput.checkValidity()) { linkInput.setCustomValidity('Inserisci un indirizzo completo https:// o http://.'); linkInput.reportValidity(); return; }
            editor.chain().focus().extendMarkRange('link').setLink({ href: value }).run();
            linkForm.hidden = true;
            linkInput.disabled = true;
            linkInput.value = '';
        });
        linkInput.addEventListener('input', () => linkInput.setCustomValidity(''));
        linkForm.append(linkInput, applyLink);
        root.insertBefore(linkForm, content);
        const buttons = actions.map(([label, action, active]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.className = 'min-h-12 rounded border border-line px-3 text-sm aria-pressed:bg-ink aria-pressed:text-canvas focus-visible:outline-2 focus-visible:outline-accent';
            button.addEventListener('click', action);
            toolbar.append(button);
            return { button, active };
        });
        const updateToolbar = () => buttons.forEach(({ button, active }) => { if (active) button.setAttribute('aria-pressed', String(editor.isActive(active))); });
        editor.on('selectionUpdate', updateToolbar);
        editor.on('transaction', updateToolbar);
        updateToolbar();
        input.form?.addEventListener('submit', () => { input.value = editor.isEmpty ? '' : editor.getHTML(); });
        input.hidden = true;
        input.required = false;
        root.hidden = false;
        root.dataset.ready = 'true';
        document.querySelector('label[for="' + CSS.escape(input.id) + '"]')?.addEventListener('click', () => editor.commands.focus());
    });
}
