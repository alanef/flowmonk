# Cloudflare Tunnel for Webhook Testing

This directory contains scripts to expose your local FlowMonk instance to the internet via Cloudflare Tunnel. This is useful for testing webhooks from external services (Freemius, etc.) against your local development environment.

**Note**: This setup is intended for development/testing only. For production, deploy FlowMonk to a proper hosting environment.

## Architecture

```
Internet                    Your Machine
────────                    ────────────

Freemius ──▶ email-tunnel.fw9.uk ──▶ Cloudflared ──▶ LPI (8081) ──▶ FlowMonk (8082)
                                          │
                                          └── Or directly to FlowMonk if LPI disabled
```

## Prerequisites

1. **Cloudflare Account** with a domain configured
2. **cloudflared** CLI installed: https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/
3. **LocalProxyInspector (optional)**: Download from https://lpi.tools

## Setup

### 1. Create Cloudflare Tunnel

```bash
# Login to Cloudflare
cloudflared tunnel login

# Create a tunnel (use your own name)
cloudflared tunnel create email-tunnel

# This creates a credentials JSON file - copy it to this directory
cp ~/.cloudflared/<tunnel-id>.json ./email-tunnel-credentials.json
```

### 2. Configure DNS

Add a CNAME record in Cloudflare DNS:
- Name: `email-tunnel` (or your subdomain)
- Target: `<tunnel-id>.cfargotunnel.com`

Or use cloudflared to create it:
```bash
cloudflared tunnel route dns email-tunnel email-tunnel.yourdomain.com
```

### 3. Update Configuration

Edit `email-tunnel-config.yml`:

```yaml
tunnel: your-tunnel-name
credentials-file: /path/to/email-tunnel-credentials.json

ingress:
  - hostname: email-tunnel.yourdomain.com
    service: http://localhost:8081  # LPI port, or 8082 for direct
  - service: http_status:404
```

### 4. Update start.sh

Update paths in `start.sh` to match your system:
- Path to `email-tunnel-config.yml`
- Path to LPI database directory

## Usage

### With LocalProxyInspector (Recommended for Debugging)

LPI lets you inspect webhook requests before they reach FlowMonk.

```bash
# Start tunnel + LPI
./start.sh

# View LPI inspection UI
open http://localhost:3082
```

The flow is:
1. Webhook hits `https://email-tunnel.yourdomain.com`
2. Cloudflared routes to LPI on port 8081
3. LPI logs the request and forwards to FlowMonk on 8082
4. You can inspect requests at http://localhost:3082

### Without LocalProxyInspector

If you don't need request inspection, edit `email-tunnel-config.yml`:

```yaml
ingress:
  - hostname: email-tunnel.yourdomain.com
    service: http://localhost:8082  # Direct to FlowMonk
  - service: http_status:404
```

And comment out the LPI section in `start.sh`:

```bash
# Start lpi (COMMENTED OUT)
# nohup konsole ... lpi --proxy 8081 ...
```

### Stopping

```bash
./stop.sh
```

## Files

| File | Purpose |
|------|---------|
| `start.sh` | Starts cloudflared tunnel and LPI in Konsole windows |
| `stop.sh` | Stops all tunnel services |
| `email-tunnel-config.yml` | Cloudflared tunnel configuration |
| `email-tunnel-credentials.json` | Tunnel credentials (gitignored, contains secrets) |

## Configuring Webhooks

Once running, configure your webhook source to send to:
```
https://email-tunnel.yourdomain.com/webhook/freemius/{product_id}
```

## Troubleshooting

### Tunnel not connecting
- Check credentials file path is correct
- Verify tunnel exists: `cloudflared tunnel list`
- Check DNS is configured

### Webhooks not arriving
- Verify FlowMonk is running on port 8082
- Check LPI is running on port 8081 (if using)
- Look at LPI UI for incoming requests

### Konsole not available (non-KDE systems)
Replace `konsole` in `start.sh` with your terminal:
- GNOME: `gnome-terminal`
- macOS: `osascript` or `open -a Terminal`
- Generic: Run commands in separate terminal tabs manually