# Skandha (PHP)

The site and its own server in PHP (PDO SQLite), exported from UniversalIDE. PHP 8.1 or later with
the PDO SQLite extension (most hosts have it); nothing to install.

```sh
ADMIN_KEY=choose-a-long-secret php -S localhost:8000 -t public router.php    # http://localhost:8000
```

**On a web host (Apache):** upload the folder and make `public/` the site's web root (the
document root). `public/.htaccess` sends the API to `public/index.php`; set `ADMIN_KEY` (and
`DATA_DIR` if you like) in the host's control panel or with `SetEnv` in that file. The host needs
mod_rewrite and mod_headers. Or with Docker: `docker build -t site . && docker run -p 8080:80 -e ADMIN_KEY=… -v site-data:/data site`.

## Layout

- `public/`: the site's pages, styles, pictures and exact copies, served as they are, and
  `index.php` for the API.
- `src/server.php`: the server: `POST /api/forms/:id`, `POST /api/views`, `/api/admin/…`, and
  the site's files under `php -S` (`router.php`).
- `data/`: the database (outside the web root). The sending limits keep a hash of a visitor's
  address for a minute, in the database, since PHP keeps nothing between requests.

## Messages and statistics

Forms on the site that send to you post to `/api/forms/<form id>`; the messages are kept in the
database (the newest 2,000). Page views are counted a day per page, with the sites visitors came
from; nothing about the visitors themselves is kept. Read them with the admin key:

```sh
curl -H "Authorization: Bearer $ADMIN_KEY" http://localhost:8000/api/admin/messages
curl -H "Authorization: Bearer $ADMIN_KEY" "http://localhost:8000/api/admin/stats?days=30"
curl -X DELETE -H "Authorization: Bearer $ADMIN_KEY" http://localhost:8000/api/admin/messages/<id>
```

| Setting | |
|---|---|
| `PORT` | where it listens |
| `DATA_DIR` | the folder for the database (`site.sqlite`) |
| `ADMIN_KEY` | lets you read messages and statistics; without it those addresses answer 404 |
| `TRUST_PROXY=1` | behind a proxy: a visitor's address (for the sending limits only) is the first in `X-Forwarded-For` |

`server.json` holds the site's forms, pages and rules; `site/` is the site itself. Both are
written by UniversalIDE: export again to update them.
