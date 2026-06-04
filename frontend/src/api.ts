// Thin API client. The browser never computes game outcomes — it only asks
// the Laravel API and renders what comes back (server-authoritative design).

const BASE = import.meta.env.VITE_API_URL ?? "http://localhost:8000/api";

export type Health = {
  status: string;
  service: string;
};

export async function fetchHealth(): Promise<Health> {
  const res = await fetch(`${BASE}/health`);
  if (!res.ok) {
    throw new Error(`Health check failed: ${res.status}`);
  }
  return res.json();
}
