# Changelog

All notable changes to FlowMonk are documented here, in
[Keep a Changelog](https://keepachangelog.com) format. FlowMonk follows
[semver](https://semver.org); the current version is in `VERSION`.

## [Unreleased]

### Security
- Free-plugin (freelib) opt-in webhooks are now screened before a subscriber is
  created. These submissions can't be signed (the plugin code is public), so
  anyone could sign up any address and trigger a double opt-in email plus four
  reminders, which is the main source of spam-complaint risk. Submissions are now
  dropped if the source IP has sent 5 in the last 24 hours, the same address
  (ignoring +tags and Gmail dots) was accepted for the same plugin in the last
  30 days, the address is a role account (admin@, noreply@, postmaster@,
  webmaster@ and similar; info@, contact@, hello@, sales@, support@ and office@
  are still allowed), the domain is disposable, or the domain has no MX.
  Dropped submissions get the same response as accepted ones.
- Freelib opt-ins for an address that is blocklisted in Listmonk are ignored,
  instead of re-adding it to the list.

### Changed
- Subscribers who never confirm double opt-in are now blocklisted in Listmonk
  at day 21 instead of deleted. Deleting them let the same address be signed up
  again and receive another five emails; the blocklist acts as a permanent
  suppression record. The drip-runner summary reports these as "Blocklisted".

### Added
- Dunning events (initiated, reminder sent, confirmed, unsubscribed, blocklisted,
  expired) are recorded, and the stats page shows which email each subscriber
  confirmed after, so the value of each reminder can be measured.
- The stats page shows free-plugin opt-in submissions for the last 30 days by
  receiving host (campaign.email.fw9.uk or the legacy verify.workflow.fw9.uk)
  and outcome, to inform when the legacy host can be retired.

## [1.0.0] - 2026-10-04

Baseline: the state of FlowMonk when this changelog was introduced.
