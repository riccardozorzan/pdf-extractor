<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>PDF Extractor</title>

    <link rel="stylesheet" href="style.css">
</head>

<body>

    <header>
        <h1>PDF Extractor</h1>
    </header>

    <main>

        <section class="toolbar">

            <form id="uploadForm" class="dropzone">
                <label for="pdfFile">
                    Seleziona uno o più PDF
                </label>

                <input
                    type="file"
                    id="pdfFile"
                    name="pdf"
                    accept=".pdf,application/pdf"
                    multiple
                    required
                >

                <button type="submit">
                    Carica PDF
                </button>

                <span class="dropHint">
                    oppure trascina qui i file
                </span>
            </form>

            <p class="limits">
                Limiti di caricamento:
                <strong id="limitsText">100 file, 50 MB per file</strong>
            </p>

            <p id="uploadSelection" class="limits selection" hidden></p>

        </section>

        <section class="viewer">

            <div class="pagination">

                <button id="prevPage" disabled>
                    ← Precedente
                </button>

                <span>
                    Pagina

                    <input
                        type="number"
                        id="pageNumber"
                        min="1"
                        value="1"
                        disabled
                    >

                    di

                    <span id="totalPages">0</span>
                </span>

                <button id="nextPage" disabled>
                    Successiva →
                </button>

            </div>

            <div id="pdfContainer">

                <p id="emptyMessage">
                    Carica un PDF per iniziare.
                </p>

                <div id="pageWrapper" hidden>
                    <canvas id="pdfCanvas"></canvas>

                    <div id="selectionOverlay"></div>
                </div>

            </div>

        </section>

        <section class="extractor">

            <h2>Area di estrazione</h2>

            <p>
                Inserisci le coordinate in punti PDF.
                Lascia i campi vuoti per non effettuare
                alcuna estrazione.
            </p>

            <div class="coordinates">

                <label>
                    X
                    <input
                        type="number"
                        id="coordX"
                        min="0"
                        step="1"
                        placeholder="X"
                    >
                </label>

                <label>
                    Y
                    <input
                        type="number"
                        id="coordY"
                        min="0"
                        step="1"
                        placeholder="Y"
                    >
                </label>

                <label>
                    W
                    <input
                        type="number"
                        id="coordW"
                        min="1"
                        step="1"
                        placeholder="Larghezza"
                    >
                </label>

                <label>
                    H
                    <input
                        type="number"
                        id="coordH"
                        min="1"
                        step="1"
                        placeholder="Altezza"
                    >
                </label>

            </div>

            <div class="actions">

                <button id="extractButton" disabled>
                    Estrai testo
                </button>

                <button id="bulkExtractButton" disabled>
                    Estrai da tutte le pagine
                </button>

                <button id="clearAreaButton" disabled>
                    Cancella area
                </button>

            </div>

            <h3>Testo estratto</h3>

            <div class="text-actions">

                <button id="copyTextButton" disabled>
                    Copia negli appunti
                </button>

                <button id="downloadTextButton" disabled>
                    Scarica .txt
                </button>

            </div>

            <pre id="extractedText">Nessuna estrazione effettuata.</pre>

        </section>

        <p id="status" role="status"></p>

    </main>

    <script
        src="vendor/pdfjs/pdf.min.js"
    ></script>

    <script src="app.js"></script>

</body>
</html>
