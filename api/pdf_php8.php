<?php
#php 8.x

declare(strict_types=1);

session_start();

const MAX_UPLOAD_SIZE = 50 * 1024 * 1024;
const MAX_UPLOAD_FILES = 100;
const SESSION_TTL = 3600;

const PDFINFO_BIN = '/usr/bin/pdfinfo';
const PDFTOTEXT_BIN = '/usr/bin/pdftotext';

const BASE_TMP = __DIR__ . '/../tmp/sessions';

header('X-Content-Type-Options: nosniff');

if (!is_dir(BASE_TMP)) {
    mkdir(BASE_TMP, 0700, true);
}

if (empty($_SESSION['pdf_id'])) {
    $_SESSION['pdf_id'] = bin2hex(random_bytes(16));
}

$sessionDir = BASE_TMP . '/' . $_SESSION['pdf_id'];

if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0700, true);
}

$metadataPath = $sessionDir . '/metadata.json';

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function runCommand(array $args): array
{
    $command = implode(
        ' ',
        array_map('escapeshellarg', $args)
    );

    $output = [];
    $exitCode = 0;

    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'code' => $exitCode,
        'output' => implode("\n", $output),
    ];
}

// Normalizza $_FILES in un elenco di file singoli, gestendo
// sia l'upload di un solo file sia l'array di piu file.
function normalizeUploadedFiles(array $file): array
{
    if (!is_array($file['name'])) {
        return [$file];
    }

    $files = [];

    foreach ($file['name'] as $index => $name) {
        $files[] = [
            'name' => $name,
            'tmp_name' => $file['tmp_name'][$index],
            'error' => $file['error'][$index],
            'size' => $file['size'][$index],
        ];
    }

    return $files;
}

// Carica i metadata e normalizza il formato: le sessioni create
// prima del supporto multi-PDF hanno solo pages/filename.
function loadMetadata(string $metadataPath, string $sessionDir): ?array
{
    if (!is_file($metadataPath)) {
        return null;
    }

    $metadata = json_decode(
        file_get_contents($metadataPath),
        true
    );

    if (!is_array($metadata)) {
        return null;
    }

    if (!isset($metadata['documents'])) {
        if (!is_file($sessionDir . '/document.pdf')) {
            return null;
        }

        $metadata['documents'] = [[
            'file' => 'document.pdf',
            'filename' => (string) ($metadata['filename'] ?? 'documento.pdf'),
            'pages' => (int) ($metadata['pages'] ?? 0),
            'offset' => 0,
        ]];
    }

    return $metadata;
}

// Traduce una pagina "globale" (nel documento composto da tutti
// i PDF caricati) nel file e nella pagina locali.
function locatePage(array $metadata, string $sessionDir, int $globalPage): ?array
{
    foreach ($metadata['documents'] as $document) {
        $offset = (int) $document['offset'];
        $pages = (int) $document['pages'];

        if ($globalPage > $offset && $globalPage <= $offset + $pages) {
            return [
                'path' => $sessionDir . '/' . $document['file'],
                'page' => $globalPage - $offset,
                'filename' => $document['filename'],
            ];
        }
    }

    return null;
}

// Legge e valida l'area di estrazione (in punti PDF) dai
// parametri POST. Restituisce null se non è valida.
function readAreaFromPost(): ?array
{
    $area = [];

    foreach (['x', 'y', 'w', 'h'] as $key) {
        $value = filter_var(
            $_POST[$key] ?? null,
            FILTER_VALIDATE_INT
        );

        if ($value === false || $value === null) {
            return null;
        }

        $area[$key] = $value;
    }

    // Limiti prudenziali, da adeguare alle esigenze applicative.
    if (
        $area['x'] < 0 || $area['y'] < 0
        || $area['w'] <= 0 || $area['h'] <= 0
        || $area['x'] > 20000 || $area['y'] > 20000
        || $area['w'] > 20000 || $area['h'] > 20000
    ) {
        return null;
    }

    return $area;
}

// Estrae il testo di una pagina nell'area indicata.
function extractAreaText(string $pdfPath, int $page, array $area): array
{
    return runCommand([
        PDFTOTEXT_BIN,
        '-f', (string) $page,
        '-l', (string) $page,
        '-x', (string) $area['x'],
        '-y', (string) $area['y'],
        '-W', (string) $area['w'],
        '-H', (string) $area['h'],
        $pdfPath,
        '-',
    ]);
}

