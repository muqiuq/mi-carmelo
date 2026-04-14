# Mi Carmelo - virtual pet chicken

Mi Carmelo is a web-based virtual pet chicken game where players care for an animated CSS-drawn chicken by feeding and petting it — but only after completing language challenges. The chicken gets hungry every 8 hours and dies if neglected for 48 hours, but can be revived by solving a challenge.

## ⭐ Features

- Pure CSS-drawn chicken sprite with idle walking, hungry begging, eating, petting, star celebration, and death animations
- Challenge-based feeding: answer 3–5 questions correctly to feed, 1 question to pet
- Reward economy: earn points, diamonds (every 10 correct first-tries), and stars (every 10 diamonds)
- Per-user knowledge tracking: questions answered well are prioritized out, weaker ones appear more often
- Web Push notifications when the chicken is hungry
- Admin panel: manage users, edit questions, debug push subscriptions, access token gating
- Death & revive mechanic with configurable timeouts
- Multi-user support with individual chickens and progress
- Mobile-first responsive UI with vendored Bootstrap 5.3
- Optional URL-based access tokens for restricting access
- Spanish UI, German/Spanish challenge questions
- In-app shop: buy decorative flowers for your chicken's room using earned points
- Six flower color variants (pink, red, blue, purple, orange, white) assigned per slot
- Per-user purchase limits and sold-out tracking
- AI-powered question generation: generate Spanish→German vocabulary pairs via OpenAI (admin only)
- Admin tools: reset decorations with currency refund, clean deleted question stats
- Shop admin overview with per-user ownership matrix
- Cache-busting asset versioning for reliable deployments

## 🔧 Quick start

### Prerequisites

- Podman (or Docker)

### Run locally

```sh
git clone <repo-url>
cd micarmelo
chmod +x start.sh
./start.sh
```

Access at `http://localhost:8080`

Default users:
- `carmelo` / `carmelo` (player)
- `queen` / `queen` (admin)

The database and VAPID keys are auto-generated on first run. Only the `data/` directory is volume-mounted.

### Configure

All game parameters live in `data/game_config.php`:

```php
'feeding_interval_seconds' => 28800,    // 8 hours between feedings
'death_timeout_seconds' => 172800,      // 48 hours until chicken dies
'points_first_try' => 10,
'points_second_try' => 5,
'points_third_plus_try' => 1,
'correct_words_for_diamond' => 10,
'diamonds_for_star' => 10,
'knows_well_threshold' => 3,
'require_access_token' => false,
'base_url' => 'http://localhost:8080/',
```

Questions are defined in `data/questions.yaml`:

```yaml
- question: "1?"
  answers:
    - "eins"

- question: "¿Quién?"
  answers:
    - "wer"
```

### Deploy to production

```sh
chmod +x deploy.sh
./deploy.sh
```

Deploys via rsync to the configured remote host. The database is never overwritten. `game_config.php` is only copied on first deploy.

### Run E2E tests

```sh
chmod +x run-e2e.sh
./run-e2e.sh
```

Playwright tests run inside a container — no local Node.js required.

## ⚠️ Disclaimer

- Portions of this project were developed using Vibe Coding practices.
- An AI assistant (GitHub Copilot) was used during development; review and validate outputs before production use.
