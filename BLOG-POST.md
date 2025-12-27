# Building a Drip Email System on Listmonk: Custom Segmentation and Transactional Sequences

Listmonk is a fantastic self-hosted email platform – fast, lightweight, and privacy-friendly. But it has limitations, particularly around segmentation and automated drip sequences. Here's how we extended it to handle complex, attribute-based drip campaigns for our WordPress plugins.

## The Challenge

We needed to:
1. Send timed drip email sequences to free plugin users
2. Segment subscribers by multiple attributes (plugin installed, license status, drip stage)
3. Track and advance subscribers through email sequences automatically
4. Include unsubscribe links in transactional emails (not natively supported)

Listmonk's built-in segmentation is basic – you can filter by lists and a few standard fields, but not by custom subscriber attributes. And while it has transactional email support, there's no built-in drip/automation system.

## What We Built

### 1. Campaign List Builder

A PHP web app that creates dynamic Listmonk campaigns by querying subscriber attributes directly.

**The Problem:** Listmonk's campaign targeting can't filter by custom attributes like `p1330_status = 'free'` or `p1330_drip_stage = 'free_2'`.

**The Solution:** A web UI that:
- Connects to Listmonk's PostgreSQL database
- Lets you build complex attribute-based queries
- Creates campaigns targeting the resulting subscriber set
- Stores query definitions in SQLite for reuse

Example query: *"All subscribers where plugin 1330 is active, status is 'free', marketing_allowed is true, and drip_stage is 'free_3'"*

### 2. Drip Controller

A PHP CLI application that runs via cron and manages drip sequences.

**How it works:**

1. Queries Listmonk for subscribers with `drip_next` dates in the past
2. Checks their current `drip_stage` attribute
3. Sends the appropriate transactional email via Listmonk's `/api/tx` endpoint
4. Advances the subscriber to the next stage
5. Sets the next `drip_next` date based on configured delays

**Subscriber attributes used:**
```json
{
  "p1330_drip_stage": "free_2",
  "p1330_drip_next": "2025-12-10T10:00:00Z",
  "p1330_drip_started": "2025-12-08T10:00:00Z",
  "p1330_status": "free",
  "marketing_allowed": true
}
```

**Sequence configuration** (stored in SQLite):
```
Plugin 1330:
  free_1 → template 6 → wait 2 days → free_2
  free_2 → template 7 → wait 5 days → free_3
  free_3 → template 8 → wait 7 days → free_4
  free_4 → template 9 → wait 16 days → free_5
  free_5 → template 10 → complete
```

The controller supports:
- Multiple plugins with separate sequences
- Dry-run mode for testing
- Lock files to prevent concurrent runs
- Detailed logging

### 3. Git-Tracked Transactional Templates

Email templates stored in git for version control and AI-assisted editing.

**Folder structure:**
```
templates/transactional/
├── DEE/                    # Display Eventbrite Events
│   ├── free_1_welcome.html
│   ├── free_2_followup.html
│   ├── free_3_pro_intro.html
│   ├── free_4_social_proof.html
│   └── free_5_review_request.html
├── FAS/                    # Fullworks Anti-Spam
│   ├── free_1_welcome.html
│   ├── free_2_check_stats.html
│   ├── free_3_pro_pitch.html
│   └── free_4_review_request.html
└── README.md
```

Templates use Listmonk's Go template syntax:
```html
<p>Hi {{ .Subscriber.Name | default "there" }},</p>
```

## The Unsubscribe Hack

Listmonk's transactional emails don't include unsubscribe links by default – they're designed for receipts and notifications, not marketing sequences.

**The workaround:** Manually construct the unsubscribe URL using the subscriber's UUID:

```html
<a href="https://email.example.com/subscription/LISTMONK-LIST-UUID/{{ .Subscriber.UUID }}">
  Unsubscribe
</a>
```

Replace `LISTMONK-LIST-UUID` with your actual list's UUID from Listmonk. The `{{ .Subscriber.UUID }}` is populated automatically.

## Architecture

```
┌─────────────────────┐     ┌─────────────────────┐
│  Campaign List      │     │  Drip Controller    │
│  Builder (PHP)      │     │  (PHP CLI + Cron)   │
└─────────┬───────────┘     └─────────┬───────────┘
          │                           │
          │ SQL queries               │ API calls
          ▼                           ▼
┌─────────────────────────────────────────────────┐
│                   Listmonk                       │
│  ┌─────────────┐  ┌─────────────┐               │
│  │  PostgreSQL │  │  /api/tx    │               │
│  │  (attribs)  │  │  (send)     │               │
│  └─────────────┘  └─────────────┘               │
└─────────────────────────────────────────────────┘
          │
          ▼
┌─────────────────────┐
│  Shared SQLite      │
│  (config + queries) │
└─────────────────────┘
```

Both containers share a SQLite database volume for configuration. The drip controller reads sequence definitions, the list builder reads/writes saved queries.

## Deployment (Coolify/Docker)

```yaml
services:
  campaign-list-builder:
    build: ./campaign-list-builder
    volumes:
      - shared-data:/var/www/html/data

  drip-controller:
    build: ./drip-controller
    volumes:
      - shared-data:/data
    # Cron runs every 15 minutes
```

## Drip Sequence Design

Our sequences follow this pattern:

| Day | Email | Purpose |
|-----|-------|---------|
| 0 | Welcome | Help them succeed, show quick wins |
| 2-7 | Follow-up | Check in, provide tips, soft Pro mention |
| 7-14 | Pro intro | Feature comparison, clear value prop |
| 14-21 | Social proof | Testimonials, trust building |
| 30 | Review request | Ask for WordPress.org review |

Key principles:
- Lead with value, not sales
- Be genuinely helpful in early emails
- Pro pitch comes after they've experienced the free version
- Include plugin name in subject lines for recognition
- Always provide easy unsubscribe

## Results

- Fully automated drip sequences for multiple plugins
- Attribute-based segmentation that Listmonk can't do natively
- Version-controlled templates with AI-assisted editing
- Self-hosted, GDPR-friendly, no third-party email services
- Total infrastructure cost: one VPS running Coolify

## Code

The full system is available at: https://github.com/alanef/fullworks-email-helpers

---

*Built with Listmonk, PHP, SQLite, and Docker. No external email services required.*