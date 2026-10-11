pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

const API = '../api/pdf.php';

const MAX_UPLOAD_SIZE_MB = 50;
const BULK_CHUNK_SIZE = 25;

const uploadForm = document.getElementById('uploadForm');
const pdfFile = document.getElementById('pdfFile');

const pageNumber = document.getElementById('pageNumber');
const totalPages = document.getElementById('totalPages');

const prevPage = document.getElementById('prevPage');
const nextPage = document.getElementById('nextPage');

const pdfCanvas = document.getElementById('pdfCanvas');
const pageWrapper = document.getElementById('pageWrapper');

const emptyMessage = document.getElementById('emptyMessage');
const selectionOverlay = document.getElementById('selectionOverlay');

const extractButton = document.getElementById('extractButton');
const clearAreaButton = document.getElementById('clearAreaButton');

const bulkExtractButton = document.getElementById('bulkExtractButton');
const copyTextButton = document.getElementById('copyTextButton');
const downloadTextButton = document.getElementById('downloadTextButton');

const extractedText = document.getElementById('extractedText');
const status = document.getElementById('status');

const fields = {
    x: document.getElementById('coordX'),
    y: document.getElementById('coordY'),
    w: document.getElementById('coordW'),
    h: document.getElementById('coordH')
};

let pdfDocument = null;
let currentPage = 1;
let totalPageCount = 0;

let currentRenderTask = null;
let renderVersion = 0;

let originalPageWidth = 0;
let originalPageHeight = 0;

let displayedScale = 1;

let currentFilename = '';
let selectionStart = null;

function setStatus(message) {
    status.textContent = message;
}

async function apiJson(url, options = {}) {
    const response = await fetch(url, options);

    const contentType = response.headers.get('content-type') || '';

    if (!contentType.includes('application/json')) {
        throw new Error('Risposta non valida dal server.');
    }

    const data = await response.json();

    if (!response.ok) {
        throw new Error(data.error || 'Errore del server.');
    }

    return data;
}

async function uploadFile(file) {
    if (!file) {
        setStatus('Seleziona un PDF.');
        return;
    }

    if (file.size > MAX_UPLOAD_SIZE_MB * 1024 * 1024) {
        setStatus(
            `Il file supera la dimensione massima di ${MAX_UPLOAD_SIZE_MB} MB.`
        );

        return;
    }

    const formData = new FormData();

    formData.append('action', 'upload');
    formData.append('pdf', file);

    try {
        setStatus('Caricamento PDF...');

        const result = await apiJson(API, {
            method: 'POST',
            body: formData
        });

        // Rilascia il documento precedente per liberare la memoria.
        if (pdfDocument) {
            if (currentRenderTask) {
                currentRenderTask.cancel();
                currentRenderTask = null;
            }

            await pdfDocument.destroy();
            pdfDocument = null;
        }

        // PDF.js carica il documento originale dal browser.
        const arrayBuffer = await file.arrayBuffer();

        pdfDocument = await pdfjsLib.getDocument({
            data: arrayBuffer
        }).promise;

        currentFilename = result.filename;
        totalPageCount = result.pages;
        currentPage = 1;

        totalPages.textContent = totalPageCount;

        pageNumber.disabled = false;
        prevPage.disabled = false;
        nextPage.disabled = false;

        extractButton.disabled = false;
        clearAreaButton.disabled = false;
        bulkExtractButton.disabled = false;

        pageNumber.max = totalPageCount;
        pageNumber.value = 1;

        emptyMessage.hidden = true;
        pageWrapper.hidden = false;

        clearCoordinates();

        await renderPage(1);

        setStatus(
            `Caricato: ${result.filename} (${totalPageCount} pagine).`
        );

    } catch (error) {
        setStatus(error.message);
    }
}

uploadForm.addEventListener('submit', (event) => {
    event.preventDefault();

    uploadFile(pdfFile.files[0]);
});

// Caricamento trascinando il file nella zona di upload.
uploadForm.addEventListener('dragover', (event) => {
    event.preventDefault();
    uploadForm.classList.add('dragover');
});

uploadForm.addEventListener('dragleave', () => {
    uploadForm.classList.remove('dragover');
});

uploadForm.addEventListener('drop', (event) => {
    event.preventDefault();
    uploadForm.classList.remove('dragover');

    const file = event.dataTransfer.files[0];

    if (file) {
        pdfFile.files = event.dataTransfer.files;
    }

    uploadFile(file);
});

