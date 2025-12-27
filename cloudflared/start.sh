#!/bin/bash
./stop.sh
# Base position - starting point for the cascade
x_pos=50
y_pos=50

# Window dimensions (narrow width)
width=800
height=400

# Start HTTP tunnel
nohup konsole --noclose -p tabtitle="HTTP Tunnel - email-tunnel" --geometry ${width}x${height}+${x_pos}+${y_pos} -e "cloudflared tunnel --config /home/alan/projects/github.com/alanef/fullworks-email-helpers/cloudflared/email-tunnel-config.yml run email-tunnel.fw9.uk"  &
x_pos=$((x_pos + 30))
y_pos=$((y_pos + 30))
sleep 1


# Start lpi
nohup konsole --noclose -p tabtitle="LPI - email-tunnel" --geometry ${width}x${height}+${x_pos}+${y_pos} -e "lpi --proxy 8081 --target http://localhost:8082 --ui 3082  --db /home/alan/.lpi/email"  &
x_pos=$((x_pos + 30))
y_pos=$((y_pos + 30))
sleep 1