# Anixart for Stremio

Anime add-on for Stremio: catalog, search, and primary video streams from Anixart.
Pure PHP, without frameworks or dependencies.

## Features

- catalog of updates and search across the entire Anixart catalog
- several voiceovers to choose from (AniDUB, AniLibria, SHIZA Project, etc.)
- direct streams without torrents and local servers: mp4 (Sibnet), HLS up to 720p (Kodik, Rutube)
- works without authorization
- caching of responses and posters, resistance to backend failures

## Requirements

This project is not a standalone desktop app. It must be hosted by a web server and served over HTTPS, because Stremio only accepts third-party add-ons through a trusted secure URL.

- a web server (for example Apache)
- PHP 8.5 with the `curl` extension enabled
- a domain or local HTTPS address trusted by Stremio

> Example setup: Caddy + PHP

## Installation

1. Install a local web server and PHP.
2. Clone or copy this repository into the web server's document root.
Example:
```bash
cd /var/www/ && mkdir stremio-anixart && git clone https://github.com/sutoca/stremio-anixart
```
3. Make sure the add-on is reachable by its manifest URL:
> https://example.com/manifest.json
4. Open `https://example.com/configure` in your browser and click "Install in Stremio".
> Alternatively, add the manifest manually in Stremio:
> Stremio → Community add-ons → insert the link.
5. Done: the Anixart catalog and search will appear in the add-ons section.
> In short: install the web server, put this repository into the served folder, and open the add-on through HTTPS.

## License
[MIT](LICENSE)