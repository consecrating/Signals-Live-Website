# Signals Live Website

This repository is the source snapshot for the live Signals site.

## Layout

- `signal.html` — live signal and autonomous paper-execution page
- `tool.html` — paper-trading dashboard
- `js/` — browser-side signal, God Mode, and paper-trading engines
- `api/` — PHP proxy and safety kernel

## Deployment paths

Deploy the repository contents beneath the site’s `/signals/` path: HTML files to `/signals/`, JavaScript files to `/signals/js/`, and PHP files to `/signals/api/`.

Secrets and runtime brain data are intentionally excluded. In particular, do not commit `api/config.secret.php`, `brain-data/`, logs, or generated temporary files.
