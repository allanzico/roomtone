# Roomtone

A party soundtrack built from what guests love, even guests who can't name a song.

Guests scan a QR code and type three things they love: a show, a food, a place, an artist. A DJ agent uses the [Qloo Taste AI API](https://www.qloo.com/) to turn those tastes into a set, and the host screen shows the party as a living 3D room where every song points at the guest it was picked for.

Built for the Qloo Agentic Hackathon.

## Run it locally

Requirements: PHP 8.3+, Composer, Node 22+.

```bash
composer install
npm install
cp .env.example .env        # skip if you already have a .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
composer run dev
```

Open http://localhost:8000/e/DEMO/host for the demo party.

On the host screen: `G` adds a guest, `N` skips to the next track, `E` cycles the energy level.

## Tests

```bash
php artisan test
```

## License

MIT, see [LICENSE](LICENSE).
