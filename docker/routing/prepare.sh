#!/usr/bin/env bash
# Downloads an OSM extract and builds the OSRM graph (MLD) into ./data.
# Usage: ./prepare.sh [geofabrik-path]   e.g. europe/germany/berlin (default), europe/germany, europe/austria
set -euo pipefail
REGION="${1:-europe/germany/berlin}"
IMAGE="ghcr.io/project-osrm/osrm-backend:v6.0.0"
cd "$(dirname "$0")"
mkdir -p data vroom-conf
curl -fL "https://download.geofabrik.de/${REGION}-latest.osm.pbf" -o data/region.osm.pbf
docker run --rm -t -v "$PWD/data:/data" "$IMAGE" osrm-extract -p /opt/car.lua /data/region.osm.pbf
docker run --rm -t -v "$PWD/data:/data" "$IMAGE" osrm-partition /data/region.osrm
docker run --rm -t -v "$PWD/data:/data" "$IMAGE" osrm-customize /data/region.osrm
cat > vroom-conf/config.yml <<'YML'
cliArgs:
  geometry: false
  planmode: false
  threads: 4
  explore: 5
  limit: '5mb'
  logdir: '/..'
  logsize: '100M'
  maxlocations: 1000
  maxvehicles: 200
  override: true
  path: ''
  port: 3000
  router: 'osrm'
  timeout: 30000
  baseurl: '/'
routingServers:
  osrm:
    car:
      host: 'osrm'
      port: '5000'
YML
echo "Done. Start with: docker compose up -d"
