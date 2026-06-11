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
1. ~~**Oggetti-strumento nelle crisi comuni**~~ ✅ CHIUSO (branch feat/tool-items-crises, 2026-06-11). 6 carte-crisi frequenti con costo-base reale (power_cascade→scanner, survivor_strained/survivor_breaks→medkit, ration_crisis→rations, ration_cut_decision→rations, fuel_leak_warning→spacesuit) ottengono una scelta gated sull'oggetto: scorciatoia più economica che **consuma** l'attrezzo (`consume_item`). Motore riusato (`has_item` + `consume_item`), zero engine nuovo. Test-guardiano `ToolItemChoiceTest` (presenza/consumo/solo-griglia-sbloccata/visibilità). **Escluse di proposito:** `power_flicker` (già aveva welder); `old_scorch`/`reactor_gamble`/`the_sacrifice` (costo-base = 0, niente da rendere "più economico"). Spec/piano: `specs/2026-06-11-tool-items-common-crises-design.md`, `plans/2026-06-11-tool-items-common-crises.md`. **Residuo:** estendere il pattern ad altre crisi con costo reale quando si risvegliano gli oggetti dormienti (vedi Tier 3 #6).

### Tier 2 — profondità e varietà
2. ~~**Struttura ad Atti / Fasi**~~ ✅ CHIUSO (branch feat/phase-pools, 2026-06-11). L'engine ad Atti c'era **già** (PhaseResolver: giorno+pressione-risorse+floor monotòno; `phase`/`phase_index` valutati; Selector filtra). La feature era **contenuto**: aggiunti 15 eventi (5/fase) a `ContentEventSeeder::phaseEvents()` con **posta crescente** — Isolamento (costi piccoli, reversibili) → Deterioramento (medi, strascichi via spawn) → Resa dei conti (estremi, kill/flag-finale). Test `PhasePoolTest` (presenza/gating, conteggio 5/fase, posta crescente, spawn end-to-end con fase forzata). Nessun flag-orfano (riusa knows_the_past/made_the_sacrifice/left_someone). Spec/piano: `specs/2026-06-11-phase-pools-design.md`, `plans/2026-06-11-phase-pools.md`.
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
- ~~**Flag-testimone senza lettori.**~~ ✅ CHIUSO (branch feat/narrative-consequences, 2026-06-10). Un test-guardiano (`FlagReachabilityTest`) garantisce che ogni flag scritto sia letto da carta/finale/epilogo. Aggiunte 4 carte-eco in-run (knows_the_past, research_complete, ate_alone, illness_caught) e il finale dedicato `prezzo_della_fame` (cannibalism+ate_alone). Spec/piano: `specs/2026-06-10-narrative-consequences-design.md`.
- **Testo dei ritorni spedizione generico.** Gli `exp_return_*` dicono "chi avevi mandato" invece del nome. Personalizzarli (speaker = expeditioner) darebbe più voce. Piccolo.
- **Cadenza della Fame = leva di tuning.** Il pasto compare ~ogni 4-5 giorni per il giocatore bravo; va validato col tuo playtest e tarato a gusto (soglia `spawn_bands`, `daily_rise`). Solo numeri in `config/game.php`.
- **Difficoltà spedizioni = leva di tuning.** Le probabilità (`ExpeditionResolver`) e le durate/pericolosità delle mete sono punti di partenza; da tarare col playtest.
- **Card a scelta singola narrative.** Restano alcuni "momenti" a una sola opzione (fantasmi/ricordi, gated e rari). Intenzionali, ma alcuni si potrebbero arricchire con una seconda scelta.
- **Pool eventi base vs nuovo.** Coesistono `EventSeeder` (contenuto originale: technician_*, ration_night, ecc.) e `ContentEventSeeder` (tutto il nuovo). Nessun problema funzionale, ma un giorno conviene armonizzare tono/peso tra i due.

---

## ▶️ Raccomandazione
Prossima fetta: **Oggetti-strumento nelle crisi comuni** (Tier 1 #1) — chiude il cerchio su ripetizione + interazione oggetti, e riusa eventi esistenti (efficiente). In parallelo, quando vuoi una pausa dal "grande", **Epiloghi** (#5) e **Polish** (#7) sono fette piccole ad alto ritorno che sfruttano i ganci già pronti (flag-testimone).
