#!/usr/bin/env bash
# Prints the Helm values that follow from a project's URL and domain: the
# media URL, the Mercure hub's public URL and where the HTTPRoute attaches.
# Used by k8s/deploy.sh and by CI.
#
#   --hostname <domain>  the project's own Gateway "site" (k8s/project-manifests.sh),
#                        routes only for this host
#   --node-ips           also infra's public-gateway (https listener, node IPs),
#                        routes for any host: only one project may do this
# Without either: public-gateway only, for any host.
#
# Usage: k8s/site-values.sh https://kate.example.com [--hostname kate.example.com] [--node-ips] > site.yaml
set -euo pipefail

usage() { echo "Usage: $0 <site-url> [--hostname <domain>] [--node-ips]" >&2; exit 1; }
[ $# -ge 1 ] || usage
SITE_URL="${1%/}"
shift
HOSTNAME_=""
NODE_IPS=false
while [ $# -gt 0 ]; do
  case "$1" in
    --hostname) [ $# -ge 2 ] || usage; HOSTNAME_="$2"; shift 2 ;;
    --node-ips) NODE_IPS=true; shift ;;
    *) usage ;;
  esac
done
[ -n "${HOSTNAME_}" ] || NODE_IPS=true

refs=()
hostnames="[]"
if [ -n "${HOSTNAME_}" ]; then
  refs+=('{"name": "site", "sectionName": "https"}')
  hostnames="[\"${HOSTNAME_}\"]"
fi
if [ "${NODE_IPS}" = true ]; then
  refs+=('{"name": "public-gateway", "namespace": "gateway-system", "sectionName": "https"}')
  hostnames="[]"   # node IPs carry no hostname
fi
PARENT_REFS="[$(IFS=,; echo "${refs[*]}")]"

SITE_URL="${SITE_URL}" PARENT_REFS="${PARENT_REFS}" HOSTNAMES="${hostnames}" yq -n '
  .typo3.extraEnv.TYPO3_S3_PUBLIC_URL = strenv(SITE_URL) + "/media/typo3/" |
  .mercure.publicUrl = strenv(SITE_URL) + "/.well-known/mercure" |
  .httpRoute.parentRefs = env(PARENT_REFS) |
  .httpRoute.hostnames = env(HOSTNAMES)'
