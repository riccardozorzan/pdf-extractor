<?php
# php 5.5+ (compatibile anche con PHP 7.x e 8.x)
#
# Port per PHP 5.5 dell'API PDF Extractor: stesse funzionalità
# di pdf_php8.php (upload, extract, extract_bulk, GC sessioni,
# messaggi d'errore diagnostici) senza costrutti PHP 7/8.
# Le costanti sono definite con define() per massima
# compatibilità con le versioni più vecchie di PHP.

session_start();

define('MAX_UPLOAD_SIZE', 50 * 1024 * 1024);
define('SESSION_TTL', 3600);

define('PDFINFO_BIN', '/usr/bin/pdfinfo');
define('PDFTOTEXT_BIN', '/usr/bin/pdftotext');

define('BASE_TMP', __DIR__ . '/../tmp/sessions');

// Evita il warning "not safe to rely on the system's timezone
// settings" di PHP 5.x/7.x quando date.timezone non è impostato.
if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

header('X-Content-Type-Options: nosniff');

if (!is_dir(BASE_TMP)) {
    mkdir(BASE_TMP, 0700, true);
}

// Genera un id sessione di 32 caratteri esadecimali.
// random_bytes esiste solo da PHP 7: si usa openssl o
// /dev/urandom con un ultimo ripiego su uniqid.
function generateSessionId()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(16));
    }

    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes(16);

        if ($bytes !== false) {
            return bin2hex($bytes);
        }
    }

    if (@is_readable('/dev/urandom')) {
        $handle = @fopen('/dev/urandom', 'rb');

        if ($handle !== false) {
            $bytes = fread($handle, 16);
            fclose($handle);

            if ($bytes !== false && strlen($bytes) === 16) {
                return bin2hex($bytes);
            }
        }
    }

    return md5(uniqid(mt_rand(), true));
}

if (empty($_SESSION['pdf_id'])) {
    $_SESSION['pdf_id'] = generateSessionId();
}

$sessionDir = BASE_TMP . '/' . $_SESSION['pdf_id'];

if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0700, true);
}

$pdfPath = $sessionDir . '/document.pdf';
$metadataPath = $sessionDir . '/metadata.json';

