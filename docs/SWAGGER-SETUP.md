# Swagger / OpenAPI setup

- Local UI: `http://localhost:8010/api/docs` (isolated test API: `http://localhost:8011/api/docs`). The UI endpoint is restricted to local/testing environments.
- Machine-readable JSON: `GET /api/docs/openapi.json`; checked-in generated document: `docs/openapi.json`.
- Regenerate: `python3 scripts/generate_openapi.py`. The generator calls Laravel `route:list --json`, inspects controller `validate([...])` rules, and records route middleware for Sanctum, roles, and `EnsureAdmin` permissions. It does not run migrations or write to a database.
- Authenticate: Swagger UI's Authorize action uses HTTP Bearer. Paste the Sanctum personal access token (without the `Bearer ` prefix).
- The document declares local API servers on ports 8010 and 8011. Update the server list for other environments; production interactive UI remains disabled by default.

The generator emits OpenAPI 3.0.3, path parameters, validated query/body fields and constraints, multipart file schemas when validation declares a file, validation/authentication/authorization errors, authentication and role/permission extensions, named resource and pagination schemas, and examples for key booking/payment/reminder flows. XLSX and protected media endpoints use binary response schemas. Counselor/CMS and clinical/finance/reminder response mappings live in `scripts/generate_openapi.py`; do not edit the generated JSON directly.

Route coverage is generated from registered API routes. Response payloads for several legacy endpoints are still broad/generic, and status-code variants may differ between controller actions. Do not treat this document as 100% exact response-shape coverage. Extend the response mapping in the generator when those controllers are reconciled; regenerate with `python3 scripts/generate_openapi.py` after route, validation, or schema changes. Never edit `docs/openapi.json` by hand.

Swagger UI assets are currently loaded from Swagger UI CDN. Offline browser use requires a pinned local distribution. The spec contains no environment values or provider credentials.
