# shellcheck shell=bash
# Sourced by k8s/deploy.sh and k8s/remove.sh: connects kubectl and helm to
# the cluster in infra/. Needs ROOT and a fail() function.
#
# Sets KUBECONFIG, REGISTRY, MASTER_IP and WORK_DIR (a temporary directory,
# removed on exit together with the SSH tunnel, if one was needed).
# Env: KUBECONFIG (default infra/kubeconfig), REGISTRY (default: tofu output)

export KUBECONFIG="${KUBECONFIG:-${ROOT}/infra/kubeconfig}"
[ -f "${KUBECONFIG}" ] || fail "no kubeconfig at ${KUBECONFIG} (run tofu apply in infra/)"
REGISTRY="${REGISTRY:-$(tofu -chdir="${ROOT}/infra" output -raw registry)}"
MASTER_IP="${REGISTRY%%:*}"

WORK_DIR="$(mktemp -d)"
TUNNEL_CTL=""
cleanup() {
  if [ -n "${TUNNEL_CTL}" ]; then
    ssh -S "${TUNNEL_CTL}" -O exit "ubuntu@${MASTER_IP}" 2>/dev/null || true
  fi
  rm -rf "${WORK_DIR}"
}
trap cleanup EXIT

# macOS may block kubectl/helm from the VM subnet (Local Network privacy) while
# ssh is allowed. Then talk to the API through an SSH tunnel on localhost;
# the k3s API certificate is valid for 127.0.0.1.
if ! kubectl get --raw /readyz >/dev/null 2>&1; then
  echo "API not reachable directly, using an SSH tunnel to ${MASTER_IP}"
  # The first free local port, so several deploys can run at the same time
  port=""
  for candidate in $(seq 16443 16463); do
    if ssh -f -N -M -S "${WORK_DIR}/ctl" -o ExitOnForwardFailure=yes \
        -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR \
        -L "127.0.0.1:${candidate}:127.0.0.1:6443" "ubuntu@${MASTER_IP}" 2>/dev/null; then
      port="${candidate}"
      break
    fi
  done
  [ -n "${port}" ] || fail "no SSH tunnel to ${MASTER_IP} (ports 16443-16463 busy or ssh refused)"
  TUNNEL_CTL="${WORK_DIR}/ctl"
  sed "s#https://${MASTER_IP}:6443#https://127.0.0.1:${port}#" "${KUBECONFIG}" > "${WORK_DIR}/kubeconfig"
  export KUBECONFIG="${WORK_DIR}/kubeconfig"
  kubectl get --raw /readyz >/dev/null || fail "cluster not reachable, also not through SSH"
fi