async function renderPage(page) {
    if (!pdfDocument) {
        return;
    }

    if (page < 1 || page > totalPageCount) {
        return;
    }

    const version = ++renderVersion;

    try {
        if (currentRenderTask) {
            currentRenderTask.cancel();
            currentRenderTask = null;
        }

        currentPage = page;
        pageNumber.value = page;

        prevPage.disabled = page <= 1;
        nextPage.disabled = page >= totalPageCount;

        setStatus(`Caricamento pagina ${page}...`);

        // PDF.js visualizza il documento originale.
        const pdfPage = await pdfDocument.getPage(page);

        if (version !== renderVersion) {
            return;
        }

        const baseViewport = pdfPage.getViewport({
            scale: 1
        });

        originalPageWidth = baseViewport.width;
        originalPageHeight = baseViewport.height;

        const container = document.getElementById('pdfContainer');

        const availableWidth = Math.max(
            100,
            container.clientWidth - 24
        );

        displayedScale = Math.min(
            1.5,
            availableWidth / originalPageWidth
        );

        const viewport = pdfPage.getViewport({
            scale: displayedScale
        });

        const outputScale = window.devicePixelRatio || 1;

        pdfCanvas.width = Math.floor(
            viewport.width * outputScale
        );

        pdfCanvas.height = Math.floor(
            viewport.height * outputScale
        );

        pdfCanvas.style.width = `${viewport.width}px`;
        pdfCanvas.style.height = `${viewport.height}px`;

        pageWrapper.style.width = `${viewport.width}px`;
        pageWrapper.style.height = `${viewport.height}px`;

        const context = pdfCanvas.getContext('2d');

        const transform = outputScale !== 1
            ? [outputScale, 0, 0, outputScale, 0, 0]
            : null;

        currentRenderTask = pdfPage.render({
            canvasContext: context,
            viewport,
            transform
        });

        await currentRenderTask.promise;

        if (version !== renderVersion) {
            return;
        }

        currentRenderTask = null;

        updateOverlay();

        setStatus(`Pagina ${page} di ${totalPageCount}.`);

    } catch (error) {
        if (error.name !== 'RenderingCancelledException') {
            setStatus(error.message);
        }
    }
}

prevPage.addEventListener('click', () => {
    renderPage(currentPage - 1);
});

nextPage.addEventListener('click', () => {
    renderPage(currentPage + 1);
});

pageNumber.addEventListener('change', () => {
    const page = Number(pageNumber.value);

    if (
        Number.isInteger(page)
        && page >= 1
        && page <= totalPageCount
    ) {
        renderPage(page);
    } else {
        pageNumber.value = currentPage;
    }
});

function readCoordinates() {
    const values = {};

    for (const [key, input] of Object.entries(fields)) {
        if (input.value.trim() === '') {
            return null;
        }

        const value = Number(input.value);

        if (!Number.isFinite(value)) {
            return null;
        }

        values[key] = value;
    }

    if (
        values.x < 0 ||
        values.y < 0 ||
        values.w <= 0 ||
        values.h <= 0
    ) {
        return null;
    }

    return values;
}

function setOverlayBox(x, y, w, h) {
    const scaleX = pdfCanvas.clientWidth / originalPageWidth;
    const scaleY = pdfCanvas.clientHeight / originalPageHeight;

    selectionOverlay.style.display = 'block';

    selectionOverlay.style.left =
        `${x * scaleX}px`;

    selectionOverlay.style.top =
        `${y * scaleY}px`;

    selectionOverlay.style.width =
        `${w * scaleX}px`;

    selectionOverlay.style.height =
        `${h * scaleY}px`;
}

function updateOverlay() {
    const coords = readCoordinates();

    if (!coords || !originalPageWidth || !originalPageHeight) {
        selectionOverlay.style.display = 'none';
        return;
    }

    setOverlayBox(coords.x, coords.y, coords.w, coords.h);
}

// Converte le coordinate del puntatore in punti PDF,
// indipendentemente dalla scala di visualizzazione.
function pointerToPdfPoint(event) {
    const rect = pdfCanvas.getBoundingClientRect();

    const scaleX = originalPageWidth / rect.width;
    const scaleY = originalPageHeight / rect.height;

    return {
        x: (event.clientX - rect.left) * scaleX,
        y: (event.clientY - rect.top) * scaleY
    };
}

// Selezione dell'area trascinando il mouse sulla pagina.
pdfCanvas.addEventListener('pointerdown', (event) => {
    if (!pdfDocument || !originalPageWidth) {
        return;
    }

    pdfCanvas.setPointerCapture(event.pointerId);

    selectionStart = pointerToPdfPoint(event);

    setOverlayBox(selectionStart.x, selectionStart.y, 0, 0);
});

pdfCanvas.addEventListener('pointermove', (event) => {
    if (!selectionStart) {
        return;
    }

    const point = pointerToPdfPoint(event);

    setOverlayBox(
        Math.min(selectionStart.x, point.x),
        Math.min(selectionStart.y, point.y),
        Math.abs(point.x - selectionStart.x),
        Math.abs(point.y - selectionStart.y)
    );
});

pdfCanvas.addEventListener('pointerup', (event) => {
    if (!selectionStart) {
        return;
    }

    const point = pointerToPdfPoint(event);

    const x = Math.round(Math.min(selectionStart.x, point.x));
    const y = Math.round(Math.min(selectionStart.y, point.y));
    const w = Math.round(Math.abs(point.x - selectionStart.x));
    const h = Math.round(Math.abs(point.y - selectionStart.y));

    selectionStart = null;

    if (w < 1 || h < 1) {
        updateOverlay();
        return;
    }

    fields.x.value = Math.max(0, x);
    fields.y.value = Math.max(0, y);
    fields.w.value = w;
    fields.h.value = h;

    updateOverlay();

    setStatus('Area selezionata: regola le coordinate se necessario.');
});

