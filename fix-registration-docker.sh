#!/bin/bash

# Hi.Events Registration Fix Script for Docker
# This script fixes the registration issue by running the fix inside the Docker container

echo "Hi.Events Docker Registration Fix"
echo "================================="
echo ""

# Check if Docker is available
if ! command -v docker &> /dev/null; then
    echo "Error: Docker is not installed or not available in PATH"
    exit 1
fi

# Check if backend container is running
if ! docker ps | grep -q "backend"; then
    echo "Error: Backend container is not running"
    echo "Please start your Hi.Events Docker containers first"
    exit 1
fi

echo "Running registration fix in Docker container..."
echo ""

# Run the fix script inside the container
docker exec backend php fix-registration.php

# Check the exit code
if [ $? -eq 0 ]; then
    echo ""
    echo "✓ Registration fix completed successfully!"
    echo "You can now try registering again at your Hi.Events instance."
else
    echo ""
    echo "✗ Registration fix failed. Please check the error messages above."
    echo ""
    echo "Alternative solutions:"
    echo "1. Run migrations: docker exec backend php artisan migrate"
    echo "2. Run seeders: docker exec backend php artisan db:seed"
    echo "3. Run the custom command: docker exec backend php artisan hi-events:fix-account-configuration"
fi
