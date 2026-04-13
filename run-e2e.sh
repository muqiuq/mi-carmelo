#!/bin/bash

# run-e2e.sh
# This script spins up a Podman container to run Playwright E2E tests 
# against the running application on the host.

IMAGE="mcr.microsoft.com/playwright:v1.59.1-jammy"

echo "Running Playwright E2E tests inside Podman container..."
echo "Ensure your application is currently running (via start.sh on port 8080)"

# On macOS with podman, the host is reachable via host.containers.internal
# We need --userns=keep-id so the container user can read/write the mounted volume
podman run --rm \
    --userns=keep-id \
    -v "$PWD":/app \
    -w /app \
    -e BASE_URL=http://host.containers.internal:8080 \
    $IMAGE \
    /bin/bash -c "npm install && npx playwright test"