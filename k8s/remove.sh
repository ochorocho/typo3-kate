#!/usr/bin/env bash
# Removes a deployed project from the cluster: its Helm release, its namespace
# (database, Valkey, Garage S3, Gateway, certificate, Secrets) and the NFS
# volumes it leaves behind. The files under projects/<project>/ stay, and so
# do the NFS directories on the master (Retain): delete those by hand once
# you no longer need fileadmin.
#
# Usage: k8s/remove.sh <project> [--yes]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail() { echo "Error: $*" >&2; exit 1; }

PROJECT=""
YES=false
for arg in "$@"; do
  case "${arg}" in
    --yes) YES=true ;;
    -*) fail "unknown option ${arg}" ;;
    *) [ -z "${PROJECT}" ] || fail "one project at a time"; PROJECT="${arg}" ;;
  esac
done
[ -n "${PROJECT}" ] || fail "Usage: k8s/remove.sh <project> [--yes]"

PROJECT_FILE="${ROOT}/projects/${PROJECT}/kate.yaml"
NAMESPACE="${PROJECT}"
if [ -f "${PROJECT_FILE}" ]; then
  NAMESPACE="$(yq -r ".namespace // \"${PROJECT}\"" "${PROJECT_FILE}")"
fi

for tool in kubectl helm jq yq tofu; do
  command -v "${tool}" >/dev/null || fail "${tool} is not installed"
done
# shellcheck source=k8s/cluster.sh
. "${ROOT}/k8s/cluster.sh"

kubectl get namespace "${NAMESPACE}" >/dev/null 2>&1 || fail "namespace ${NAMESPACE} does not exist"
owner="$(kubectl get namespace "${NAMESPACE}" -o json | jq -r '.metadata.annotations["typo3-kate/project"] // ""')"
[ "${owner}" = "${PROJECT}" ] \
  || fail "namespace ${NAMESPACE} is not marked as project ${PROJECT} (annotation typo3-kate/project: '${owner}'); deploy it once with k8s/deploy.sh ${PROJECT}, or remove it by hand"

# NFS volumes use reclaimPolicy Retain: remember them before the claims go
pvs="$(kubectl get pv -o json | jq -r --arg ns "${NAMESPACE}" \
  '.items[] | select(.spec.claimRef.namespace == $ns) | "\(.metadata.name) \(.spec.persistentVolumeReclaimPolicy) \(.spec.csi.volumeHandle // "-")"')"

echo "This deletes project ${PROJECT}: namespace ${NAMESPACE} with its database, files and Secrets."
[ -z "${pvs}" ] || { echo "Volumes:"; while read -r line; do echo "  ${line}"; done <<<"${pvs}"; }
if [ "${YES}" = false ]; then
  read -r -p "Type the project name to confirm: " answer
  [ "${answer}" = "${PROJECT}" ] || fail "not confirmed, nothing deleted"
fi

helm -n "${NAMESPACE}" uninstall "${RELEASE:-typo3}" --wait 2>/dev/null || echo "No Helm release ${RELEASE:-typo3} in ${NAMESPACE}"
kubectl delete namespace "${NAMESPACE}" --wait --timeout=10m

# The Retain volumes are Released now; their objects can go, the data stays
while read -r name policy handle; do
  [ -n "${name}" ] && [ "${policy}" = Retain ] || continue
  kubectl delete pv "${name}" --ignore-not-found
  echo "  data kept on the NFS server: ${handle}"
done <<<"${pvs}"

echo "Removed ${PROJECT}. Left to you: its DNS record and, when no longer needed, the NFS data above (on the master under /srv/nfs)."
