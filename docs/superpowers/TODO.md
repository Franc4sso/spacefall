# Spacefall — Cose che restano da fare

> Stato aggiornato al 2026-06-08. Fonte di verità corrente per il lavoro futuro (sostituisce le parti datate di `roadmap.md`). Ogni voce "feature" si costruisce come le altre: brainstorming → spec → piano → esecuzione subagent.

---

## ✅ Fatto (per contesto)
- **Redesign cosmico** + loop di gioco (il giorno avanza, le silent card non si bloccano).
- **Equipaggio vivo**: fili dei personaggi, standing, reazioni parlate, Diario.
- **La Fame**: fame per personaggio, marea di carte (pasto/triage/sacrificio), macchina del cibo, oscillazioni, bilanciata col simulatore.
- **Spedizioni**: manda qualcuno fuori, stato "presente vs via", esiti pesati (ExpeditionResolver), ritorni.
- **Rifiniture**: fix card a opzione singola spuria, `technician_ghost` calmierato, griglia oggetti curata a 9 (finti rimossi).
- 183 test verdi, TS pulito.

---

## 🔭 Feature ancora da costruire (in ordine di impatto)

### Tier 1 — alto impatto
1. **Oggetti-strumento nelle crisi comuni** *(prossima consigliata)*
   Le ~15 carte più frequenti ottengono scelte condizionate all'oggetto: la breccia col welder la saldi, con la tuta esci a ripararla, col toolkit improvvisi. Oggi solo gli oggetti-cibo e l'attrezzatura-spedizioni interagiscono; questo estende l'interazione a *tutte* le crisi ed è l'arma più efficiente contro la ripetizione. ~8–10 task.

### Tier 2 — profondità e varietà
2. **Struttura ad Atti / Fasi** — la run evolve (Isolamento → Deterioramento → Resa dei conti); pool diversi per fase, così il giorno 30 ≠ giorno 3. ~6–8 task.
3. **Archi narrativi degli oggetti** — ogni oggetto-bandiera una mini-storia in tappe (l'archivio svela l'equipaggio precedente, la banca semi un orto da curare). ~6–10 task.
4. **Relazioni d'equipaggio nel contenuto** — il sistema legame/tensione/odio esiste ma è sottoutilizzato: coppie che si legano/odiano e che tu influenzi, con effetti (in spedizione, nelle crisi). ~5–7 task.

### Tier 3 — pagamento emotivo e rigiocabilità
5. **Epiloghi personalizzati** — il finale racconta *la tua* run: chi è morto e come, le scelte chiave, l'epiteto, un "che ne è stato di loro" per superstite. ~4–6 task.
6. **Meta-progressione cross-run** — sfruttare il sistema profilo/unlock esistente: memoria cross-run, scenari di partenza, sblocco progressivo degli oggetti bloccati (vedi sotto). ~6–8 task.

### Tier 4 — feel e accessibilità (poco lavoro, grande resa)
7. **Polish sensoriale** — ronzio ambientale, suoni UI, transizioni delle carte, feedback sulle scelte pesanti. ~3–5 task.
8. **Onboarding leggero** — tooltip o prima run guidata per fame/standing/oggetti/spedizioni. ~3–5 task.

---

## 🧵 Fili lasciati in sospeso (debito / rifiniture dal lavoro fatto)

- **Contenuto oggetti dormiente.** I 6 oggetti bloccati (toolkit, manual, reactor_cell, sensors, flare, logbank) hanno eventi che oggi non si attivano mai (non sono nella griglia). Vanno o **risvegliati** dal sistema di meta-sblocco (Tier 3 #6), o i loro eventi riassegnati a oggetti in griglia, o rimossi. Decisione da prendere.
- **Flag-testimone senza lettori.** `cannibalism`, `lost_on_expedition`, `died_of_hunger`, `bex_saw_death` vengono impostati ma nessun finale/evento li legge ancora. Sono ganci pronti per gli **epiloghi** (Tier 3 #5) o finali dedicati (es. un finale "il prezzo della fame").
- **Testo dei ritorni spedizione generico.** Gli `exp_return_*` dicono "chi avevi mandato" invece del nome. Personalizzarli (speaker = expeditioner) darebbe più voce. Piccolo.
- **Cadenza della Fame = leva di tuning.** Il pasto compare ~ogni 4-5 giorni per il giocatore bravo; va validato col tuo playtest e tarato a gusto (soglia `spawn_bands`, `daily_rise`). Solo numeri in `config/game.php`.
- **Difficoltà spedizioni = leva di tuning.** Le probabilità (`ExpeditionResolver`) e le durate/pericolosità delle mete sono punti di partenza; da tarare col playtest.
- **Card a scelta singola narrative.** Restano alcuni "momenti" a una sola opzione (fantasmi/ricordi, gated e rari). Intenzionali, ma alcuni si potrebbero arricchire con una seconda scelta.
- **Pool eventi base vs nuovo.** Coesistono `EventSeeder` (contenuto originale: technician_*, ration_night, ecc.) e `ContentEventSeeder` (tutto il nuovo). Nessun problema funzionale, ma un giorno conviene armonizzare tono/peso tra i due.

---

## ▶️ Raccomandazione
Prossima fetta: **Oggetti-strumento nelle crisi comuni** (Tier 1 #1) — chiude il cerchio su ripetizione + interazione oggetti, e riusa eventi esistenti (efficiente). In parallelo, quando vuoi una pausa dal "grande", **Epiloghi** (#5) e **Polish** (#7) sono fette piccole ad alto ritorno che sfruttano i ganci già pronti (flag-testimone).
