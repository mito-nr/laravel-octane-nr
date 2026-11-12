#!/bin/sh
# Generates steady traffic so both pipelines have data in New Relic.
# usage: ./scripts/load.sh [count] [base_url]
COUNT="${1:-100}"
BASE="${2:-http://localhost:8000}"

i=1
while [ "$i" -le "$COUNT" ]; do
  curl -s -o /dev/null -w "parallel   %{http_code} %{time_total}s\n" "$BASE/api/parallel?n=3"
  curl -s -o /dev/null -w "sequential %{http_code} %{time_total}s\n" "$BASE/api/sequential?n=3"
  if [ $((i % 10)) -eq 0 ]; then
    curl -s -o /dev/null -w "error      %{http_code}\n" "$BASE/api/error"
  fi
  i=$((i + 1))
done
