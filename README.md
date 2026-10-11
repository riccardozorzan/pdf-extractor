# PDF Extractor

Web app PHP + JavaScript per l'estrazione del testo da PDF tramite
**area selezionata** su ogni pagina.

## Funzionalità

- Caricamento PDF via pulsante o **trascinamento** (max 50 MB)
- Visualizzazione delle pagine con PDF.js
- Selezione dell'area di estrazione **trascinando il mouse** sulla pagina
  (oppure digitando a mano le coordinate in punti PDF)
- Estrazione del testo dell'area sulla pagina corrente
- **Estrazione bulk**: la stessa area viene estratta da tutte le pagine
  e il risultato viene scaricato automaticamente come file `.txt`
- Copia del testo negli appunti e download `.txt` dell'area corrente
- Le sessioni (PDF caricati) vengono automaticamente cancellate dopo 1 ora

## Requisiti

- PHP **5.5+** oppure PHP **8.1+** (vedi sotto il meccanismo di selezione)
  con estensione `fileinfo` (e `openssl` consigliata sui PHP 5.x)
- `pdfinfo` e `pdftotext` (pacchetto `poppler-utils`)
- Nessuna dipendenza Composer, nessun database

## Avvio

Dalla root del progetto:

```bash
php -d upload_max_filesize=50M -d post_max_size=52M -S localhost:8000
```

> I flag `-d` sono necessari perché i default di PHP (`upload_max_filesize=2M`,
> `post_max_size=8M`) rifiutano PDF più grandi di 2 MB con un errore 400.

Poi apri <http://localhost:8000/public/>.

> Per il deployment in produzione servono un virtual host con document root
> impostata sulla root del progetto (l'API in `api/` è richiamata da
> `public/app.js` come `../api/pdf.php`).

## Struttura

```
api/pdf.php        Dispatcher: sceglie l'API in base alla versione di PHP
api/pdf_php8.php   Implementazione moderna (PHP >= 8.1)
api/pdf_php55.php  Port compatibile PHP 5.5+ (usato anche su 7.x)
public/index.php   Interfaccia
public/app.js      Logica lato client (PDF.js)
public/style.css   Stili
tmp/sessions/      PDF caricati, per sessione (auto-puliti, non nel repo)
```

### Selezione automatica dell'API per versione di PHP

`api/pdf.php` è un dispatcher scritto in codice parsabile da PHP 5.5:
al caricamento controlla `PHP_VERSION_ID` e include l'implementazione
adatta, senza che il frontend debba sapere nulla della versione in uso.

| Versione di PHP | Implementazione |
|---|---|
| >= 8.1 | `pdf_php8.php` (`declare(strict_types)`, tipi, `never`) |
| < 8.1 (incluse 5.5–8.0) | `pdf_php55.php` (stesse funzionalità, stile 5.5) |

> Nota: PHP 5.5 è EOL dal 2016; il port è pensato per hosting legacy.
> Testato con lint ed e2e su container `php:5.6-cli` (l'immagine ufficiale
> 5.5 non è più scaricabile da Docker Hub).

## API

Tutte le azioni sono POST su `api/pdf.php` con `application/x-www-form-urlencoded`
o `multipart/form-data`; lo stato è mantenuto via cookie di sessione.

| action | Parametri | Descrizione |
|---|---|---|
| `upload` | `pdf` (file) | Carica e valida il PDF, restituisce `pages` e `filename` |
| `extract` | `page`, `x`, `y`, `w`, `h` | Testo dell'area su una pagina |
| `extract_bulk` | `x`, `y`, `w`, `h`, `from`, `to` | Testo dell'area su un intervallo di pagine |

Le coordinate sono in punti PDF, origine in alto a sinistra.
