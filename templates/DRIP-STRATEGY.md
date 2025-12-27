# Drip Email Strategy

## Overview

This document outlines drip email sequences for all Fullworks plugins:
- **Freemium plugins:** Free, Trial, and Pro sequences
- **Free-only plugins:** Cross-sell and review sequences

---

# FREEMIUM PLUGINS

These have Pro versions. Three separate sequences based on user status.

| Plugin | Slug | Freemius ID | Landing Page |
|--------|------|-------------|--------------|
| Display Eventbrite Events (DEE) | widget-for-eventbrite-api | 1330 | https://fullworksplugins.com/products/widget-for-eventbrite/ |
| Fullworks Anti-Spam (FAS) | fullworks-anti-spam | 5065 | https://fullworksplugins.com/products/anti-spam/ |
| Quick PayPal Payments (QPP) | quick-paypal-payments | 5623 | https://fullworksplugins.com/products/quick-paypal-payments/ |

---

## Free User Sequence (5 emails)

Goal: **Convert to Pro or Trial**

| Email | Day | Stage | Subject | Goal |
|-------|-----|-------|---------|------|
| 1 | 0 | free_1 | {Plugin} - welcome | Onboarding, quick win |
| 2 | 2 | free_2 | {Plugin} - how's it going? | Support, tips, soft Pro mention |
| 3 | 7 | free_3 | {Plugin} - what Pro adds | Feature comparison, trial CTA |
| 4 | 14 | free_4 | {Plugin} - why users upgrade | Social proof, testimonials |
| 5 | 30 | free_5 | {Plugin} - quick favour? | Review request |

After email 5: Mark as `complete`

---

## Trial User Sequence (5 emails)

Goal: **Convert to Paid before trial ends**

Trial period: 14 days

| Email | Day | Stage | Subject | Goal |
|-------|-----|-------|---------|------|
| 1 | 0 | trial_1 | {Plugin} Pro - your trial has started | Welcome, key features to try |
| 2 | 3 | trial_2 | {Plugin} Pro - have you tried {feature}? | Highlight best Pro feature |
| 3 | 7 | trial_3 | {Plugin} Pro - halfway through your trial | Progress check, benefits recap |
| 4 | 11 | trial_4 | {Plugin} Pro - 3 days left | Urgency, upgrade CTA |
| 5 | 13 | trial_5 | {Plugin} Pro - last day | Final reminder, easy upgrade link |

After email 5: Mark as `complete` (Freemius handles trial expiry)

### Trial Email Content Guidelines

- **trial_1:** Excitement, what's now unlocked, 3 things to try today
- **trial_2:** Deep dive on ONE killer feature (different per plugin)
- **trial_3:** "You're halfway - here's what you'd lose if you don't upgrade"
- **trial_4:** Scarcity, "3 days left to keep these features"
- **trial_5:** Last chance, simple upgrade button, "takes 30 seconds"

---

## Pro User Sequence (4 emails)

Goal: **Retention, reviews, affiliate recruitment**

Note: Freemius handles renewal reminders - not included here.

| Email | Day | Stage | Subject | Goal |
|-------|-----|-------|---------|------|
| 1 | 0 | pro_1 | {Plugin} Pro - welcome aboard | Thank you, support info, setup help |
| 2 | 7 | pro_2 | {Plugin} Pro - getting the most from it | Advanced tips, lesser-known features |
| 3 | 30 | pro_3 | {Plugin} Pro - quick favour? | Request review (WP.org or Freemius) |
| 4 | 90 | pro_4 | {Plugin} Pro - earn by recommending | Affiliate program invitation |

After email 4: Mark as `complete`

### Pro Email Content Guidelines

- **pro_1:** Warm welcome, priority support details, license management link
- **pro_2:** "Did you know you can..." - hidden gems, advanced shortcodes/settings
- **pro_3:** Personal ask for review, emphasize helping other users find it
- **pro_4:** Affiliate pitch - earn commission, help others, link to signup

---

# FREE-ONLY PLUGINS

No Pro version. Goals: **Reviews, cross-sell to freemium plugins, affiliate recruitment**

Note: Free plugins use custom opt-in library (not Freemius), so no Freemius IDs.

**Testimonials source:** https://wordpress.org/support/plugin/{slug}/reviews/

---

## Stop User Enumeration (SUE)

**50,000 installs** - Tier 1 (full sequence)

| Info | Value |
|------|-------|
| Slug | stop-user-enumeration |
| What it does | Prevents hackers discovering WordPress usernames via author archives and REST API |
| Cross-sell | FAS - "You've blocked enumeration, now block spam attacks" |
| Welcome angle | "Hackers can no longer find your usernames" |
| Review link | https://wordpress.org/support/plugin/stop-user-enumeration/reviews/#new-post |
| Support link | https://wordpress.org/support/plugin/stop-user-enumeration/ |

