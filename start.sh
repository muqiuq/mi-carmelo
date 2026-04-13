#!/bin/bash

# Configuration
PORT=8080
CONTAINER_NAME="mi-carmelo-app"
IMAGE_NAME="micarmelo"

echo "Starting Mi Carmelo on podman..."

# Ensure data directory exists on the host
mkdir -p "$PWD/data"
chmod 777 "$PWD/data"

# Stop and remove the container if it already exists
if podman ps -a --format '{{.Names}}' | grep -Eq "^${CONTAINER_NAME}\$"; then
    echo "Removing existing container..."
    podman stop $CONTAINER_NAME >/dev/null 2>&1
    podman rm $CONTAINER_NAME >/dev/null 2>&1
fi

# Build the image
podman build -t $IMAGE_NAME -f Containerfile . >/dev/null 2>&1

# Run the container
podman run -d \
    --name $CONTAINER_NAME \
    -p $PORT:80 \
    -v "$PWD/data":/var/www/html/data \
    $IMAGE_NAME

echo "Waiting for container to start..."
sleep 2

# Trigger first request to initialize DB
curl -s "http://localhost:8080/api/auth.php?action=check" > /dev/null 2>&1

# Fix permissions on newly created files (DB, VAPID keys)
chmod 666 "$PWD/data"/* 2>/dev/null

if podman ps --format '{{.Names}}' | grep -Eq "^${CONTAINER_NAME}\$"; then
    echo "Container started successfully!"
    echo "You can view the application at: http://localhost:${PORT}"
else
    echo "ERROR: Container failed to start. Logs:"
    podman logs $CONTAINER_NAME 2>&1
fi
