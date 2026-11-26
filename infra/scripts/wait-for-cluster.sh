#!/usr/bin/env bash
# Waits until all k3s nodes and the dashboard are ready, then writes a kubeconfig.
# Env: MASTER_IP, EXPECTED_NODES, REGISTRY, KUBECONFIG_OUT (default ./kubeconfig), TIMEOUT (seconds, default 1200)
set -euo pipefail

: "${MASTER_IP:?MASTER_IP is required}"
: "${EXPECTED_NODES:?EXPECTED_NODES is required}"
KUBECONFIG_OUT="${KUBECONFIG_OUT:-./kubeconfig}"
TIMEOUT="${TIMEOUT:-1200}"

ssh_master() {
  ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
      -o ConnectTimeout=5 -o LogLevel=ERROR "ubuntu@${MASTER_IP}" "$@"
}

deadline=$((SECONDS + TIMEOUT))
wait_until() {
  local what="$1"; shift
  echo "Waiting for ${what} ..."
  until "$@" >/dev/null 2>&1; do
    if (( SECONDS > deadline )); then
      echo "Timed out waiting for ${what}. Check: ssh ubuntu@${MASTER_IP} 'sudo cloud-init status --long; sudo journalctl -u k3s'" >&2
      exit 1
    fi
    sleep 10
  done
}

nodes_ready() {
  local ready
  ready=$(ssh_master "sudo k3s kubectl get nodes --no-headers 2>/dev/null | awk '\$2 == \"Ready\"' | wc -l")
  [[ "${ready// /}" -ge "${EXPECTED_NODES}" ]]
}

wait_until "SSH on master ${MASTER_IP}" ssh_master true
wait_until "k3s API" ssh_master "sudo k3s kubectl get --raw=/readyz"
wait_until "${EXPECTED_NODES} Ready nodes" nodes_ready
wait_until "dashboard deployment" ssh_master "sudo k3s kubectl -n headlamp get deploy headlamp"
wait_until "dashboard rollout" ssh_master "sudo k3s kubectl -n headlamp rollout status deploy/headlamp --timeout=30s"

# Platform for applications
k="sudo k3s kubectl"
wait_until "registry" ssh_master "curl -fsS http://${REGISTRY:-127.0.0.1:30500}/v2/"
wait_until "NFS storage driver" ssh_master "$k -n kube-system rollout status deploy/csi-nfs-controller --timeout=30s"
wait_until "mariadb-operator" ssh_master "$k -n mariadb-system rollout status deploy/mariadb-operator --timeout=30s"
wait_until "Gateway" ssh_master "$k -n gateway-system wait gateway/public-gateway --for=condition=Programmed --timeout=30s"

ssh_master "cat /etc/rancher/k3s/k3s.yaml" | sed "s/127.0.0.1/${MASTER_IP}/" > "${KUBECONFIG_OUT}"
chmod 600 "${KUBECONFIG_OUT}"

echo "Cluster ready. Kubeconfig written to ${KUBECONFIG_OUT}"
ssh_master "sudo k3s kubectl get nodes -o wide"
