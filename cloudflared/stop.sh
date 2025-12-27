# Function to kill Konsole process by tab title
kill_by_tabtitle() {
    local tabtitle="$1"
    echo "Stopping $tabtitle..."

    # Find PID by tab title and kill it
    pid=$(ps aux | grep "tabtitle=$tabtitle" | grep -v grep | awk '{print $2}')

    if [ -n "$pid" ]; then
        echo "Found process with PID: $pid"
        kill $pid
    else
        echo "No process found with tabtitle: $tabtitle"
    fi
}

# Stop all development Konsole windows

kill_by_tabtitle "HTTP Tunnel - email-tunnel"

kill_by_tabtitle "LPI - email-tunnel"


echo "All tunnel services stopped."