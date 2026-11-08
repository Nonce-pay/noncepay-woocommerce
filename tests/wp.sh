#!/bin/bash
# Runs wp-cli against the test site: tests/wp.sh plugin list
cd "$(dirname "$0")" && exec docker compose run --rm -T cli "$@" 2> >(grep -v "^ *Container " >&2)
