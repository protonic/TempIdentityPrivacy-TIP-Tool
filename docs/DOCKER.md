# Running TIP Tool with Docker

The Docker setup runs three containers:

| Container | What it does |
|---|---|
| `app` | PHP 8.3 + Apache serving TIP Tool on port 8080 |
| `db` | MySQL 8.4, data kept in the `db` volume |
| `cron` | Fetches mail every minute and removes expired addresses every hour |

The image is published at `ghcr.io/protonic/tip-tool`, for `linux/amd64` and `linux/arm64` (Raspberry Pi 4/5 included).

You still need what every TIP Tool install needs: a domain with a **catch-all IMAP mailbox**. Docker doesn't provide the mail server.

## 1. Install

You need Docker with the Compose plugin (Docker Desktop on Windows and macOS, or Docker Engine on Linux).

```bash
git clone https://github.com/protonic/TempIdentityPrivacy-TIP-Tool.git
cd TempIdentityPrivacy-TIP-Tool
cp .env.example .env
```

Only `docker-compose.yml` and `.env` are needed to run the published image, so you can copy just those two files to your server instead of cloning.

## 2. Fill in `.env`

| Setting | What to put |
|---|---|
| `SITE_URL` | The address people use, e.g. `https://mail.example.com` |
| `DB_PASS`, `DB_ROOT_PASS` | Two long random passwords |
| `CRON_SECRET_KEY` | 32+ random characters: `openssl rand -hex 32` |
| `ADMIN_USERNAME`, `ADMIN_USER_EMAIL`, `ADMIN_PASSWORD` | Your first admin account (password 10-72 characters) |

> **Keep `CRON_SECRET_KEY` safe and unchanged.** It encrypts the IMAP passwords stored in the database. If you lose or change it, re-enter each domain's IMAP password in the admin panel.

Every other setting has a working default and is explained in `.env.example`.

## 3. Start

```bash
docker compose up -d
docker compose logs -f app
```

On first start the `app` container waits for MySQL, imports the schema, and creates your admin account. When you see `Admin account "admin" created`, open `http://localhost:8080/admin/login`, sign in, and add your catch-all mailbox under **Manage Domains**.

Then **remove `ADMIN_PASSWORD` from `.env`**. It is only read when no admin exists yet.

`setup.php` is not included in the image, so there is nothing to delete.

## HTTPS and reverse proxies

The container serves plain HTTP. In production, put it behind a reverse proxy that handles TLS, such as Caddy, Traefik, nginx, or a Cloudflare Tunnel.

Set `TRUSTED_PROXIES` to your proxy's address (an IP or CIDR, comma-separated). TIP Tool then reads the visitor's real IP from `X-Forwarded-For` and the original scheme from `X-Forwarded-Proto`. Without it, every visitor looks like the proxy, which breaks rate limiting and admin session pinning.

Example with Caddy on the same host:

```caddyfile
mail.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

```dotenv
SITE_URL=https://mail.example.com
TRUSTED_PROXIES=172.16.0.0/12
```

`172.16.0.0/12` covers Docker's default bridge networks, which is where a host proxy's requests come from. If Cloudflare proxies the site, also set `TRUST_CF_CONNECTING_IP=true`.

Mailbox pages redirect to HTTPS unless you open them on `localhost`, so test on `localhost` or through your HTTPS proxy.

## Upgrading

```bash
docker compose pull
docker compose up -d
```

Pin a version with `TIP_VERSION=1.2.0` in `.env` if you don't want to follow `latest`.

## Backups

All data is in MySQL. To back it up:

```bash
docker compose exec db sh -c 'mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > tiptool-backup.sql
```

Keep your `.env` with the backup, because the dump is useless without `CRON_SECRET_KEY`.

## Building the image yourself

```bash
docker compose up -d --build
```

This builds from the local source instead of pulling from GHCR.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `app` exits with `CRON_SECRET_KEY must be at least 32 characters` | Set it in `.env` |
| `Waiting for database` repeats, then fails | Check `DB_*` values; `docker compose logs db` |
| No mail arrives | `docker compose logs cron`, and check the IMAP settings in **Manage Domains** |
| Redirect loop or every visitor has the same IP | Set `TRUSTED_PROXIES` to your proxy's address |
| App logs | `docker compose exec app tail -f logs/error.log` |

## For maintainers: publishing the image

`.github/workflows/docker.yml` builds the image on pull requests that touch Docker files, and publishes it to GHCR when you push a version tag:

```bash
git tag v1.0.0
git push origin v1.0.0
```

That publishes `1.0.0`, `1.0`, and `latest`. After the first publish, open the package on GitHub (**Packages → tip-tool → Package settings**) and set its visibility to **Public** so anyone can pull it without logging in.