// Traduce il codice di errore di upload di PHP in un
// messaggio leggibile per l'utente.
function uploadErrorMessage(int $error): string
{
    $limit = ini_get('upload_max_filesize');

    switch ($error) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "Il file supera i limiti di PHP "
                . "(upload_max_filesize attuale: {$limit}).";

        case UPLOAD_ERR_PARTIAL:
            return 'Il caricamento del file è stato interrotto.';

        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Cartella temporanea mancante sul server.';

        case UPLOAD_ERR_CANT_WRITE:
            return 'Impossibile scrivere il file sul disco di destinazione.';

        case UPLOAD_ERR_EXTENSION:
            return 'Un’estensione di PHP ha bloccato il caricamento.';

        default:
            return 'Errore durante il caricamento del PDF.';
    }
}

// Elimina ricorsivamente una cartella e il suo contenuto.
function deleteDirectory(string $dir): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $dir,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($dir);
}

// Elimina le sessioni scadute per evitare che i PDF
// restino su disco senza limite.
foreach (glob(BASE_TMP . '/*', GLOB_ONLYDIR) ?: [] as $oldSessionDir) {
    if (!preg_match(
        '/^[a-f0-9]{32}$/',
        basename($oldSessionDir)
    )) {
        continue;
    }

    if (time() - filemtime($oldSessionDir) < SESSION_TTL) {
        continue;
    }

    deleteDirectory($oldSessionDir);
}

// Tiene viva la sessione corrente finché è in uso.
if (is_dir($sessionDir)) {
    touch($sessionDir);
}

