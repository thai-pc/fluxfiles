---
description: Design a spec for a feature idea with the spec-writer subagent (claims, endpoints, storage, security, test plan).
argument-hint: <feature idea, e.g. "PDF tools via Stirling embed">
---
Use the **spec-writer** subagent to design a spec for: $ARGUMENTS

It must fit the FluxFiles grain (stateless / no DB / storage-resident; config = JWT
claims; paid feature = a gitignored module on the 3-layer gate; embed great free OSS
rather than build a competitor). Produce the spec with the sections in
`.claude/agents/spec-writer.md` (problem & who-pays, architecture fit, JWT claims,
endpoints, storage layout, security, package layout, test plan, open questions), and
end by listing the exact claims to add to `docs/CONFIG.md`. Do not implement.
