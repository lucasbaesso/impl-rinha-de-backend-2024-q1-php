#!/bin/bash

# Stop and remove containers
docker compose -f docker-compose-dev.yml down

# Build and start containers in detached mode
docker compose -f docker-compose-dev.yml up -d --build
