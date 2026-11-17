# Suspension semantics

Status: **Confirmed (2026-11-17, D32)**. The user confirmed the rules and the proposed defaults below without change, and they are implemented; see the [console evidence](console-evidence.md). This document was written on 2026-11-16 as a proposal and is kept as the record of what was decided.

## Confirmed rules

1. **Two separate actions.** *Suspend account* applies to one identity everywhere. *Suspend workspace* applies to one workspace for all its members. Neither is deletion, and neither changes memberships, grants or records.
2. **Account suspension.** All of the account's sessions end immediately and sign-in is refused with a neutral message. Password reset and email verification do not restore access. The account's workspaces keep operating for their other members.
3. **Sole-owner workspaces.** Suspending an account that is the only active owner of a workspace does not suspend that workspace; the impact review lists such workspaces so the operator can decide separately.
4. **Workspace suspension.** Members can sign in but cannot open the workspace; they see that it is suspended. Public links (onboarding forms, lead links, published media kits, partner join links) respond as unavailable. Partner relationships stay recorded, and the other party sees the partnership as paused.
5. **Queued and scheduled work.** Authorization is rechecked when work executes. For a suspended workspace or actor, pending notifications are cancelled rather than held, scheduled runs are skipped and recorded as skipped, and follow-up approvals are invalidated.
6. **Reactivation.** Restores access from that moment only. It does not restore revoked memberships or grants, re-open expired links, or replay cancelled or skipped work.
7. **Platform operators.** Suspending an account with a platform grant also blocks administration. The last usable platform owner cannot be suspended.
8. **Every action** needs a reason, an expected record version, fresh password and authenticator verification, a server-computed impact review, modal confirmation and an audit event. Rejected attempts are audited.
9. **Sign out sessions** is offered as a separate, smaller action on an account, with a reason and audit, and without suspension.

## Confirmed answers

| Question | Confirmed answer |
|---|---|
| Does a suspended member see why, and whom to contact? | A neutral "access is suspended; contact support" message with no reason shown. |
| Are invoices, payments and exports readable while a workspace is suspended? | No access while suspended; records are retained unchanged. |
| Who may suspend? | A new `access.manage` capability; platform owners hold it implicitly. |
| Is the affected party notified by email? | Not in the first release; no notification kind exists for it. |
| Is there a maximum duration or review date? | None enforced; the Overview lists suspensions older than 30 days for review. |
| Does suspension pause the entitlement end date? | No; assignment dates are unaffected. |

Deletion of accounts or workspaces remains out of scope until the M3-02 retention and privacy rules are decided.

## Implementation notes

- Rule 4, partner view: a suspended workspace's public links and members are blocked and its relationships stay recorded, but the other party's portal does not yet label the partnership as paused. This is the one part of the confirmed rules not built.
- Rule 5: queued notifications, ready scheduled runs and approved follow-ups are cancelled at the moment of suspension. Work that would start later is refused when it executes because membership authorization fails; such refusals are not separately recorded as "skipped".
- Rule 9 and the capability question: `access.manage` is implemented as proposed.
