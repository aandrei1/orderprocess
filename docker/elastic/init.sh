#!/bin/sh
# DEV-ONLY one-shot: prepares the log stack for Filebeat and Kibana.
#  1. index template for orders-* (stock Filebeat 8.x insists on data streams,
#     which we do not want, so the template is managed here instead)
#  2. Kibana data view "orders-*" on @timestamp
# Runs inside the php container (make logs-init), so the host needs no sh/curl.
# Idempotent: safe to re-run.
set -eu

ES=${ES_URL:-http://localhost:9200}
KIBANA=${KIBANA_URL:-http://localhost:5601}

echo "Waiting for Kibana..."
until curl -fs "$KIBANA/api/status" | grep -q '"level":"available"'; do sleep 3; done

echo "Putting index template orders..."
curl -fsS -X PUT "$ES/_index_template/orders" -H 'Content-Type: application/json' -d '{
  "index_patterns": ["orders-*"],
  "priority": 200,
  "template": {
    "settings": {"number_of_shards": 1, "number_of_replicas": 0},
    "mappings": {
      "dynamic_templates": [
        {"strings_as_keyword": {
          "match_mapping_type": "string",
          "mapping": {"type": "keyword", "ignore_above": 1024}
        }}
      ],
      "properties": {
        "@timestamp": {"type": "date"},
        "message": {"type": "text"}
      }
    }
  }
}'
echo

echo "Creating Kibana data view orders-*..."
curl -fsS -X POST "$KIBANA/api/data_views/data_view" \
  -H 'kbn-xsrf: true' -H 'Content-Type: application/json' \
  -d '{"override": true, "data_view": {"id": "orders", "name": "orders", "title": "orders-*", "timeFieldName": "@timestamp"}}' \
  >/dev/null
echo "Done."
