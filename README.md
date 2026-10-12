# Newspack Live

An unofficial live-blog plugin for [Newspack](https://newspack.com).

> **Unofficial project.** Newspack Live is an independent, personal project. It is not made, endorsed, sponsored or supported by Automattic or the Newspack team, and it is not affiliated with the author's employer. "Newspack", "WordPress" and related names are trademarks of their respective owners, used here only to describe compatibility.

Newspack Live adds live coverage of developing stories to Newspack sites: election nights, storms, trials, breaking news. A live is a regular post with live coverage turned on.

## Features

- Live coverage on any post, with Upcoming, Live and Ended states.
- A newsroom console to publish short updates fast, on desktop and mobile, with several editors at once.
- A reader page with a Context card, a pinned update, entry labels and a "new updates" button. Works without JavaScript.
- Reader questions, with a moderation inbox and spam protection.
- Integrations with Newspack Content Gate, Reader Activation, Newspack Ads, Co-Authors Plus and `LiveBlogPosting` structured data.

## Requirements

- WordPress 6.7 or later
- PHP 8.1 or later
- The [Newspack plugin](https://newspack.com/download-center/), installed and active

## Installation

1. Build the plugin zip with `npm run release:archive` (output: `release/newspack-live-{version}.zip`).
2. In WordPress, go to Plugins → Add New → Upload Plugin, upload the zip and activate it.
3. Go to Live → New live.

## Development

    nvm use && npm ci && bin/composer.sh install
    npm run build
    bin/dod.sh   # lint, tests and build in one run

Local setup and all commands: [AGENTS.md](AGENTS.md).

## License

[GPL-2.0-or-later](LICENSE). Distributed without any warranty.
