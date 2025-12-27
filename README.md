# FlowMonk

A powerful automation suite for [Listmonk](https://listmonk.app/) that adds drip email sequences, dunning workflows, and webhook processing.

## Features

- **Drip Email Sequences**: Automated email sequences triggered by subscriber events
- **Dunning Workflows**: Double opt-in reminder sequences with automatic blocklisting
- **Webhook Processing**: Receive and process webhooks from Freemius, Freelib, and custom sources
- **Web UI**: Manage sequences, monitor queues, and view statistics
- **SQLite Storage**: Lightweight, file-based state management
- **Multi-Product Support**: Configure different sequences per product/plugin

## Architecture

FlowMonk consists of two services:

- **campaign-list-builder**: PHP web UI for configuration and monitoring
- **drip-controller**: Cron-based service that processes drip sequences

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Webhooks      │────▶│  FlowMonk UI    │────▶│    Listmonk     │
│ (Freemius etc.) │     │  (Port 8082)    │     │   (Email API)   │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                               │
                               ▼
                        ┌─────────────────┐
                        │ Drip Controller │
                        │   (Cron Job)    │
                        └─────────────────┘
```

## Quick Start

### Prerequisites

- Docker and Docker Compose
- A running [Listmonk](https://listmonk.app/) instance

### Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/alanef/fullworks-email-helpers.git
   cd fullworks-email-helpers
   ```

2. Copy the example environment file and configure:
   ```bash
   cp .env.example .env
   # Edit .env with your Listmonk credentials
   ```

3. Start the services:
   ```bash
   docker compose up -d
   ```

4. Access the web UI at http://localhost:8082

### Configuration

Edit `.env` with your settings:

```bash
# Listmonk API
LISTMONK_URL=https://your-listmonk-instance.com
LISTMONK_USER=api
LISTMONK_PASS=your-api-password

# FlowMonk Authentication
APP_USER=admin
APP_PASS=your-secure-password

# Optional
APP_NAME=FlowMonk
APP_PORT=8082
LOG_LEVEL=info
```

## Usage

### Setting Up a Drip Sequence

1. Navigate to **Drip Config** in the web UI
2. Add a new Product with your plugin/product ID
3. Create sequence types (e.g., "Free", "Trial", "Pro")
4. Add steps with template IDs, delays, and subjects

### Webhook Integration

FlowMonk accepts webhooks at:
- `POST /webhook/freemius/{product_id}` - Freemius webhooks (HMAC verified)
- `POST /webhook/freelib/{product_id}` - Freelib webhooks
- `POST /webhook/other/{product_id}` - Generic webhooks

### Monitoring

- **Drip Queue**: View pending, due, and completed drip emails
- **Drip Stats**: See completion rates and trends by product
- **Audit**: Review webhook processing logs

## Development

### Local Development with Hot Reload

The `docker-compose.override.yml` file mounts source directories for live editing:

```bash
docker compose up -d
# Edit files in campaign-list-builder/ or drip-controller/
# Changes are reflected immediately
```

### Manual Drip Run

```bash
# Run drip processor manually
docker exec drip-controller php /app/drip-runner.php

# Dry run (no emails sent)
docker exec -e DRY_RUN=true drip-controller php /app/drip-runner.php
```

### Project Structure

```
fullworks-email-helpers/
├── campaign-list-builder/     # Web UI service
│   ├── public/                # Web root (index.php router)
│   ├── src/                   # PHP classes
│   └── templates/             # Page templates
├── drip-controller/           # Cron service
│   ├── src/                   # PHP classes
│   └── drip-runner.php        # Main entry point
├── shared/                    # Shared code
│   └── src/                   # SequenceDatabase, etc.
├── docker-compose.yml         # Production/Coolify config
├── docker-compose.override.yml # Local dev additions
└── .env.example               # Environment template
```

## Deployment

### Coolify

FlowMonk is designed to work with [Coolify](https://coolify.io/). The `docker-compose.yml` is the production configuration that Coolify will use automatically.

### Manual Docker Deployment

```bash
docker compose -f docker-compose.yml up -d
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

This project is licensed under the MIT License - see [LICENSE.md](LICENSE.md) for details.

## Credits

- Built on [Listmonk](https://listmonk.app/) - Self-hosted newsletter and mailing list manager
- UI styled with [Pico CSS](https://picocss.com/)
- Interactivity powered by [Alpine.js](https://alpinejs.dev/)