// Se post_max_size viene superato, $_POST e $_FILES restano
// vuoti: intercettiamo il caso per restituire un errore chiaro
// invece di una risposta vuota.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST['action'])
    && empty($_FILES)
) {
    error_log(sprintf(
        'POST senza action né file: CONTENT_LENGTH=%s, post_max_size=%s',
        $_SERVER['CONTENT_LENGTH'] ?? 'n/a',
        ini_get('post_max_size')
    ));

    jsonResponse([
        'error' => 'La richiesta supera i limiti di PHP '
            . '(post_max_size attuale: ' . ini_get('post_max_size') . ').'
            . ' Se stai usando il server di sviluppo, avvialo con '
            . '-d post_max_size=52M.',
    ], 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'upload') {

    if (!isset($_FILES['pdf'])) {
        error_log('Upload PDF fallito: nessun file ricevuto');

        jsonResponse([
            'error' => 'Errore durante il caricamento del PDF.'
        ], 400);
    }

    $files = normalizeUploadedFiles($_FILES['pdf']);

    if ($files === []) {
        jsonResponse([
            'error' => 'Nessun file ricevuto.'
        ], 400);
    }

    if (count($files) > MAX_UPLOAD_FILES) {
        jsonResponse([
            'error' => 'Troppi file: massimo ' . MAX_UPLOAD_FILES . ' PDF per volta.'
        ], 413);
    }

    // Valida tutti i file prima di toccare i documenti della
    // sessione: se uno fallisce, i PDF precedenti restano.
    $validated = [];

    foreach ($files as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            error_log(sprintf(
                'Upload PDF fallito: file=%s, error=%d, size=%s',
                $file['name'],
                $file['error'],
                $file['size']
            ));

            jsonResponse([
                'error' => "«{$file['name']}»: " . uploadErrorMessage($file['error'])
            ], 400);
        }

        if ($file['size'] > MAX_UPLOAD_SIZE) {
            jsonResponse([
                'error' => "«{$file['name']}» supera la dimensione massima consentita."
            ], 413);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);

        if ($mime !== 'application/pdf') {
            jsonResponse([
                'error' => "«{$file['name']}» non è un PDF valido (rilevato: {$mime})."
            ], 400);
        }

        // Verifica che il file sia leggibile come PDF.
        $info = runCommand([
            PDFINFO_BIN,
            $file['tmp_name']
        ]);

        if ($info['code'] !== 0) {
            jsonResponse([
                'error' => "«{$file['name']}» non è leggibile come PDF."
            ], 400);
        }

        if (!preg_match(
            '/^Pages:\s+(\d+)\s*$/mi',
            $info['output'],
            $matches
        )) {
            jsonResponse([
                'error' => "Numero di pagine non disponibile per «{$file['name']}»."
            ], 400);
        }

        $file['pages'] = (int) $matches[1];

        if ($file['pages'] < 1) {
            jsonResponse([
                'error' => "«{$file['name']}» non contiene pagine."
            ], 400);
        }

        $validated[] = $file;
    }

    // Elimina i documenti della sessione precedente (formato
    // single-PDF e multi-PDF).
    foreach (glob($sessionDir . '/document*.pdf') ?: [] as $oldDocument) {
        unlink($oldDocument);
    }

    // Salva ogni documento e costruisce la mappa delle pagine
    // globali (offset cumulativo).
    $documents = [];
    $offset = 0;

    foreach ($validated as $index => $file) {
        $storedName = 'document_' . $index . '.pdf';

        if (!move_uploaded_file(
            $file['tmp_name'],
            $sessionDir . '/' . $storedName
        )) {
            jsonResponse([
                'error' => "Impossibile salvare «{$file['name']}»."
            ], 500);
        }

        $documents[] = [
            'file' => $storedName,
            'filename' => basename($file['name']),
            'pages' => $file['pages'],
            'offset' => $offset,
        ];

        $offset += $file['pages'];
    }

    $metadata = [
        'pages' => $offset,
        'filename' => $documents[0]['filename'],
        'documents' => $documents,
        'uploaded_at' => date(DATE_ATOM),
    ];

    file_put_contents(
        $metadataPath,
        json_encode($metadata, JSON_PRETTY_PRINT),
        LOCK_EX
    );

    jsonResponse([
        'success' => true,
        'pages' => $offset,
        'filename' => $metadata['filename'],
        'files' => array_map(static function (array $document): array {
            return [
                'filename' => $document['filename'],
                'pages' => $document['pages'],
            ];
        }, $documents),
    ]);
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'extract'
) {
    $metadata = loadMetadata($metadataPath, $sessionDir);

    if ($metadata === null) {
        jsonResponse([
            'error' => 'Nessun PDF caricato.'
        ], 400);
    }

    $totalPages = (int) ($metadata['pages'] ?? 0);

    $page = filter_var(
        $_POST['page'] ?? null,
        FILTER_VALIDATE_INT
    );

    $area = readAreaFromPost();

    if (
        $page === false || $page === null
        || $page < 1 || $page > $totalPages
        || $area === null
    ) {
        jsonResponse([
            'error' => 'Coordinate o numero di pagina non validi.'
        ], 400);
    }

    $target = locatePage($metadata, $sessionDir, $page);

    if ($target === null) {
        jsonResponse([
            'error' => 'Numero di pagina non valido.'
        ], 400);
    }

    $result = extractAreaText($target['path'], $target['page'], $area);

    if ($result['code'] !== 0) {
        jsonResponse([
            'error' => 'Errore durante l’estrazione del testo.'
        ], 500);
    }

    jsonResponse([
        'success' => true,
        'page' => $page,
        'area' => $area,
        'text' => $result['output'],
    ]);
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'extract_bulk'
) {
    $metadata = loadMetadata($metadataPath, $sessionDir);

    if ($metadata === null) {
        jsonResponse([
            'error' => 'Nessun PDF caricato.'
        ], 400);
    }

    $totalPages = (int) ($metadata['pages'] ?? 0);

    $area = readAreaFromPost();

    if ($totalPages < 1 || $area === null) {
        jsonResponse([
            'error' => 'Coordinate non valide.'
        ], 400);
    }

    // Intervallo pagine opzionale, per l'estrazione a chunk.
    $from = filter_var(
        $_POST['from'] ?? 1,
        FILTER_VALIDATE_INT
    );

    $to = filter_var(
        $_POST['to'] ?? $totalPages,
        FILTER_VALIDATE_INT
    );

    if ($from === false || $from === null || $from < 1) {
        $from = 1;
    }

    if ($to === false || $to === null || $to > $totalPages) {
        $to = $totalPages;
    }

    if ($from > $to) {
        jsonResponse([
            'error' => 'Intervallo di pagine non valido.'
        ], 400);
    }

    $pages = [];

    for ($page = $from; $page <= $to; $page++) {
        $target = locatePage($metadata, $sessionDir, $page);

        if ($target === null) {
            jsonResponse([
                'error' => "Numero di pagina non valido: {$page}."
            ], 400);
        }

        $result = extractAreaText($target['path'], $target['page'], $area);

        if ($result['code'] !== 0) {
            jsonResponse([
                'error' => "Errore durante l’estrazione della pagina {$page}."
            ], 500);
        }

        $pages[] = [
            'page' => $page,
            'source' => $target['filename'],
            'text' => $result['output'],
        ];
    }

    jsonResponse([
        'success' => true,
        'from' => $from,
        'to' => $to,
        'area' => $area,
        'pages' => $pages,
    ]);
}