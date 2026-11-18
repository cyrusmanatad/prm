# Initial pilot email budget

Date: 2026-11-18. Source: user continuation of M5/M6, [delivery contract](delivery-operations-contract.md) and [trial limits](provider-delivery-acceptance.md). See [readiness evidence](../m6/readiness-evidence.md).

## Confirmed and proposed values

**Confirmed:** retain 1 business send per workspace per minute and 2 globally per minute, with zero incremental spend. The user reported 290 provider sends remaining on 2026-11-18. This is current trial allowance, not a production capacity measurement or permission to use all 290. The existing trial remains limited to 20 attempts total, including account mail, uncertain attempts and the 3 previously completed attempts.

**Confirmed implementation targets (2026-11-18):** the user selected the allocations below after reviewing this proposal. They are implemented locally with activation off by default; [verification](../m6/readiness-evidence.md) and production rollout acceptance remain separate.

| Limit | Initial pilot target |
| --- | --- |
| Application-wide daily ceiling | 250 SMTP recipient attempts per UTC day |
| Business outbox allocation | At most 200 of those 250 |
| Account/security and operational mail reserve | 50; business mail cannot consume it |
| Application alert threshold | 80% of either allocation; alert via logs/external monitoring as well as available mail |
| Incremental spend | Zero; no paid upgrades, overage or automatic purchase |
| Provider headroom | 50 below the user-attested 300/day allowance; other provider consumers must fit within it |

Brevo currently describes a 300/day Free plan, but holds excess transactional mail in a retry queue. Provider acceptance is therefore insufficient as a daily-send stop. See [official limits](https://help.brevo.com/hc/en-us/articles/208580669-FAQs-What-are-the-limits-of-the-Free-plan). SMTP credentials cannot establish actual account usage, billing settings or the reset timezone.

## Enforcement and remaining acceptance

- Count every SMTP recipient attempt at the transport boundary, including verification/reset, Brand decisions, direct monitoring mail and business notifications. Existing business reservation limits alone do not cover those other paths. Reject unreviewed multi-recipient expansion.
- Persist counters atomically across workers/processes. Count failed and uncertain external attempts conservatively; do not refund uncertainty or replay it. A transaction rollback after SMTP must not erase consumption. Reserve before transport, using a separately committed budget operation.
- Exhausted business allowance defers still-eligible pending work with a visible reason and rechecks permission/consent/content before any later attempt. Account/security requests retain safe throttling and do not claim delivery without transport. Password reset keeps the same generic known/unknown-account response to avoid enumeration; capped signup succeeds with explicit paused-verification feedback, and verification resend reports temporary unavailability.
- Use UTC for application accounting, independently of the provider's reset clock. A rolling 24-hour provider safety limit or verified reset alignment is required before relying on different calendars. Crossing midnight must not reset the trial's lifetime ceiling.
- Inventory other applications, campaigns and staging users sharing the provider. Separate application databases do not coordinate a provider-global allowance. Use a shared authoritative budget or explicitly partition/segregate provider capacity before production.
- Test concurrent account/business sends, process crashes, rollover/DST, provider uncertainty, cap exhaustion and recovery. Keep external monitoring available when email itself is capped.

## Decisions still required

The numerical targets are decided. Implementation uses the same limits over a rolling 24-hour window as well as the UTC-day ceiling, preventing a midnight burst without assuming Brevo's reset timezone. Name the usage/stop owner and monitoring recipient, confirm provider-wide sharing and disabled paid overage, and accept the deployment/activation plan. This document authorizes no production send, paid plan or deployment. M5-08/M5-09 remain open until implementation and applicable provider/rollout acceptance are recorded.

Migration `2026_11_18_000004_add_mail_daily_budget` installs the inactive policy and function-only accounting. `mail:budget-policy` reads the aggregate state with the migration role; `--enable` or `--disable` additionally requires `--operator` and `--reason` and appends a platform audit event. No web/runtime endpoint changes policy. Runtime/direct mail, both outboxes and monitoring share the counters. Business notifications retain their approved current-basis checks and defer on an exhausted allocation; unattempted account outbox mail stays pending. Verification resend reports temporary unavailability; registration reports account creation with verification paused; password reset keeps its generic known/unknown-account response. Local `array`/`log` capture does not consume provider allowance. Unreviewed multiple envelope recipients are refused while enforcement is active.

The application reserves consumption independently before transport and never refunds failed, uncertain or unused reservations. A random receipt can be consumed once and expires after ten minutes, while its allocation remains charged for at least 24 hours after reservation and for 24 hours after transport admission if consumed. Account-outbox reservation and allocation commit together before SMTP; direct mail uses a separate autocommit connection so HTTP rollback cannot refund transport. Activate only after all runtimes use the matching candidate and provider allowance has been reconciled; no deployment or activation has occurred outside disposable tests.
