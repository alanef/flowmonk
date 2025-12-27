# Transactional Email Templates

These templates are used with Listmonk for drip email sequences.

## Folder Structure

```
transactional/
├── DEE/           # Display Eventbrite Events (plugin 1330)
├── FAS/           # Fullworks Anti-Spam (plugin 5065)
└── QPP/           # Quick PayPal Payments (plugin 5623)
```

## Template Naming Convention

`{type}_{step}_{description}.html`

Examples:
- `free_1_welcome.html` - First email in free sequence
- `free_2_getting_started.html` - Second email in free sequence
- `trial_1_trial_started.html` - First email when trial begins
- `premium_1_thank_you.html` - Purchase confirmation

## Listmonk Template Variables

- `{{ .Subscriber.Name }}` - Subscriber's name
- `{{ .Subscriber.Email }}` - Subscriber's email
- `{{ .Subscriber.UUID }}` - For unsubscribe links
- `{{ .Subscriber.Name | default "there" }}` - With fallback

## Copying to Listmonk

1. Create new template in Listmonk (Campaigns > Templates)
2. Set type to "Transactional"
3. Copy HTML content
4. Note the template ID for drip sequence config