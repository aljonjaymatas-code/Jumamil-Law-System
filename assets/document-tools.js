(() => {
    const editorForm = document.querySelector('[data-draft-editor]');
    if (editorForm) {
        const editor = editorForm.querySelector('[data-rich-text-surface]');
        const contentInput = editorForm.querySelector('[name="draft_content"]');
        const toolbar = editorForm.querySelector('.editor-toolbar');
        const allowedCommands = new Set([
            'bold',
            'italic',
            'underline',
            'insertUnorderedList',
            'insertOrderedList',
            'justifyLeft',
            'justifyCenter',
            'justifyRight',
            'undo',
            'redo',
        ]);

        toolbar.addEventListener('mousedown', (event) => {
            if (event.target.closest('button')) {
                event.preventDefault();
            }
        });

        toolbar.addEventListener('click', (event) => {
            const button = event.target.closest('[data-command]');
            if (!button || !allowedCommands.has(button.dataset.command)) {
                return;
            }
            document.execCommand(button.dataset.command, false);
            editor.focus();
        });

        toolbar.querySelector('[data-block-format]').addEventListener('change', (event) => {
            editor.focus();
            document.execCommand('formatBlock', false, `<${event.target.value}>`);
        });

        editorForm.addEventListener('submit', () => {
            contentInput.value = editor.innerHTML;
        });

        const printButton = editorForm.querySelector('[data-print-draft]');
        printButton.addEventListener('click', () => window.print());
    }

    const preview = document.querySelector('[data-pdf-preview]');
    if (!preview) {
        return;
    }

    const image = preview.querySelector('[data-page-image]');
    const pageIndicator = preview.querySelector('[data-page-indicator]');
    const message = preview.querySelector('[data-preview-message]');
    const previousButton = preview.querySelector('[data-page-previous]');
    const nextButton = preview.querySelector('[data-page-next]');
    let currentPage = 1;
    let pageCount = 0;
    let imageUrl = '';
    let loading = false;

    const loadPage = async (targetPage) => {
        if (loading || targetPage < 1 || (pageCount > 0 && targetPage > pageCount)) {
            return;
        }

        loading = true;
        previousButton.disabled = true;
        nextButton.disabled = true;
        message.textContent = `Rendering page ${targetPage}...`;

        try {
            const query = new URLSearchParams({
                id: preview.dataset.documentId,
                page: String(targetPage),
            });
            const response = await fetch(`preview-page.php?${query}`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'image/jpeg' },
            });
            if (!response.ok) {
                throw new Error('The requested page could not be rendered.');
            }

            const renderedPageCount = Number(response.headers.get('X-Document-Page-Count'));
            if (!Number.isInteger(renderedPageCount) || renderedPageCount < 1) {
                throw new Error('The preview service returned an invalid page count.');
            }

            const blob = await response.blob();
            if (blob.type !== 'image/jpeg') {
                throw new Error('The preview service returned an unsupported image.');
            }

            const previousImageUrl = imageUrl;
            imageUrl = URL.createObjectURL(blob);
            image.onload = () => {
                if (previousImageUrl) {
                    URL.revokeObjectURL(previousImageUrl);
                }
            };
            image.onerror = () => {
                URL.revokeObjectURL(imageUrl);
                if (previousImageUrl) {
                    URL.revokeObjectURL(previousImageUrl);
                }
                imageUrl = '';
                message.textContent = 'The rendered page image could not be displayed.';
            };
            image.src = imageUrl;
            currentPage = targetPage;
            pageCount = renderedPageCount;
            pageIndicator.textContent = `Page ${currentPage} of ${pageCount}`;
            message.textContent = 'Only this page is loaded in the browser.';
        } catch (error) {
            message.textContent = error.message || 'The PDF page preview could not be loaded.';
        } finally {
            loading = false;
            previousButton.disabled = currentPage <= 1;
            nextButton.disabled = pageCount === 0 || currentPage >= pageCount;
        }
    };

    previousButton.addEventListener('click', () => loadPage(currentPage - 1));
    nextButton.addEventListener('click', () => loadPage(currentPage + 1));
    window.addEventListener('pagehide', () => {
        if (imageUrl) {
            URL.revokeObjectURL(imageUrl);
        }
    });

    loadPage(1);
})();
