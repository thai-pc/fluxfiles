---
description: Decide whether/what to build for an idea with the planner subagent (build vs embed vs skip, free vs paid, who pays, v1 scope).
argument-hint: <feature idea, e.g. "PDF editing">
---
Use the **planner** subagent to decide what to do about: $ARGUMENTS

It decides *whether* and *what*, not *how* — no API/claim design (that's `/spec`).
Follow `.claude/agents/planner.md`: check what already exists in the codebase first;
decide build-in-core vs paid module vs BYO-embed-OSS vs don't-build (remember the
`optimize` paid→free reversal — justify paid, don't assume it); name the operator
persona who pays; cut a v1 scope with an explicit out-of-scope list. Grain check:
stateless / no DB / config = JWT claims.

Write the plan to `.claude/work/plan-<slug>.md` and end with the decision in one
sentence. Do not design endpoints and do not implement.
