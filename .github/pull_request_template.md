## What and why

<!-- What changes, and what problem it solves. Link the issue or doc if there is one. -->

## Risk

<!-- Delete what does not apply. Anything left ticked means a reviewer should
     look harder, not that the change is wrong. Master plan §53 classes the
     first five as HIGH RISK. -->

- [ ] Tenant isolation (adds or changes a `withoutGlobalScope` call, raw SQL, or a queued job)
- [ ] Authentication or authorization
- [ ] Payroll, tax or billing calculation
- [ ] Database schema or a migration
- [ ] Deployment, gates, hooks, or anything under `scripts/`
- [ ] File upload or download
- [ ] None of the above

## Verification

<!-- What you ran, and what it said. "Tests pass" is not evidence; a command and
     its output is. -->

```
./scripts/gates.sh
```

- [ ] Gates pass locally
- [ ] New behaviour has a test
- [ ] **Any regression test was shown to FAIL without the fix** — revert the change, watch it go red, put it back. A test that passes both before and after proves nothing.

## Documentation

- [ ] Docs updated, or no doc describes this behaviour
- [ ] No document now claims a control that does not exist

<!-- That last one is not boilerplate. Four documents in this repository
     asserted a CI pipeline that had never been built; a documented control
     nobody runs is worse than an admitted gap, because it stops people
     looking. -->
