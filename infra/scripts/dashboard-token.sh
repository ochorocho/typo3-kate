#!/usr/bin/env bash
# Prints the login token for the Headlamp dashboard. Usage: dashboard-token.sh <master-ip>
set -euo pipefail

MASTER_IP="${1:?usage: $0 <master-ip>}"

ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR \
  "ubuntu@${MASTER_IP}" \
  "sudo k3s kubectl -n headlamp get secret dashboard-admin-token -o jsonpath='{.data.token}' | base64 -d"
echo
