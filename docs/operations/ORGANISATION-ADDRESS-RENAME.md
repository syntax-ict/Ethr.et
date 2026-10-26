# Renaming an organisation's address

Added 2026-10-26. A tenant admin can change the name in the organisation's
address, from **Settings → Address → Change name**: `ethr.et/{name}`, and
`{name}.ethr.et` where subdomains are served. A verified custom domain is not
affected.

## Who can do it

Tenant admins only: the endpoint is gated on `settings.manage`, which only the
`tenant_admin` role holds. The platform console does not rename addresses.

## What happens

| Happens | Does not happen |
|---|---|
| Links ETHR sends from now on use the new name | The old name is **not** released |
| The old name is kept in `tenant_subdomain_aliases` and keeps reaching the organisation: `ethr.et/{old}` and the old subdomain's sign-in page forward to the new address, and a browser still sending the old name as `X-Tenant` keeps working | No other organisation can register or rename to the old name |
| A `settings.subdomain_changed` audit entry records the old and new names | Records, users and sessions on the shared address are untouched |

The organisation can take a retired name back at any time: renaming to it
removes it from the retired list and retires the name it is leaving.

The forwards are `302`, not `301`. A name can be taken back, and a browser that
cached a permanent redirect would then bounce between the two names.

## Rules

The name follows registration's own rules (`Tenant::SUBDOMAIN_REGEX`,
`Tenant::RESERVED_SUBDOMAINS`): 3 to 63 lower-case letters, digits and inner
hyphens, never a reserved name. It must not be another organisation's current
name, a deleted organisation's name, or a name another organisation retired.
The settings screen checks the name as it is typed
(`GET /settings/address/subdomain/check`), and the save
(`PUT /settings/address/subdomain`) checks it again inside the transaction.
Five renames an hour per organisation (`throttle:address-rename`), because
every rename keeps one more name reserved.

## Two consequences worth telling the organisation

- **Sessions on the old subdomain.** A session belongs to the host it was made
  on (`SESSION_DOMAIN` is empty), so people signed in at `{old}.ethr.et` sign
  in again at `{new}.ethr.et`. The admin who renamed is sent there. People on
  the shared address `ethr.et` stay signed in.
- **Single sign-on.** ETHR's SAML entity ID and ACS address contain the name
  (`/api/v1/sso/saml/{name}/…`). The old ACS address still reaches the
  organisation, but the identity provider should be given the new addresses
  shown in the SSO settings. The confirmation dialog says so.
