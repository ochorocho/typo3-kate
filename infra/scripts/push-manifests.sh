#!/usr/bin/env bash
# Copies the platform manifests (written by OpenTofu to MANIFEST_DIR) to the
# master, where k3s applies everything in /var/lib/rancher/k3s/server/manifests.
# Managed files get a "tofu-" prefix; ones that no longer exist locally are
# removed, and k3s then deletes their resources. Then optionally waits for
# the platform.
# Env: MASTER_IP, MANIFEST_DIR, WAIT (1/0), REGISTRY, ACME_ENABLED (1/0), TIMEOUT (default 1200)
set -euo pipefail

: "${MASTER_IP:?MASTER_IP is required}"
: "${MANIFEST_DIR:?MANIFEST_DIR is required}"
TIMEOUT="${TIMEOUT:-1200}"
REMOTE=/var/lib/rancher/k3s/server/manifests

ssh_master() {
  ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
      -o ConnectTimeout=5 -o LogLevel=ERROR "ubuntu@${MASTER_IP}" "$@"
}

# --- copy ------------------------------------------------------------------
wanted=()
for file in "${MANIFEST_DIR}"/*.yaml; do
  name="tofu-$(basename "${file}")"
  wanted+=("${name}")
  ssh_master "sudo install -D -m 600 /dev/stdin ${REMOTE}/${name}" < "${file}"
  echo "Copied ${name}"
done
for name in $(ssh_master "cd ${REMOTE} && ls tofu-*.yaml 2>/dev/null || true"); do
  if [[ ! " ${wanted[*]} " == *" ${name} "* ]]; then
    ssh_master "sudo rm -f ${REMOTE}/${name}"
    echo "Removed ${name}"
  fi
done

[ "${WAIT:-1}" = 1 ] || exit 0

# --- wait --------------------------------------------------------------------
deadline=$((SECONDS + TIMEOUT))
k="sudo k3s kubectl"
wait_until() {
  local what="$1"; shift
  echo "Waiting for ${what} ..."
  until ssh_master "$@" >/dev/null 2>&1; do
    if (( SECONDS > deadline )); then
      echo "Timed out waiting for ${what}." >&2
      return 1
    fi
    sleep 10
  done
}

wait_until "dashboard" "$k -n headlamp rollout status deploy/headlamp --timeout=30s"
wait_until "registry" "curl -fsS http://${REGISTRY:-127.0.0.1:30500}/v2/"
wait_until "NFS storage driver" "$k -n kube-system rollout status deploy/csi-nfs-controller --timeout=30s"
wait_until "mariadb-operator" "$k -n mariadb-system rollout status deploy/mariadb-operator --timeout=30s"
wait_until "cert-manager" "$k -n cert-manager rollout status deploy/cert-manager-webhook --timeout=30s"
if [ "${ACME_ENABLED:-0}" = 1 ]; then
  if ! wait_until "Let's Encrypt certificate" "$k -n gateway-system wait certificate/site --for=condition=Ready --timeout=30s"; then
    ssh_master "$k -n gateway-system describe certificate site | tail -15; $k get challenges -A -o wide" >&2 || true
    exit 1
  fi
fi
wait_until "Gateway" "$k -n gateway-system wait gateway/public-gateway --for=condition=Programmed --timeout=30s"
echo "Platform ready."
