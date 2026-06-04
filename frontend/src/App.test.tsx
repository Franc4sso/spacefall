import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import App from "./App";

afterEach(() => {
  vi.restoreAllMocks();
});

it("renders the station title", () => {
  vi.stubGlobal("fetch", vi.fn(() => new Promise(() => {}))); // never resolves
  render(<App />);
  expect(screen.getByText("STARFALL STATION")).toBeInTheDocument();
});

it("shows the API service name when the health check succeeds", async () => {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => ({
      ok: true,
      json: async () => ({ status: "ok", service: "Starfall Station API" }),
    })),
  );

  render(<App />);

  await waitFor(() =>
    expect(screen.getByTestId("health")).toHaveTextContent(
      "SISTEMA ONLINE — Starfall Station API",
    ),
  );
});

it("shows offline when the health check fails", async () => {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => ({ ok: false, status: 500 })),
  );

  render(<App />);

  await waitFor(() =>
    expect(screen.getByTestId("health")).toHaveTextContent("SISTEMA OFFLINE"),
  );
});
