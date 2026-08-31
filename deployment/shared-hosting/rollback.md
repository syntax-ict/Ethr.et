# Rollback

The full plan — decision tree, DNS TTL considerations, what to do if rollback is needed
*after* real tenant data has been written on the shared-hosting side — is
`docs/ROLLBACK_RUNBOOK.md`. This file is the one-line version for the moment something
is visibly wrong right after cutover:

```
Repoint ethr.et / www.ethr.et back to the VPS's IP.
```

That's it, as long as nothing has written data specific to the shared-hosting
deployment yet — which is the expected case for the first hours/days after cutover,
since `ethr.et` had no live traffic before this migration (verified — see
`docs/B1-B5_GATE_REPORT.md`; the VPS is dormant, not serving). The VPS deployment is
untouched by anything in this package. Read `docs/ROLLBACK_RUNBOOK.md` before
rolling back if any real tenant has already signed up or logged in against the
shared-hosting deployment — at that point it's a data-reconciliation decision, not a
pure DNS one.