### Sequence (4 emails)

| Email | Day | Stage | Subject | Content Focus |
|-------|-----|-------|---------|---------------|
| 1 | 0 | free_1 | Stop User Enumeration - welcome | What it blocks, no config needed |
| 2 | 7 | free_2 | Stop User Enumeration - you're protected | Stats on brute force attacks, how SUE helps |
| 3 | 14 | free_3 | Stop User Enumeration - complete your security | Cross-sell FAS for spam/bot protection |
| 4 | 30 | free_4 | Stop User Enumeration - quick favour? | Review request |

---

## Stop WP Email Going to Spam (SWEGTS)

**10,000 installs** - Tier 1 (full sequence)

| Info | Value |
|------|-------|
| Slug | stop-wp-emails-going-to-spam |
| What it does | Adds proper From headers so WordPress emails don't go to spam folders |
| Cross-sell | FAS - "Emails going out properly? Now stop spam coming IN" |
| Welcome angle | "Your WordPress emails will now reach inboxes" |
| Review link | https://wordpress.org/support/plugin/stop-wp-emails-going-to-spam/reviews/#new-post |
| Support link | https://wordpress.org/support/plugin/stop-wp-emails-going-to-spam/ |

### Sequence (4 emails)

| Email | Day | Stage | Subject | Content Focus |
|-------|-----|-------|---------|---------------|
| 1 | 0 | free_1 | Stop WP Emails Going to Spam - welcome | What it fixes, check your spam folder test |
| 2 | 7 | free_2 | Stop WP Emails Going to Spam - working? | How to test, troubleshooting tips |
| 3 | 14 | free_3 | Stop WP Emails Going to Spam - you might also need | Cross-sell FAS for inbound spam |
| 4 | 30 | free_4 | Stop WP Emails Going to Spam - quick favour? | Review request |

---

## Contact Form Clean and Simple (CFCS)

**8,000 installs** - Tier 1 (full sequence)

| Info | Value |
|------|-------|
| Slug | clean-and-simple-contact-form-by-meg-nicholas |
| What it does | Simple, lightweight contact form with no bloat |
| Cross-sell | FAS - "Protect your contact form from spam bots" |
| Welcome angle | "Clean, simple contact form - works out of the box" |
| Review link | https://wordpress.org/support/plugin/clean-and-simple-contact-form-by-meg-nicholas/reviews/#new-post |
| Support link | https://wordpress.org/support/plugin/clean-and-simple-contact-form-by-meg-nicholas/ |

### Sequence (4 emails)

| Email | Day | Stage | Subject | Content Focus |
|-------|-----|-------|---------|---------------|
| 1 | 0 | free_1 | Contact Form Clean and Simple - welcome | Shortcode usage, basic setup |
| 2 | 7 | free_2 | Contact Form Clean and Simple - customization tips | Styling, email settings |
| 3 | 14 | free_3 | Contact Form Clean and Simple - stop form spam | Cross-sell FAS for spam protection |
| 4 | 30 | free_4 | Contact Form Clean and Simple - quick favour? | Review request |

---

## Simple Shortcode for GoogleMaps (SSGM)

**4,000 installs** - Tier 2 (short sequence)

| Info | Value |
|------|-------|
| Slug | simple-google-maps-short-code |
| What it does | Embed Google Maps with a simple shortcode |
| Cross-sell | DEE - "Showing locations? Display your Eventbrite events too" |
| Welcome angle | "Google Maps on your site in seconds" |
| Review link | https://wordpress.org/support/plugin/simple-google-maps-short-code/reviews/#new-post |
| Support link | https://wordpress.org/support/plugin/simple-google-maps-short-code/ |

### Sequence (3 emails)

| Email | Day | Stage | Subject | Content Focus |
|-------|-----|-------|---------|---------------|
| 1 | 0 | free_1 | Simple Google Maps Shortcode - welcome | Basic usage, API key setup |
| 2 | 14 | free_2 | Simple Google Maps Shortcode - showing event locations? | Cross-sell DEE + support info |
| 3 | 30 | free_3 | Simple Google Maps Shortcode - quick favour? | Review request |

---

## Remove Site Health From Dashboard (RSHFD)

**1,000 installs** - Tier 2 (short sequence)

| Info | Value |
|------|-------|
| Slug | remove-site-heath-from-dashboard |
| What it does | Removes the Site Health widget from WordPress dashboard |
| Cross-sell | FAS - "Keeping dashboard clean? Keep spam out too" |
| Welcome angle | "Dashboard decluttered - Site Health widget removed" |
| Review link | https://wordpress.org/support/plugin/remove-site-heath-from-dashboard/reviews/#new-post |
| Support link | https://wordpress.org/support/plugin/remove-site-heath-from-dashboard/ |

