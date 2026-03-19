#!/bin/bash
# Runs the full build with WordPress plugin standards review.
# build.sh skips the review by default for speed.
SKIP_REVIEW=0 bash "$(dirname "$0")/build.sh" "$@"
