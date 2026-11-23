# Authenticator reset: identity-check procedure

**Status: Proposal (2026-11-23).** This drafts the written identity check that M1-04 requires before an operator resets a workspace owner's authenticator ([contract](owner-mfa-contract.md), D34/D35). The choices marked **Assumption / Open** need the user's decision; ownership of the support process belongs with M6-06.

## What a reset does today

The administration action on an account's Security tab (`POST /admin/accounts/{id}/reset-authenticator`) needs the `access.manage` capability, a reason, the operator's password and a fresh authenticator code, then a Cancel-first confirmation. It:

- clears the authenticator and every recovery code, and signs out all of the account's sessions;
- records `authenticator.reset` in the account's security events and an entry in the administration audit with the reason;
- leaves the account unprotected by a second factor until its holder sets up a new authenticator at the next sign-in.

It is refused for the operator's own account and for accounts with a platform grant; those recover only through the offline `platform:grant --recover` procedure (D31).

**Gap:** the account holder is not notified. Until that exists, step 5 below sends the notice by hand.

## Proposed procedure

1. **Request.** Accept a request only by email from the account's **verified** address, as shown on the administration account page. Never act on a request from another address, a chat message or a phone call, and never on an address the requester supplies.
2. **Challenge.** Reply to the verified address, not to the incoming message's reply-to, with a one-time reference (for example the first eight characters of a new support case ID) and ask the requester to return it. A request whose mailbox may be compromised (a recent email change, or an urgent or pressuring request) waits for step 3.
3. **Second confirmation where one exists.** If the account is an owner of a workspace with another active owner or admin, ask that person to confirm through their own verified address. **Assumption / Open:** whether this is required or advisory, and what happens for sole owners.
4. **Reset.** Use the Security tab. The reason records the case reference and how identity was confirmed, for example "Case 3ab9340b: verified-email challenge returned; co-owner confirmed". Never record identity documents, codes or personal details.
5. **Tell the account holder.** Email the verified address that the authenticator was reset, that they must set up a new one at their next sign-in, and how to report a reset they did not request.
6. **Follow up.** Check the account's security events after the holder signs in; if no new authenticator is set up within a week, note it on the case.

## Refuse or escalate when

- The request comes from any address other than the verified one, or the challenge reference is not returned.
- The account holds a platform grant (offline recovery only) or is the operator's own account (the console refuses both).
- The account is suspended, or has a pending privacy closure: resolve those first.
- The requester pressures for speed or asks to skip a step.

## Decisions needed (Assumption / Open)

- **Second confirmer:** who confirms (step 3), and whether sole owners need another check, such as a delay.
- **Channel:** whether the verified-email challenge is enough, or a second channel is required.
- **Handling:** who handles reset requests and where cases are tracked (M6-06).
- **Notification:** whether to add an automatic notice to the verified address on reset, replacing step 5's manual email. Recommended.
