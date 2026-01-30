# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Email marketing automation system for Fullworks WordPress plugins, built on Listmonk. Two main services:
- **campaign-list-builder**: PHP web UI for managing Listmonk campaigns and drip sequences
- **drip-controller**: PHP cron service that processes drip email sequences and dunning

## Commands

```bash
# Start local development
docker compose up -d

# View drip-controller logs
docker logs -f drip-controller

# Manual drip run (inside container)
docker exec drip-controller php /app/drip-runner.php

# Dry run (no emails sent)
docker exec -e DRY_RUN=true drip-controller php /app/drip-runner.php
```

Web UI available at http://localhost:8082

## YouTrack CLI (yt)

Project management via YouTrack. Requires `.env.youtrack` file with `YOUTRACK_URL`, `YOUTRACK_TOKEN`, and optionally `YOUTRACK_PROJECT`.

```bash
# Connection & status
yt test                     # Test connection
yt status                   # Show current work status
yt list                     # Download and save all issues

# Issue operations
yt show <issue-id>          # Show issue details
yt comment <issue-id> <text> # Add comment to issue
yt create <title> [desc]    # Create new issue

# Custom fields
yt fields                   # List all custom fields and their possible values
yt field <issue-id> <field-name> <value>  # Update any custom field

# Stage transitions
yt selected <issue-id>      # Move to Selected
yt nextup <issue-id>        # Move to Next Up
yt start <issue-id>         # Move to In Progress
yt testing <issue-id>       # Move to Testing
yt ready <issue-id>         # Move to Release Ready
yt deployed <issue-id>      # Move to Deployed
yt dontdo <issue-id>        # Move to Dont do
yt nostage <issue-id>       # Clear stage

# Override project
yt -p ABC status            # Use project ABC instead of .env.youtrack default
```

## Architecture

### Drip System Flow

1. WordPress plugins send subscriber data to Listmonk via webhook
2. `drip-runner.php` runs via cron every 15 minutes
3. `DripProcessor` queries Listmonk for subscribers with due drips
4. `DunningProcessor` handles double opt-in confirmation reminders
5. Emails sent via Listmonk's transactional API

### Key Classes (drip-controller/src/)

| Class | Purpose |
|-------|---------|
| `ListmonkClient` | API wrapper for Listmonk REST API |
| `DripProcessor` | Sends drip emails based on subscriber stage |
| `DunningProcessor` | DOI confirmation reminders (1/3/7/14 days), blocklist at 21 days |
| `SequenceManager` | Loads drip sequence configs from `config/sequences.php` |
| `SequenceDatabase` | SQLite storage for sequence definitions |

### Subscriber Attributes

Plugin-specific drip tracking uses prefix `p{pluginId}_`:
- `pDEE_drip_stage`: Current stage (`free_1`, `trial_2`, `pro_1`, `complete`, `error`, `stopped`, `deleted`)
- `pDEE_drip_next`: ISO 8601 datetime for next email
- `pDEE_status`: User type (`free`, `trial`, `pro`)

Drip stage meanings:
- `free_1`, `trial_1`, `pro_1`, etc.: Active in sequence
- `complete`: Finished all emails in sequence
- `stopped`: Unsubscribed or blocklisted in Listmonk
- `error`: Max send failures reached (3 attempts)
- `deleted`: Subscriber was deleted from Listmonk (404 on lookup)

Global dunning tracking:
- `doi_stage`: Dunning stage (`dunning_1` through `dunning_blocklist`)
- `doi_next`: Next reminder datetime
- `doi_started`: When dunning initiated
- `marketing_allowed`: Freemius marketing consent (stored in attribs for tracking)

### Marketing Consent & Subscriber Status

Listmonk subscriber `status` is set based on Freemius marketing consent:
- Freemius `is_marketing_allowed = false` → Listmonk `status = 'blocklisted'`
- Freemius `is_marketing_allowed = true` → Listmonk `status = 'enabled'`

Re-opt-in logic: If a subscriber was blocklisted due to Freemius opt-out (previous `marketing_allowed` attribute was `false`) and they later opt back in, they are re-enabled. However, if they unsubscribed via Listmonk directly, a new Freemius webhook with `marketing_allowed = true` will NOT re-enable them (respects Listmonk unsubscribe).

### Double Opt-In Handling

- DripProcessor holds off sending if `subscription_status = 'unconfirmed'` on DOI lists
- DunningProcessor sends confirmation reminders at escalating intervals
- After 21 days unconfirmed, subscriber is blocklisted

## Common Mistakes to Avoid

### File Permissions
When creating new files (especially PHP templates), the Write tool may create files with restrictive permissions (600).

**Always run after creating new files:**
```bash
chmod 644 <new-file-path>
```

### Template Structure (campaign-list-builder)
When creating new page templates in `campaign-list-builder/templates/`:

1. Wrap ALL content inside the `x-data` div, including headings:
```html
<div x-data="pageName()" x-init="init()">
    <h1>Page Title</h1>
    <!-- rest of content -->
</div>
```

2. Put `<style>` and `<script>` tags AFTER the closing `</div>`

3. Match the structure of existing working templates like `drip-stats.php`

### Docker Cron Environment Variables
Cron jobs in Docker containers do NOT inherit environment variables.

The drip-controller's `entrypoint.sh` exports env vars to `/etc/environment`. If adding new env vars that the cron job needs, ensure they're captured in the grep pattern:
```bash
printenv | grep -E '^(LISTMONK_|LOG_LEVEL|DRY_RUN|DATABASE_)' > /etc/environment
```

## Plugin IDs

| Plugin | ID | Slug |
|--------|-----|------|
| Display Eventbrite Events (DEE) | 1330 | widget-for-eventbrite-api |
| Fullworks Anti-Spam (FAS) | 5065 | fullworks-anti-spam |
| Quick PayPal Payments (QPP) | 5623 | quick-paypal-payments |

Free-only plugins (no Freemius): SUE, SWEGTS, CFCS, SSGM, RSHFD, MMT, LHF, FS, AUM

## Deployment (Coolify)

### Known Issue: Listmonk crashes when deploying campaign-list-builder

There's a dependency between services. When deploying campaign-list-builder, Listmonk may crash and need to be restarted. Deploy in this order:
1. Deploy campaign-list-builder
2. Check if Listmonk is responding
3. If not, restart Listmonk container
4. Then deploy drip-controller if needed