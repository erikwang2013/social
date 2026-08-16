#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../contracts"
buf generate
echo "PHP stubs generated (service/generated, admin/generated)"
