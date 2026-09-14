#!/usr/bin/env bash
# Manual smoke test for local_scorm_maker_import_import_book_from_html.
# Usage: ./call_import_book_from_html.sh <wwwroot> <wstoken> <courseid>
# Example: ./call_import_book_from_html.sh http://localhost:8080 abc123... 2

set -euo pipefail

WWWROOT="${1:?Usage: $0 <wwwroot> <wstoken> <courseid>}"
TOKEN="${2:?Usage: $0 <wwwroot> <wstoken> <courseid>}"
COURSEID="${3:?Usage: $0 <wwwroot> <wstoken> <courseid>}"
HTMLFILE="$(dirname "$0")/sample_book.html"

curl -sS "${WWWROOT}/webservice/rest/server.php" \
  --data-urlencode "wstoken=${TOKEN}" \
  --data-urlencode "wsfunction=local_scorm_maker_import_import_book_from_html" \
  --data-urlencode "moodlewsrestformat=json" \
  --data-urlencode "courseid=${COURSEID}" \
  --data-urlencode "name=Livro de Teste" \
  --data-urlencode "description=<p>Importado via script de teste.</p>" \
  --data-urlencode "htmlcontent@${HTMLFILE}"
echo
