#!/usr/bin/env bash
# Prints the per-project routing manifests, applied by k8s/deploy.sh into the
# project's namespace:
#  - Gateway "site": one HTTPS listener for the project's domain. Every Gateway
#    on port 8443 lands on Traefik's websecure entrypoint; Traefik picks the
#    certificate by SNI, so projects never touch infra's public-gateway.
#  - Certificate "site": Let's Encrypt for the domain (secret site-tls), via
#    the ClusterIssuers from infra/templates/acme.yaml.tftpl
#  - HTTPRoute "media": /media/ -> Garage, attached like the chart's route
#    (parents and hostnames from k8s/site-values.sh)
# Without a domain only the media route is printed (node IPs, public-gateway).
#
# Usage: k8s/project-manifests.sh [--hostname <domain> --issuer <letsencrypt|letsencrypt-staging>] [--node-ips]
set -euo pipefail

usage() { echo "Usage: $0 [--hostname <domain> --issuer <cluster-issuer>] [--node-ips]" >&2; exit 1; }
HOSTNAME_=""
ISSUER=""
site_args=()
while [ $# -gt 0 ]; do
  case "$1" in
    --hostname) [ $# -ge 2 ] || usage; HOSTNAME_="$2"; site_args+=(--hostname "$2"); shift 2 ;;
    --issuer) [ $# -ge 2 ] || usage; ISSUER="$2"; shift 2 ;;
    --node-ips) site_args+=(--node-ips); shift ;;
    *) usage ;;
  esac
done
[ -z "${HOSTNAME_}" ] || [ -n "${ISSUER}" ] || usage

if [ -n "${HOSTNAME_}" ]; then
  cat <<YAML
apiVersion: gateway.networking.k8s.io/v1
kind: Gateway
metadata:
  name: site
spec:
  gatewayClassName: traefik
  listeners:
    # Port 8443 = Traefik's "websecure" entrypoint (port 443 on every node)
    - name: https
      hostname: "${HOSTNAME_}"
      protocol: HTTPS
      port: 8443
      tls:
        mode: Terminate
        certificateRefs:
          - name: site-tls
      allowedRoutes:
        namespaces:
          from: Same
---
apiVersion: cert-manager.io/v1
kind: Certificate
metadata:
  name: site
spec:
  secretName: site-tls
  dnsNames:
    - "${HOSTNAME_}"
  issuerRef:
    kind: ClusterIssuer
    name: ${ISSUER}
---
YAML
fi

# The URL only matters for values this route doesn't use
route="$("$(dirname "$0")/site-values.sh" https://unused ${site_args[@]+"${site_args[@]}"} | yq -o json -I0 '.httpRoute')"
echo "# https://<site>/media/<key> -> Garage web endpoint, bucket \"media\"."
echo "# Longer than the chart's \"/\" route, so it wins for /media."
ROUTE="${route}" yq -n '
  .apiVersion = "gateway.networking.k8s.io/v1" |
  .kind = "HTTPRoute" |
  .metadata.name = "media" |
  .spec.parentRefs = env(ROUTE).parentRefs |
  .spec.hostnames = env(ROUTE).hostnames |
  .spec.rules = [{
    "matches": [{"path": {"type": "PathPrefix", "value": "/media/"}}],
    "filters": [{"type": "URLRewrite", "urlRewrite": {"hostname": "media", "path": {"type": "ReplacePrefixMatch", "replacePrefixMatch": "/"}}}],
    "backendRefs": [{"name": "garage", "port": 3902}]
  }] |
  del(.spec.hostnames | select(length == 0)) |
  ... style = ""'