### Sequence (3 emails)

| Email | Day | Stage | Subject | Content Focus |
|-------|-----|-------|---------|---------------|
| 1 | 0 | free_1 | Remove Site Health - welcome | What's removed, why it helps |
| 2 | 14 | free_2 | Remove Site Health - keeping WordPress tidy? | Cross-sell FAS + support info |
| 3 | 30 | free_3 | Remove Site Health - quick favour? | Review request |

---

## Meet my Team (MMT)

**400 installs** - Tier 3 (minimal sequence)

| Info | Value |
|------|-------|
| Slug | meet-my-team |
| What it does | Display team members with photos and bios |
| Cross-sell | DEE - "Team for events? Show your Eventbrite events too" |
| Welcome angle | "Showcase your team beautifully" |
| Review link | https://wordpress.org/support/plugin/meet-my-team/reviews/#new-post |
| Support link | https://wordpress.org/support/plugin/meet-my-team/ |

### Sequence (2 emails)

| Email | Day | Stage | Subject | Content Focus |
|-------|-----|-------|---------|---------------|
| 1 | 0 | free_1 | Meet my Team - welcome | Setup guide, shortcode options |
| 2 | 14 | free_2 | Meet my Team - quick favour? | Review request + cross-sell DEE |

---

## Load HTML Files (LHF)

**300 installs** - Tier 3 (minimal sequence)

| Info | Value |
|------|-------|
| Slug | load-html-files |
| What it does | Load static HTML files into WordPress pages |
| Cross-sell | None strong - focus on review |
| Welcome angle | "Static HTML integrated with WordPress" |
| Review link | https://wordpress.org/support/plugin/load-html-files/reviews/#new-post |
| Support link | https://wordpress.org/support/plugin/load-html-files/ |

### Sequence (2 emails)

| Email | Day | Stage | Subject | Content Focus |
|-------|-----|-------|---------|---------------|
| 1 | 0 | free_1 | Load HTML Files - welcome | How to use, file locations |
| 2 | 14 | free_2 | Load HTML Files - quick favour? | Review request |

---

## Fullworks Scanner (FS)

**20 installs** - Tier 3 (minimal sequence)

| Info | Value |
|------|-------|
| Slug | fullworks-scanner |
| What it does | Security scanning for WordPress |
| Cross-sell | FAS - "Complete your security suite" |
| Welcome angle | "Scan your site for security issues" |
| Review link | https://wordpress.org/support/plugin/fullworks-scanner/reviews/#new-post |
| Support link | https://wordpress.org/support/plugin/fullworks-scanner/ |
| Note | Low installs - consider marketing push or deprecation |

### Sequence (2 emails)

| Email | Day | Stage | Subject | Content Focus |
|-------|-----|-------|---------|---------------|
| 1 | 0 | free_1 | Fullworks Scanner - welcome | How to run a scan, what it checks |
| 2 | 14 | free_2 | Fullworks Scanner - quick favour? | Review request + cross-sell FAS |

---

## Active Users Monitor (AUM)

**20 installs** - Tier 3 (minimal sequence)

| Info | Value |
|------|-------|
| Slug | fullworks-active-users-monitor |
| What it does | Monitor currently active/logged-in users |
| Cross-sell | FAS - "Monitor users? Block suspicious activity too" |
| Welcome angle | "See who's logged into your site" |
| Review link | https://wordpress.org/support/plugin/fullworks-active-users-monitor/reviews/#new-post |
| Support link | https://wordpress.org/support/plugin/fullworks-active-users-monitor/ |
| Note | Low installs - consider marketing push or deprecation |

### Sequence (2 emails)

| Email | Day | Stage | Subject | Content Focus |
|-------|-----|-------|---------|---------------|
| 1 | 0 | free_1 | Active Users Monitor - welcome | Where to find the monitor, what it shows |
| 2 | 14 | free_2 | Active Users Monitor - quick favour? | Review request + cross-sell FAS |

---

# CROSS-SELL MATRIX

| Free Plugin | Primary Cross-sell | Secondary | Angle |
|-------------|-------------------|-----------|-------|
| Stop User Enumeration | FAS | - | Security → spam blocking |
| Stop WP Emails Going to Spam | FAS | - | Email out → spam in |
| Contact Form Clean and Simple | FAS | - | Form → form spam |
| Simple Google Maps Shortcode | DEE | - | Locations → events |
| Remove Site Health | FAS | - | Clean dashboard → clean inbox |
| Meet my Team | DEE | - | Team pages → event pages |
| Load HTML Files | - | - | No obvious fit |
| Fullworks Scanner | FAS | - | Security scanning → spam blocking |
| Active Users Monitor | FAS | - | User monitoring → security |

