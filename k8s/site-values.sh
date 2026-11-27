#!/usr/bin/env bash
# Prints the Helm values that follow from the site's URL: the media URL and
# the Mercure hub's public URL. Used by k8s/deploy.sh and by CI.
#
# Usage: k8s/site-values.sh https://kate.example.com > site.yaml
set -euo pipefail

[ $# -eq 1 ] || { echo "Usage: $0 <site-url>" >&2; exit 1; }
SITE_URL="${1%/}" yq -n '
  .typo3.extraEnv.TYPO3_S3_PUBLIC_URL = strenv(SITE_URL) + "/media/typo3/" |
  .mercure.publicUrl = strenv(SITE_URL) + "/.well-known/mercure"'
