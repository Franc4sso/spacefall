# Starfall Station

Sei l'ultimo sopravvissuto su una stazione spaziale danneggiata. Ogni giorno
affronti poche **carte decisione** e sopravvivi, oppure no.

Un fondersi di *Reigns* e *60 Seconds*: carte rapide e taglienti con 2–3 scelte,
che **ricordano cosa hai fatto** e te lo rinfacciano più avanti; e il peso del
**razionamento** di scorte scarse fra i sopravvissuti. Niente trama scritta —
le storie *emergono* dall'interazione dei sistemi.

Una run dura ~30–60 minuti. La maggior parte delle prime partite finisce in
morte. Perdi sempre per **decisioni accumulate**, mai per un evento casuale
inevitabile.

## Stack

- **Backend:** Laravel 12, PHP 8.2, Pest, API REST/JSON, SQLite.
- **Frontend:** React + TypeScript + Vite, Tailwind, Vitest + Testing Library.

Stato server-autoritativo: il browser è solo un renderer; tutte le regole vivono
nell'API. Tutto il contenuto (eventi, item, personaggi, finali) è **dati nel
database** — aggiungere un evento è una riga di seeder, mai codice del motore.

## Avvio

```bash
# backend
cd backend
composer install
php artisan migrate --seed
php artisan serve            # http://localhost:8000
php artisan test

# frontend
cd frontend
npm install
npm run dev                  # http://localhost:5173
npm run test
```

Se l'API non è sulla porta 8000, imposta `VITE_API_URL` in `frontend/.env`
(e `CORS_ALLOWED_ORIGINS` in `backend/.env`).

## Bilanciamento

L'auto-player headless guida la taratura:

```bash
php artisan sim:run --count=5000 --policy=greedy_survival
php artisan sim:run --count=5000 --policy=random
```

Tutto il bilanciamento è tarato modificando **dati** (config + contenuto), mai
il motore. Vedi `PROGRESS.md` per il diario di sviluppo per fasi.

## Lingua

Testo di gioco in **italiano**, codice (chiavi, flag, identificatori) in
**inglese**. La separazione è netta e voluta.
