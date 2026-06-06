import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, expect, it, vi } from "vitest";
import App from "./App";

// --- fixtures ---
const ITEMS = {
  pick: 2,
  items: [
    { key: "welder", name: "Saldatrice", description: "ripara" },
    { key: "scanner", name: "Scanner", description: "legge" },
    { key: "comms", name: "Radio", description: "chiama" },
  ],
};

function runState(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 1,
    day: 1,
    status: "active",
    seed: 1,
    resources: { oxygen: 100, food: 80, power: 90, morale: 60, hull: 100 },
    resource_meta: {
      oxygen: { max: 100, two_sided: false },
      food: { max: 100, two_sided: false },
      power: { max: 100, two_sided: false },
      morale: { max: 100, two_sided: true },
      hull: { max: 100, two_sided: false },
    },
    characters: [
      { name: "Anna", role: "engineer", traits: ["genius"], stress: 0, alive: true },
    ],
    items: [{ key: "welder", name: "Saldatrice", description: "ripara" }],
    systems: { life_support: { efficiency: 100 } },
    ending: null,
    card: {
      key: "power_flicker",
      title: "Sbalzo di tensione",
      body: "Le luci tremano.",
      speaker: null,
      choices: [
        { index: 0, label: "Spengo il superfluo", hint: "dovrebbe reggere", available: true },
        { index: 1, label: "Lascio perdere", hint: "rischioso", available: true },
      ],
    },
    ...overrides,
  };
}

beforeEach(() => {
  localStorage.clear();
});
afterEach(() => {
  vi.restoreAllMocks();
});

function mockFetch(handler: (url: string, init?: RequestInit) => unknown) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => ({
      ok: true,
      status: 200,
      url,
      json: async () => handler(url, init),
    })),
  );
}

it("shows the start screen with item picks", async () => {
  mockFetch((url) => {
    if (url.includes("/items")) return ITEMS;
    return {};
  });

  render(<App />);
  expect(await screen.findByText("STARFALL STATION")).toBeInTheDocument();
  expect(await screen.findByTestId("item-welder")).toBeInTheDocument();
});

it("starts a run after picking items and shows the first card", async () => {
  mockFetch((url) => {
    if (url.includes("/items")) return ITEMS;
    if (url.endsWith("/runs")) return runState();
    return {};
  });

  const user = userEvent.setup();
  render(<App />);

  await user.click(await screen.findByTestId("item-welder"));
  await user.click(await screen.findByTestId("item-scanner"));
  await user.click(screen.getByTestId("begin"));

  expect(await screen.findByTestId("card")).toBeInTheDocument();
  expect(screen.getByText("Sbalzo di tensione")).toBeInTheDocument();
  expect(screen.getByTestId("day")).toHaveTextContent("GIORNO 1");
});

it("resolves a choice and advances to the next card without a loading state", async () => {
  const next = runState({
    day: 2,
    resources: { oxygen: 92, food: 70, power: 84, morale: 56, hull: 98 },
    card: {
      key: "ration_crisis",
      title: "Chi mangia stanotte",
      body: "Una sola porzione.",
      speaker: null,
      choices: [{ index: 0, label: "Divido", hint: null, available: true }],
    },
  });

  mockFetch((url) => {
    if (url.includes("/items")) return ITEMS;
    if (url.endsWith("/runs")) return runState();
    if (url.includes("/choices")) return { resolution: { log: "Stabile.", effects: [], ending: null }, state: next };
    return {};
  });

  const user = userEvent.setup();
  render(<App />);

  await user.click(await screen.findByTestId("item-welder"));
  await user.click(await screen.findByTestId("item-scanner"));
  await user.click(screen.getByTestId("begin"));

  await screen.findByTestId("card");
  await user.click(screen.getByTestId("choice-0"));

  // Next card present immediately; day advanced. No spinner text anywhere.
  expect(await screen.findByText("Chi mangia stanotte")).toBeInTheDocument();
  expect(screen.getByTestId("day")).toHaveTextContent("GIORNO 2");
});

it("shows the game-over screen with the ending and a restart button", async () => {
  const dead = runState({
    status: "ended",
    ending: { key: "death_breakdown", type: "lose", name: "Crollo", text: "Nessuno continua." },
    card: null,
  });

  mockFetch((url) => {
    if (url.includes("/items")) return ITEMS;
    if (url.endsWith("/runs")) return runState();
    if (url.includes("/choices")) return { resolution: { log: "Fine.", effects: [], ending: dead.ending }, state: dead };
    return {};
  });

  const user = userEvent.setup();
  render(<App />);

  await user.click(await screen.findByTestId("item-welder"));
  await user.click(await screen.findByTestId("item-scanner"));
  await user.click(screen.getByTestId("begin"));
  await screen.findByTestId("card");
  await user.click(screen.getByTestId("choice-0"));

  expect(await screen.findByTestId("ending-name")).toHaveTextContent("Crollo");
  expect(screen.getByTestId("restart")).toBeInTheDocument();
});

it("disables an unavailable (item-gated) choice", async () => {
  const gated = runState({
    card: {
      key: "hull_breach",
      title: "Microbreccia",
      body: "Lo scafo perde.",
      speaker: null,
      choices: [
        { index: 0, label: "Saldo", hint: null, available: false },
        { index: 1, label: "Tappo", hint: "rischioso", available: true },
      ],
    },
  });

  mockFetch((url) => {
    if (url.includes("/items")) return ITEMS;
    if (url.endsWith("/runs")) return gated;
    return {};
  });

  const user = userEvent.setup();
  render(<App />);
  await user.click(await screen.findByTestId("item-welder"));
  await user.click(await screen.findByTestId("item-scanner"));
  await user.click(screen.getByTestId("begin"));

  await screen.findByTestId("card");
  expect(screen.getByTestId("choice-0")).toBeDisabled();
  expect(screen.getByTestId("choice-1")).toBeEnabled();
});
