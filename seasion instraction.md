You are working on a production-grade SaaS system with phased architecture.

CRITICAL CONTEXT FILES:
- CLAUDE.md (system rules)
- PRD.md (product requirements)
- PRODUCT_VISION.md (business goal)
- BACKEND.md (backend architecture)
- FRONTEND.md (UI system rules)
- DATABASE.md (data model)
- SECURITY_AUDIT.md (security rules)
- BUILD_SAFETY.md (safety constraints)

CURRENT EXECUTION MODEL:
- The system is divided into PHASE_00 → PHASE_09
- Each phase must be completed sequentially
- No phase skipping allowed
- No architecture redesign allowed unless explicitly requested

CURRENT FOCUS:
→ Read PHASE_XX.md (active phase file)
→ Continue exactly from last incomplete task
→ Do NOT reimplement completed modules
→ Do NOT redesign existing architecture

WORK RULES:
- Follow existing file structure strictly
- Maintain modular design
- Avoid breaking previous phases
- Ensure production readiness
- Prioritize stability over speed

OUTPUT STYLE:
- Give structured implementation steps
- Then execute code changes incrementally
- Always explain impact before modification


## Use when you feel sessions are drifting:
Treat this session as a continuation of a long-running SaaS project.

Before doing anything:
1. Reconstruct system state from PHASE files
2. Identify last completed milestone
3. Continue without resetting architecture
4. Preserve all previous decisions