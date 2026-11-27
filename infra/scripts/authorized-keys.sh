#!/usr/bin/env bash
# Writes the extra SSH keys from kate.yaml (ssh.authorizedKeys) into a managed
# block of ~ubuntu/.ssh/authorized_keys on every node. Lines outside the block,
# like the key cloud-init installed, are left alone. An empty KEYS removes the
# block. Runs over SSH, so the VMs are not rebuilt.
# Env: NODE_IPS (space-separated), KEYS (one key per line, may be empty)
set -euo pipefail

: "${NODE_IPS:?NODE_IPS is required}"
KEYS="${KEYS:-}"

BEGIN="# BEGIN kate.yaml ssh.authorizedKeys (managed by OpenTofu)"
END="# END kate.yaml ssh.authorizedKeys"

block=""
if [ -n "${KEYS}" ]; then
  block="${BEGIN}"$'\n'"${KEYS}"$'\n'"${END}"
fi

for ip in ${NODE_IPS}; do
  # Drop the old block, append the new one, replace the file in one step.
  ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
      -o ConnectTimeout=5 -o LogLevel=ERROR "ubuntu@${ip}" \
      "f=~/.ssh/authorized_keys; t=\$(mktemp ~/.ssh/.authorized_keys.XXXXXX)
       awk -v b='${BEGIN}' -v e='${END}' '\$0==b{skip=1;next} \$0==e{skip=0;next} !skip' \"\$f\" > \"\$t\"
       grep . >> \"\$t\" || true
       chmod 600 \"\$t\" && mv \"\$t\" \"\$f\"" <<< "${block}"
  echo "SSH keys on ${ip}: $(printf '%s' "${KEYS}" | grep -c . || true) from kate.yaml"
done
