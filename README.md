# Product GPT Importer (Free)

Product GPT Importer è un plugin WordPress pensato per creare prodotti WooCommerce partendo da documenti `.docx`, file `.pdf` o semplici URL. La generazione dei contenuti viene affidata a GPT: basta caricare la scheda tecnica (o indicare un link) e il plugin produce automaticamente titolo, descrizioni, attributi, immagini e tutti i campi essenziali del prodotto.

## Come funziona
1. **Scegli la sorgente dei dati**: carica un file `.docx`, `.pdf` oppure inserisci l'URL di una pagina prodotto esterna.
2. **Configura il profilo AI**: imposta i prompt che guidano GPT nell'estrazione e nella riscrittura dei contenuti.
3. **Avvia l'importazione**: il plugin legge i dati della scheda tecnica, interroga l'API OpenAI e genera un prodotto WooCommerce pronto per essere pubblicato.

## Limitazioni della versione gratuita
Questa versione ti permette di utilizzare la tua chiave OpenAI per testare il plugin, con alcuni limiti pensati per evitare un uso intensivo:

* Massimo **3 richieste** all'ora verso l'API OpenAI.
* Fino a **2 profili AI salvati** (incluso quello predefinito).
* **Colori personalizzati**, importazione **multiprodotto** e **anteprima batch** sono riservati alla versione Premium.

## Profili AI: system prompt e prompt utente
Ogni profilo AI contiene due parti distinte:

* **System prompt** – definisce il tono e il comportamento generale dell'assistente GPT (es. settore merceologico, requisiti linguistici, stile di scrittura).
* **Prompt utente** – contiene le istruzioni operative che verranno compilate con i dati estratti da file o URL. Qui puoi specificare quali campi recuperare, come strutturare le descrizioni, quali attributi trasformare in JSON, ecc.

Grazie ai profili puoi adattare l'importazione a diversi cataloghi (gioielli, elettronica, abbigliamento…) salvando per ognuno le regole che preferisci.

### Esempio: profilo “Gioielli e Orologi”
**System prompt**
```
Questa versione gratuita ti permette di utilizzare la tua chiave OpenAI con alcune limitazioni pensate per testare il plugin.

Massimo 3 richieste all'ora verso l'API.
Fino a 2 profili AI salvati (incluso quello predefinito).
Colori personalizzati, multiprodotto e anteprima batch riservati alla versione Premium.
```

**Prompt utente**
```
Estrai dalla seguente scheda tecnica TUTTI i dati caratteristici e restituiscili in JSON come campi attributo WooCommerce.
Il JSON deve contenere i campi:
- title
- short_description (descrizione breve nello stile seguente):
  Garanzia 24 mesi
  Referenza: prendere referenza presenti nella scheda
  Anno: prendere anno presenti nella scheda
  Corredo: prendere corredo presenti nella scheda
  Specifiche: prendere specifiche presenti nella scheda
  Tipo: (specificare se nuovo o secondo polso)
  Disponibilità immediata
- long_description (descrizione arricchita e dettagliata, almeno 100-150 parole)
- price
- stock_status
- category (scegli tra le categorie esistenti, es. "Orologi")
- sku
- seo_keywords (array)
Nel campo "attributes" includi tutte le voci trovate, anche se non sono sempre presenti, tra cui (ma non solo):
- Marca
- Modello
- Referenza
- Stato del prodotto
- Anno
- Diametro
- Condizioni
- Garanzia
- Certificato
- Scatola
- Movimento
- Cassa
- Bracciale/Cinturino
- Chiusura
- Calibro
- Quadrante
- Ghiera/Lunetta
- Vetro
- Impermeabilità
- Genere
- Corredo
- Disponibilità
Restituisci gli attributi come oggetto del tipo:
"attributes": {
  "Marca": "Rolex",
  "Modello": "Datejust",
  "Referenza": "16233",
  ...
}
Se un campo non c’è, omettilo.

- shipping_class ("Italia" se presente)

Scheda tecnica:
{$content}
```

Il placeholder `{$content}` verrà sostituito automaticamente dal testo estratto dal file o dalla pagina web.

## Schema operativo per l’estrazione dei dati
```
Seleziona sorgente (DOCX / PDF / URL)
        │
        ▼
Estrazione automatica del contenuto
        │
        ▼
Applicazione profilo AI (system prompt + prompt utente)
        │
        ▼
Chiamata API OpenAI (fino a 3/h nella versione Free)
        │
        ▼
Generazione prodotto WooCommerce (titoli, descrizioni, attributi, immagini)
```

## Packaging del plugin
Per creare uno zip distribuibile:
```bash
git archive --format=zip HEAD -o product-gpt-importer.zip
```
Carica quindi `product-gpt-importer.zip` dal gestore plugin di WordPress.
