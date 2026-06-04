import { useEffect, useState } from "react";
import { fetchHealth } from "./api";

type Status = "loading" | "online" | "offline";

export default function App() {
  const [status, setStatus] = useState<Status>("loading");
  const [service, setService] = useState<string>("");

  useEffect(() => {
    let active = true;
    fetchHealth()
      .then((h) => {
        if (!active) return;
        setService(h.service);
        setStatus("online");
      })
      .catch(() => {
        if (active) setStatus("offline");
      });
    return () => {
      active = false;
    };
  }, []);

  return (
    <main className="flex h-full flex-col items-center justify-center gap-4 p-8 text-center">
      <h1 className="text-2xl tracking-widest text-phosphor">STARFALL STATION</h1>
      <p className="text-sm text-phosphor-dim">// terminal di bordo</p>
      <p data-testid="health" className="text-sm">
        {status === "loading" && "Connessione al sistema…"}
        {status === "online" && (
          <span className="text-phosphor">SISTEMA ONLINE — {service}</span>
        )}
        {status === "offline" && <span className="text-alarm">SISTEMA OFFLINE</span>}
      </p>
    </main>
  );
}