Object.values(fields).forEach((input) => {
    input.addEventListener('input', updateOverlay);
});

function enableTextActions(enabled) {
    copyTextButton.disabled = !enabled;
    downloadTextButton.disabled = !enabled;
}

function buildBaseFilename() {
    const base = (currentFilename || 'documento')
        .replace(/\.pdf$/i, '')
        .replace(/[^\w\-.]+/g, '_');

    return base || 'documento';
}

function downloadText(text, filename) {
    const blob = new Blob(
        [text],
        { type: 'text/plain;charset=utf-8' }
    );

    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');

    link.href = url;
    link.download = filename;

    document.body.appendChild(link);
    link.click();
    link.remove();

    URL.revokeObjectURL(url);
}

async function extractText() {
    const coords = readCoordinates();

    if (!coords) {
        setStatus(
            'Inserisci valori validi per X, Y, W e H.'
        );

        return;
    }

    const formData = new FormData();

    formData.append('action', 'extract');
    formData.append('page', currentPage);

    formData.append('x', coords.x);
    formData.append('y', coords.y);
    formData.append('w', coords.w);
    formData.append('h', coords.h);

    try {
        extractButton.disabled = true;

        setStatus('Estrazione del testo...');

        const result = await apiJson(API, {
            method: 'POST',
            body: formData
        });

        extractedText.textContent = result.text || '';

        if (!result.text) {
            extractedText.textContent =
                'Nessun testo trovato nell’area selezionata.';
        }

        enableTextActions(Boolean(result.text));

        setStatus('Estrazione completata.');

    } catch (error) {
        setStatus(error.message);

    } finally {
        extractButton.disabled = false;
    }
}

extractButton.addEventListener('click', extractText);

copyTextButton.addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText(
            extractedText.textContent
        );

        setStatus('Testo copiato negli appunti.');

    } catch (error) {
        setStatus('Impossibile copiare il testo.');
    }
});

downloadTextButton.addEventListener('click', () => {
    downloadText(
        extractedText.textContent,
        `${buildBaseFilename()}_pagina_${currentPage}.txt`
    );

    setStatus('File scaricato.');
});

// Estrae la stessa area da tutte le pagine e scarica un .txt.
async function bulkExtractAll() {
    const coords = readCoordinates();

    if (!coords) {
        setStatus(
            'Inserisci valori validi per X, Y, W e H.'
        );

        return;
    }

    const sections = [];

    try {
        extractButton.disabled = true;
        bulkExtractButton.disabled = true;
        clearAreaButton.disabled = true;

        for (
            let from = 1;
            from <= totalPageCount;
            from += BULK_CHUNK_SIZE
        ) {
            const to = Math.min(
                from + BULK_CHUNK_SIZE - 1,
                totalPageCount
            );

            setStatus(
                `Estrazione bulk: pagine ${from}-${to} di ${totalPageCount}...`
            );

            const formData = new FormData();

            formData.append('action', 'extract_bulk');
            formData.append('x', coords.x);
            formData.append('y', coords.y);
            formData.append('w', coords.w);
            formData.append('h', coords.h);
            formData.append('from', from);
            formData.append('to', to);

            const result = await apiJson(API, {
                method: 'POST',
                body: formData
            });

            for (const entry of result.pages) {
                sections.push(
                    `--- Pagina ${entry.page} ---\n` +
                    entry.text.trimEnd()
                );
            }
        }

        const text = sections.join('\n\n');

        extractedText.textContent = text ||
            'Nessun testo trovato nelle aree selezionate.';

        enableTextActions(Boolean(text));

        const filename = `${buildBaseFilename()}_aree.txt`;

        if (text) {
            downloadText(text, filename);
        }

        setStatus(
            text
                ? `Estratto: ${totalPageCount} pagine, scaricate in "${filename}".`
                : 'Nessun testo trovato nelle aree selezionate.'
        );

    } catch (error) {
        setStatus(error.message);

    } finally {
        extractButton.disabled = false;
        bulkExtractButton.disabled = false;
        clearAreaButton.disabled = false;
    }
}

bulkExtractButton.addEventListener('click', bulkExtractAll);

function clearCoordinates() {
    Object.values(fields).forEach((input) => {
        input.value = '';
    });

    selectionOverlay.style.display = 'none';

    extractedText.textContent =
        'Nessuna estrazione effettuata.';

    enableTextActions(false);
}

clearAreaButton.addEventListener('click', () => {
    clearCoordinates();

    setStatus('Area di estrazione cancellata.');
});

window.addEventListener('resize', () => {
    if (pdfDocument) {
        renderPage(currentPage);
    }
});