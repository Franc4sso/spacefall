// Thin API client. The browser never computes game outcomes — it only asks
// the Laravel API and renders what comes back (server-authoritative design).

const BASE = import.meta.env.VITE_API_URL ?? "http://localhost:8000/api";

// ---- Types (mirror the API payloads) ----

export type Health = { status: string; service: string };

export type ResourceMeta = { max: number; two_sided: boolean };

export type Reaction = {
  who: string;
  tone: "anger" | "approve" | "complicated";
  line: string;
};

export type ChoiceLogEntry = {
  day: number;
  event_key: string;
  choice_index: number;
  choice_label: string;
  tags: string[];
  reaction_summary: string | null;
  effects_summary?: { resources: Record<string, number>; notes: string[] };
};

export type Choice = {
  index: number;
  label: string;
  hint: string | null;
  available: boolean;
  requires_item: string | null;
};

export type Card = {
  key: string;
  title: string;
  body: string;
  speaker: string | null;
  choices: Choice[];
};

export type Character = {
  name: string;
  role: string | null;
  traits: string[];
  stress: number;
  alive: boolean;
  standing: number;
  hunger: number;
  away: boolean;
  away_until: number;
};

export type Item = { key: string; name: string; description: string };

export type EpilogueSection = { title: string; lines: string[] };

export type Ending = {
  key: string;
  type: "win" | "lose";
  name: string;
  text: string;
  epithet: string | null;
  epilogue?: EpilogueSection[] | null;
} | null;

export type RunState = {
  id: number;
  day: number;
  status: "active" | "ended";
  seed: number;
  resources: Record<string, number>;
  resource_meta: Record<string, ResourceMeta>;
  characters: Character[];
  items: Item[];
  systems: Record<string, { efficiency: number }>;
  ending: Ending;
  card: Card | null;
  choice_log: ChoiceLogEntry[];
  crew_trust: number;
  epithet: string | null;
};

export type Resolution = {
  resolution: { log: string; effects: unknown[]; ending: Ending; reactions: Reaction[] };
  state: RunState;
};

export type ItemCatalogue = { pick: number; items: Item[] };

async function json<T>(res: Response): Promise<T> {
  if (!res.ok) {
    throw new Error(`API ${res.status} ${res.url}`);
  }
  return res.json();
}

const headers = { "Content-Type": "application/json", Accept: "application/json" };

export function fetchHealth(): Promise<Health> {
  return fetch(`${BASE}/health`).then((r) => json<Health>(r));
}

export function fetchItems(handle: string): Promise<ItemCatalogue> {
  return fetch(`${BASE}/items?handle=${encodeURIComponent(handle)}`).then((r) =>
    json<ItemCatalogue>(r),
  );
}

export function startRun(opts: {
  items: string[];
  handle: string;
  seed?: number;
}): Promise<RunState> {
  return fetch(`${BASE}/runs`, {
    method: "POST",
    headers,
    body: JSON.stringify(opts),
  }).then((r) => json<RunState>(r));
}

export function fetchRun(id: number): Promise<RunState> {
  return fetch(`${BASE}/runs/${id}`, { headers }).then((r) => json<RunState>(r));
}

export function resolveChoice(id: number, choice: number): Promise<Resolution> {
  return fetch(`${BASE}/runs/${id}/choices`, {
    method: "POST",
    headers,
    body: JSON.stringify({ choice }),
  }).then((r) => json<Resolution>(r));
}

export function advanceRun(id: number): Promise<RunState> {
  return fetch(`${BASE}/runs/${id}/advance`, { method: "POST", headers }).then(
    (r) => json<RunState>(r),
  );
}