function jsonResponse(array $data, $status = 200)
{
    http_response_code($status);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function runCommand(array $args)
{
    $command = implode(
        ' ',
        array_map('escapeshellarg', $args)
    );

    $output = array();
    $exitCode = 0;

    exec($command . ' 2>&1', $output, $exitCode);

    return array(
        'code' => $exitCode,
        'output' => implode("\n", $output),
    );
}

// Legge e valida l'area di estrazione (in punti PDF) dai
// parametri POST. Restituisce null se non è valida.
function readAreaFromPost()
{
    $area = array();

    foreach (array('x', 'y', 'w', 'h') as $key) {
        $value = filter_var(
            isset($_POST[$key]) ? $_POST[$key] : null,
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
function extractAreaText($pdfPath, $page, array $area)
{
    return runCommand(array(
        PDFTOTEXT_BIN,
        '-f', (string) $page,
        '-l', (string) $page,
        '-x', (string) $area['x'],
        '-y', (string) $area['y'],
        '-W', (string) $area['w'],
        '-H', (string) $area['h'],
        $pdfPath,
        '-',
    ));
}

// Traduce il codice di errore di upload di PHP in un
// messaggio leggibile per l'utente.
function uploadErrorMessage($error)
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
function deleteDirectory($dir)
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
$oldSessions = glob(BASE_TMP . '/*', GLOB_ONLYDIR);

if ($oldSessions === false) {
    $oldSessions = array();
}

foreach ($oldSessions as $oldSessionDir) {
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
    isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST['action'])
    && empty($_FILES)
) {
    error_log(sprintf(
        'POST senza action né file: CONTENT_LENGTH=%s, post_max_size=%s',
        isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 'n/a',
        ini_get('post_max_size')
    ));

    jsonResponse(array(
        'error' => 'La richiesta supera i limiti di PHP '
            . '(post_max_size attuale: ' . ini_get('post_max_size') . ').'
            . ' Se stai usando il server di sviluppo, avvialo con '
            . '-d post_max_size=52M.',
    ), 400);
}

if (
    isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['action']) ? $_POST['action'] : '') === 'upload'
) {

    if (
        !isset($_FILES['pdf'])
        || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK
    ) {
        $uploadError = isset($_FILES['pdf']['error'])
            ? (int) $_FILES['pdf']['error']
            : -1;

        error_log(sprintf(
            'Upload PDF fallito: error=%d, size=%s',
            $uploadError,
            isset($_FILES['pdf']['size']) ? $_FILES['pdf']['size'] : 'n/a'
        ));

        jsonResponse(array(
            'error' => uploadErrorMessage($uploadError)
        ), 400);
    }

    $file = $_FILES['pdf'];

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        jsonResponse(array(
            'error' => 'Il PDF supera la dimensione massima consentita.'
        ), 413);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if ($mime !== 'application/pdf') {
        jsonResponse(array(
            'error' => "Il file caricato non è un PDF valido (rilevato: {$mime})."
        ), 400);
    }

    // Verifica che il file sia leggibile come PDF.
    $info = runCommand(array(
        PDFINFO_BIN,
        $file['tmp_name']
    ));

    if ($info['code'] !== 0) {
        jsonResponse(array(
            'error' => 'Impossibile leggere il documento PDF.'
        ), 400);
    }

    // Estrazione del numero di pagine.
    if (!preg_match(
        '/^Pages:\s+(\d+)\s*$/mi',
        $info['output'],
        $matches
    )) {
        jsonResponse(array(
            'error' => 'Numero di pagine non disponibile.'
        ), 400);
    }

    $pages = (int) $matches[1];

    if ($pages < 1) {
        jsonResponse(array(
            'error' => 'Il PDF non contiene pagine.'
        ), 400);
    }

    // Sostituisce il documento della sessione.
    if (!move_uploaded_file(
        $file['tmp_name'],
        $pdfPath
    )) {
        jsonResponse(array(
            'error' => 'Impossibile salvare il PDF.'
        ), 500);
    }

    $metadata = array(
        'pages' => $pages,
        'filename' => basename($file['name']),
        'uploaded_at' => date(DATE_ATOM),
    );

    file_put_contents(
        $metadataPath,
        json_encode($metadata, JSON_PRETTY_PRINT),
        LOCK_EX
    );

    jsonResponse(array(
        'success' => true,
        'pages' => $pages,
        'filename' => $metadata['filename'],
    ));
}

if (
    isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['action']) ? $_POST['action'] : '') === 'extract'
) {
    if (!is_file($pdfPath) || !is_file($metadataPath)) {
        jsonResponse(array(
            'error' => 'Nessun PDF caricato.'
        ), 400);
    }

    $metadata = json_decode(
        file_get_contents($metadataPath),
        true
    );

    $totalPages = (int) (isset($metadata['pages']) ? $metadata['pages'] : 0);

    $page = filter_var(
        isset($_POST['page']) ? $_POST['page'] : null,
        FILTER_VALIDATE_INT
    );

    $area = readAreaFromPost();

    if (
        $page === false || $page === null
        || $page < 1 || $page > $totalPages
        || $area === null
    ) {
        jsonResponse(array(
            'error' => 'Coordinate o numero di pagina non validi.'
        ), 400);
    }

    $result = extractAreaText($pdfPath, $page, $area);

    if ($result['code'] !== 0) {
        jsonResponse(array(
            'error' => 'Errore durante l’estrazione del testo.'
        ), 500);
    }

    jsonResponse(array(
        'success' => true,
        'page' => $page,
        'area' => $area,
        'text' => $result['output'],
    ));
}

if (
    isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['action']) ? $_POST['action'] : '') === 'extract_bulk'
) {
    if (!is_file($pdfPath) || !is_file($metadataPath)) {
        jsonResponse(array(
            'error' => 'Nessun PDF caricato.'
        ), 400);
    }

    $metadata = json_decode(
        file_get_contents($metadataPath),
        true
    );

    $totalPages = (int) (isset($metadata['pages']) ? $metadata['pages'] : 0);

    $area = readAreaFromPost();

    if ($totalPages < 1 || $area === null) {
        jsonResponse(array(
            'error' => 'Coordinate non valide.'
        ), 400);
    }

    // Intervallo pagine opzionale, per l'estrazione a chunk.
    $from = filter_var(
        isset($_POST['from']) ? $_POST['from'] : 1,
        FILTER_VALIDATE_INT
    );

    $to = filter_var(
        isset($_POST['to']) ? $_POST['to'] : $totalPages,
        FILTER_VALIDATE_INT
    );

    if ($from === false || $from === null || $from < 1) {
        $from = 1;
    }

    if ($to === false || $to === null || $to > $totalPages) {
        $to = $totalPages;
    }

    if ($from > $to) {
        jsonResponse(array(
            'error' => 'Intervallo di pagine non valido.'
        ), 400);
    }

    $pages = array();

    for ($page = $from; $page <= $to; $page++) {
        $result = extractAreaText($pdfPath, $page, $area);

        if ($result['code'] !== 0) {
            jsonResponse(array(
                'error' => "Errore durante l’estrazione della pagina {$page}."
            ), 500);
        }

        $pages[] = array(
            'page' => $page,
            'text' => $result['output'],
        );
    }

    jsonResponse(array(
        'success' => true,
        'from' => $from,
        'to' => $to,
        'area' => $area,
        'pages' => $pages,
    ));
}



