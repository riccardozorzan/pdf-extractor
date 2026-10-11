pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

const API = '../api/pdf.php';

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

uploadForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const file = pdfFile.files[0];

    if (!file) {
        setStatus('Seleziona un PDF.');
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

        // PDF.js carica il documento originale dal browser.
        const arrayBuffer = await file.arrayBuffer();

        pdfDocument = await pdfjsLib.getDocument({
            data: arrayBuffer
        }).promise;

        totalPageCount = result.pages;
        currentPage = 1;

        totalPages.textContent = totalPageCount;

        pageNumber.disabled = false;
        prevPage.disabled = false;
        nextPage.disabled = false;

        extractButton.disabled = false;
        clearAreaButton.disabled = false;

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

        // Richiede al backend la pagina estratta con PDFtk.
        // La risposta viene usata per verificare la disponibilità
        // della pagina nella cache del server.
        const response = await fetch(
            `${API}?action=page&page=${page}`
        );

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));

            throw new Error(
                data.error || 'Impossibile recuperare la pagina.'
            );
        }

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

function updateOverlay() {
    const coords = readCoordinates();

    if (!coords || !originalPageWidth || !originalPageHeight) {
        selectionOverlay.style.display = 'none';
        return;
    }

    const scaleX = pdfCanvas.clientWidth / originalPageWidth;
    const scaleY = pdfCanvas.clientHeight / originalPageHeight;

    selectionOverlay.style.display = 'block';

    selectionOverlay.style.left =
        `${coords.x * scaleX}px`;

    selectionOverlay.style.top =
        `${coords.y * scaleY}px`;

    selectionOverlay.style.width =
        `${coords.w * scaleX}px`;

    selectionOverlay.style.height =
        `${coords.h * scaleY}px`;
}

Object.values(fields).forEach((input) => {
    input.addEventListener('input', updateOverlay);
});

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

        setStatus('Estrazione completata.');

    } catch (error) {
        setStatus(error.message);

    } finally {
        extractButton.disabled = false;
    }
}

extractButton.addEventListener('click', extractText);

function clearCoordinates() {
    Object.values(fields).forEach((input) => {
        input.value = '';
    });

    selectionOverlay.style.display = 'none';

    extractedText.textContent =
        'Nessuna estrazione effettuata.';
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