---

# AFFILIATE PROGRAM

All freemium plugins use Freemius with affiliate program (on application).

### When to Pitch Affiliates

- **Freemium Pro users:** Day 90 (pro_4 email)
- **Free-only users:** Not typically - they haven't experienced Pro quality

### Affiliate Signup URLs

- DEE: https://fullworksplugins.com/products/widget-for-eventbrite/affiliate-signup
- FAS: https://fullworksplugins.com/products/anti-spam/affiliate-signup
- QPP: https://fullworksplugins.com/products/quick-paypal-payments/affiliate-signup

### Affiliate Email Content

**Subject:** {Plugin} - earn by recommending

**Body:**
- You've been using {plugin} Pro - thank you for your support
- Know other WordPress users who'd benefit?
- Join our affiliate program and earn commission on every sale
- [Link to affiliate signup]

---

# TEMPLATE FOLDER STRUCTURE

```
templates/transactional/
├── DEE/                              # Display Eventbrite Events
│   ├── free_1_welcome.html           ✓
│   ├── free_2_followup.html          ✓
│   ├── free_3_pro_intro.html         ✓
│   ├── free_4_social_proof.html      ✓
│   ├── free_5_review_request.html    ✓
│   ├── trial_1_welcome.html
│   ├── trial_2_feature.html
│   ├── trial_3_halfway.html
│   ├── trial_4_ending_soon.html
│   ├── trial_5_last_day.html
│   ├── pro_1_welcome.html
│   ├── pro_2_tips.html
│   ├── pro_3_review.html
│   └── pro_4_affiliate.html
├── FAS/                              # Fullworks Anti-Spam
│   ├── free_1_welcome.html           ✓
│   ├── free_2_check_stats.html       ✓
│   ├── free_3_pro_pitch.html         ✓
│   ├── free_4_review_request.html    ✓
│   ├── trial_1-5
│   └── pro_1-4
├── QPP/                              # Quick PayPal Payments
│   ├── free_1-5
│   ├── trial_1-5
│   └── pro_1-4
├── SUE/                              # Stop User Enumeration
│   └── free_1-4
├── SWEGTS/                           # Stop WP Emails Going to Spam
│   └── free_1-4
├── CFCS/                             # Contact Form Clean and Simple
│   └── free_1-4
├── SSGM/                             # Simple Google Maps Shortcode
│   └── free_1-3
├── RSHFD/                            # Remove Site Health
│   └── free_1-3
├── MMT/                              # Meet my Team
│   └── free_1-2
├── LHF/                              # Load HTML Files
│   └── free_1-2
├── FS/                               # Fullworks Scanner
│   └── free_1-2
├── AUM/                              # Active Users Monitor
│   └── free_1-2
└── shared/
    ├── cross-sell-fas.html
    ├── cross-sell-dee.html
    ├── affiliate-pitch.html
    └── review-request.html
```

---

# IMPLEMENTATION PRIORITY

## Phase 1 (Done)
- [x] DEE free sequence (5 emails)
- [x] FAS free sequence (4 emails)

## Phase 2 (High Value)
- [ ] SUE free sequence (50k installs - biggest cross-sell funnel)
- [ ] SWEGTS free sequence (10k installs)
- [ ] DEE trial sequence
- [ ] FAS trial sequence

## Phase 3 (Medium Value)
- [ ] CFCS free sequence (8k installs)
- [ ] DEE pro sequence
- [ ] FAS pro sequence
- [ ] QPP all sequences

## Phase 4 (Lower Priority)
- [ ] SSGM, RSHFD (Tier 2)
- [ ] MMT, LHF, FS, AUM (Tier 3)

---

# TECHNICAL NOTES

## Subscriber Attributes

Each plugin uses these attributes:
```
{plugin_id}_drip_stage    # e.g., "free_1", "trial_3", "pro_2", "complete"
{plugin_id}_drip_next     # ISO date for next email
{plugin_id}_drip_started  # ISO date when sequence started
{plugin_id}_status        # "free", "trial", "pro"
```

## Sequence Triggers

- **Free:** Triggered when user opts in with `status = free`
- **Trial:** Triggered when `status` changes to `trial`
- **Pro:** Triggered when `status` changes to `pro` (or `premium`)

## Unsubscribe Links

Listmonk transactional emails don't include unsubscribe by default. Use this workaround with a dummy list UUID (the zeros work as a valid placeholder):
```html
<a href="https://email.fw9.uk/subscription/00000000-0000-0000-0000-000000000000/{{ .Subscriber.UUID }}">Unsubscribe</a>
```

## Marketing Allowed Check

All sequences only run when `marketing_allowed = true`
