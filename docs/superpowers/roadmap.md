# Spacefall — Roadmap (cosa costruire dopo)

> Roadmap ragionata, in ordine di impatto. Ogni voce è una *fetta* a sé: si specifica (brainstorming → spec) e si pianifica (plan → subagent) come abbiamo fatto per Living Crew e La Fame. Le stime sono in "task" del nostro formato (≈ una sessione subagent ciascuno).

## Stato attuale (fatto)
- **Redesign cosmico** + nuovo loop di gioco (mergiato).
- **Equipaggio vivo**: fili dei personaggi, standing, reazioni parlate, Diario (mergiato).
- **Fix loop**: il giorno avanza, silent card non si bloccano (mergiato).
- **La Fame**: fame per personaggio, marea di carte (pasto/triage/sacrificio), macchina del cibo, oscillazioni, bilanciata col simulatore (branch `hunger`, pronto al merge).

---

## TIER 1 — Massimo impatto (le cose già promesse al giocatore)

### 1. Spedizioni *(consigliata come prossima)*
Mandi un personaggio fuori dalla stazione; resta **via** per N giorni; torna con bottino/notizie, **oppure non torna**. La sedia vuota a tavola è tensione pura.
- **Perché**: l'hai chiesta esplicitamente. È la fetta di *agency* che manca — finora reagisci alle carte, qui **decidi tu** di agire. Lega tutto: chi mandi (equipaggio + standing), con che attrezzatura (oggetti), per procurare cosa (cibo → Fame).
- **Nuovo nel motore**: stato "in spedizione" sui personaggi (assente dal tavolo per N giorni), evento di ritorno schedulato con esiti ramificati. Un affamato/stressato rende peggio (gancio già previsto).
- **Stima**: ~10–14 task. Media-grande.

### 2. Oggetti-strumento nelle crisi comuni
Le ~15 carte che vedi più spesso ottengono **scelte condizionate all'oggetto**: la breccia col welder la saldi, con la tuta esci a ripararla, col toolkit improvvisi. Le stesse situazioni si giocano diversamente col tuo kit.
- **Perché**: è l'arma più **efficiente** contro la ripetizione (riusa eventi esistenti) e mantiene la promessa "gli oggetti contano" su tutto il gioco, non solo sul cibo.
- **Stima**: ~8–10 task. Media.

---

## TIER 2 — Profondità e varietà (contro la "ripetizione")

### 3. Struttura ad Atti / Fasi
La run **evolve**: Isolamento (g.1–10) → Deterioramento (11–22) → Resa dei conti (23+). Pool di eventi diversi attivi in fasi diverse, così il giorno 30 non è il giorno 3.
- **Perché**: dà arco e progressione; rompe la sensazione di mazzo piatto. Tutto coi dati esistenti (gating su `day`).
- **Stima**: ~6–8 task. Media.

### 4. Archi narrativi degli oggetti
Ogni oggetto-bandiera ha una sua mini-storia: l'archivio svela il destino dell'equipaggio precedente, la banca semi diventa un orto da curare in tappe, il fucile crea un confronto interno.
- **Perché**: identità e profondità per gli oggetti; due run con kit diversi sono storie diverse.
- **Stima**: ~6–10 task (content-heavy).

### 5. Relazioni d'equipaggio nel contenuto
Abbiamo già il sistema relazioni (legame/tensione/odio) ma è poco sfruttato. Coppie che si legano o si odiano, che **tu** influenzi, con effetti reali: due legati si salvano a vicenda in spedizione; una faida sabota una riparazione.
- **Perché**: approfondisce l'"equipaggio vivo" che hai amato, a basso costo (sistema già c'è).
- **Stima**: ~5–7 task. Piccola-media.

---

## TIER 3 — Pagamento emotivo e rigiocabilità

### 6. Epiloghi personalizzati
Il finale racconta **la tua** run: chi è morto e come, le scelte chiave, l'epiteto. Un "che ne è stato di loro" per ogni superstite.
- **Perché**: i finali stratificati ci sono, ma manca la stoccata emotiva specifica. È ciò che fa raccontare la partita agli amici.
- **Stima**: ~4–6 task. Piccola-media.

### 7. Meta-progressione (cross-run)
C'è già un sistema profilo/unlock (research points → oggetti sbloccabili). Sfruttarlo: memoria cross-run ("il comandante precedente..."), scenari di partenza sbloccabili, oggetti che si guadagnano giocando.
- **Perché**: dà un motivo per rigiocare e un senso di crescita.
- **Stima**: ~6–8 task. Media.

---

## TIER 4 — Feel e accessibilità (cheap, alto ritorno percepito)

### 8. Polish sensoriale
Ronzio ambientale, suoni UI discreti, transizioni delle carte più materiche, piccolo feedback aptico/visivo sulle scelte pesanti.
- **Perché**: a parità di contenuto, raddoppia la sensazione di "gioco vero". Poco lavoro, grande resa.
- **Stima**: ~3–5 task. Piccola.

### 9. Onboarding leggero
Un nuovo giocatore non capisce subito fame/standing/oggetti. Tooltip mirati o una prima run guidata leggera.
- **Perché**: il gioco è diventato profondo; serve una porta d'ingresso.
- **Stima**: ~3–5 task. Piccola.

---

## La mia raccomandazione
**Prossima: Spedizioni (1).** È la cosa che hai chiesto, dà l'*agency* che manca, ed è la naturale continuazione della Fame (mandi qualcuno a procurare cibo, rischiando di perderlo). Subito dopo, **Oggetti-strumento (2)** per chiudere il cerchio sulla ripetizione e sugli oggetti.

In parallelo, quando vuoi una pausa dal "grande", le voci **5 (relazioni)**, **6 (epiloghi)** e **8 (polish)** sono fette piccole ad alto ritorno.
