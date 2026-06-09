import { describe, it, expect } from "vitest";
import { formatEffects } from "./effectFormat";

describe("formatEffects", () => {
  it("formats resource deltas with sign and Italian labels", () => {
    const out = formatEffects([
      { resource: "oxygen", delta: -12 },
      { resource: "morale", delta: 8 },
    ]);
    expect(out).toContain("ossigeno −12");
    expect(out).toContain("morale +8");
  });

  it("notes a death and a consumed item", () => {
    const out = formatEffects([{ kill: "Cole" }, { consume_item: "medkit" }]);
    expect(out.join(" ")).toContain("morte");
    expect(out.join(" ")).toContain("medkit");
  });

  it("returns empty for no effects", () => {
    expect(formatEffects([])).toEqual([]);
  });
});
