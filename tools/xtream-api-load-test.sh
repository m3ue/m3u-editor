#!/bin/bash

# Xtream API Load Testing Script
# Tests multiple concurrent connections to random channels

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Functions
print_header() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  Xtream API Load Test${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${YELLOW}ℹ $1${NC}"
}

# Prompt for input
prompt_input() {
    local prompt="$1"
    local default="$2"
    local input
    
    if [ -n "$default" ]; then
        read -p "$(echo -e ${BLUE}$prompt [${default}]: ${NC})" input
        echo "${input:-$default}"
    else
        read -p "$(echo -e ${BLUE}$prompt: ${NC})" input
        echo "$input"
    fi
}

cleanup() {
    print_info "Cleaning up..."
    # Kill all background processes created by this script
    jobs -p | xargs -r kill 2>/dev/null || true
    print_success "Test stopped"
}

trap cleanup EXIT INT TERM

# Main
print_header

# Get Xtream API credentials
echo -e "${YELLOW}Xtream API Configuration:${NC}"
API_URL=$(prompt_input "Xtream API URL" "http://m3u-editor.test:8000")
USERNAME=$(prompt_input "Username" "test")
PASSWORD=$(prompt_input "Password" "test")

# Strip trailing slash from URL
API_URL="${API_URL%/}"

# Choose stream type
echo
echo -e "${YELLOW}Stream Type:${NC}"
echo "  1) Live Channels"
echo "  2) VOD (Movies)"
STREAM_TYPE=$(prompt_input "Select stream type" "1")

case $STREAM_TYPE in
  1)
    ACTION="get_live_streams"
    STREAM_TYPE_NAME="Live Channels"
    ;;
  2)
    ACTION="get_vod_streams"
    STREAM_TYPE_NAME="VOD"
    ;;
  *)
    print_error "Invalid selection"
    exit 1
    ;;
esac

# Get test parameters
echo
echo -e "${YELLOW}Test Configuration:${NC}"
NUM_CONNECTIONS=$(prompt_input "Number of concurrent streams" "10")
STREAM_DURATION=$(prompt_input "Duration per stream in seconds" "2")
TIMEOUT=$(prompt_input "Connection timeout in seconds" "10")

# Validate inputs
if ! [[ "$NUM_CONNECTIONS" =~ ^[0-9]+$ ]]; then
    print_error "Number of streams must be a number"
    exit 1
fi

if ! [[ "$STREAM_DURATION" =~ ^[0-9]+$ ]]; then
    print_error "Stream duration must be a number"
    exit 1
fi

if ! [[ "$TIMEOUT" =~ ^[0-9]+$ ]]; then
    print_error "Timeout must be a number"
    exit 1
fi

if [ "$NUM_CONNECTIONS" -lt 1 ]; then
    print_error "Number of streams must be at least 1"
    exit 1
fi

echo
echo -e "${YELLOW}Fetching available channels...${NC}"

# Fetch channels
CHANNELS_RESPONSE=$(curl -s "$API_URL/player_api.php?username=$USERNAME&password=$PASSWORD&action=$ACTION")

# Extract channel IDs
CHANNEL_IDS=$(echo "$CHANNELS_RESPONSE" | grep -o '"stream_id":[0-9]*' | grep -o '[0-9]*' | sort -u)

if [ -z "$CHANNEL_IDS" ]; then
    print_error "No $STREAM_TYPE_NAME found. Check your credentials and API URL."
    exit 1
fi

CHANNEL_ARRAY=($CHANNEL_IDS)
TOTAL_CHANNELS=${#CHANNEL_ARRAY[@]}

print_success "Found $TOTAL_CHANNELS $STREAM_TYPE_NAME"
echo

# Prepare test
echo -e "${YELLOW}Test Parameters:${NC}"
echo "  API URL: $API_URL"
echo "  Username: $USERNAME"
echo "  Stream Type: $STREAM_TYPE_NAME"
echo "  Concurrent streams: $NUM_CONNECTIONS"
echo "  Duration per stream: ${STREAM_DURATION}s"
echo "  Stream timeout: ${TIMEOUT}s"
echo "  Total $STREAM_TYPE_NAME available: $TOTAL_CHANNELS"
echo

read -p "$(echo -e ${BLUE}Press Enter to start the load test...${NC})"
echo

# Track statistics
START_TIME=$(date +%s)
STREAM_COUNT=0
SUCCESSFUL_STREAMS=0
FAILED_STREAMS=0

print_info "Starting load test with $NUM_CONNECTIONS concurrent stream(s)..."
echo

# Function to stream a random channel
stream_random_channel() {
    local conn_id=$1
    local stream_duration=$2
    local random_idx=$((RANDOM % TOTAL_CHANNELS))
    local channel_id=${CHANNEL_ARRAY[$random_idx]}
    
    # Build stream URL based on stream type
    if [ "$ACTION" = "get_live_streams" ]; then
        local stream_url="$API_URL/live/$USERNAME/$PASSWORD/$channel_id.ts"
    else
        local stream_url="$API_URL/movie/$USERNAME/$PASSWORD/$channel_id.mp4"
    fi
    
    # Get current time
    local current_time=$(date +%s)
    local elapsed=$((current_time - START_TIME))
    
    # Stream with timeout and capture bytes - keep stream open for the specified duration
    # Use -L to follow redirects
    local bytes_received=$(timeout "$stream_duration" curl -sL -m "$stream_duration" "$stream_url" 2>/dev/null | wc -c)
    
    if [ "$bytes_received" -gt 1024 ]; then
        # Convert bytes to KB for display
        local kb_received=$((bytes_received / 1024))
        echo "[$(printf '%03d' $elapsed)s] Connection #$conn_id: ${GREEN}✓${NC} Channel $channel_id ($kb_received KB received)"
        return 0
    else
        echo "[$(printf '%03d' $elapsed)s] Connection #$conn_id: ${RED}✗${NC} Channel $channel_id (no data)"
        return 1
    fi
}

# Open concurrent connections
declare -A PIDS

print_info "Opening $NUM_CONNECTIONS concurrent stream(s)..."

for i in $(seq 1 $NUM_CONNECTIONS); do
    (
        stream_random_channel $i $STREAM_DURATION
        echo "[INFO] Connection #$i completed"
    ) &
    
    PIDS[$i]=$!
done

print_success "All streams started"
echo

# Wait for all connections to complete
print_info "Waiting for all $NUM_CONNECTIONS stream(s) to complete..."
echo

for i in $(seq 1 $NUM_CONNECTIONS); do
    wait ${PIDS[$i]} 2>/dev/null || true
done

# Summary
echo
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Test Complete${NC}"
echo -e "${BLUE}========================================${NC}"

CURRENT_TIME=$(date +%s)
ACTUAL_DURATION=$((CURRENT_TIME - START_TIME))

echo "Test ran for: ${ACTUAL_DURATION}s"
echo "Concurrent streams: $NUM_CONNECTIONS"
echo

print_success "Load test finished"
