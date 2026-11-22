#!/bin/sh
set -eu
# Invoke from the project root. Compose interpolation and application env_file are separate concerns.
exec docker compose --env-file .env --env-file deploy/compose.env "$@"
