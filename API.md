# API Documentation

## Auth — `api/auth.php`

| Action | Method | Auth | Params | Description |
|--------|--------|------|--------|-------------|
| `login` | POST | Anonymous | `username`, `password` | Start session, returns user info |
| `logout` | POST | User | — | Destroy session |
| `check` | GET | Anonymous | — | Check if authenticated |

## User — `api/user.php`

| Action | Method | Auth | Params | Description |
|--------|--------|------|--------|-------------|
| `get_stats` | GET | User | — | Points, diamonds, stars, hunger/death status, flower_slots |
| `get_settings` | GET | User | — | Username, questions_per_challenge |
| `change_password` | POST | User | `new_password` | Min 4 chars |
| `update_settings` | POST | User | `questions_per_challenge` | 3–5 |
| `revive` | POST | User | — | Revive own chicken |

## Challenge — `api/challenge.php`

| Action | Method | Auth | Params | Description |
|--------|--------|------|--------|-------------|
| `generate` | GET | User | `type` (pet\|feed\|revive) | Get questions (1 for pet, 3–5 for feed/revive) |
| `submit` | POST | User | `results[]` {id, attempts}, `type` | Award points/diamonds/stars, track knowledge, feed/revive |

## Shop — `api/shop.php`

| Action | Method | Auth | Params | Description |
|--------|--------|------|--------|-------------|
| `list` | GET | User | — | All items with remaining count & affordability |
| `buy` | POST | User | `item_id` | Purchase item, deduct currency, assign decoration slot |

## Admin — `api/admin.php`

| Action | Method | Auth | Params | Description |
|--------|--------|------|--------|-------------|
| `list_users` | GET | Admin | — | All users with stats |
| `user_stats` | GET | Admin | `user_id` | Knowledge tracking per question |
| `get_yaml` | GET | Admin | — | Raw questions.yaml content |
| `shop_stats` | GET | Admin | — | Shop items + per-user ownership matrix |
| `list_tokens` | GET | Admin | — | Access tokens + require_token config |
| `save_yaml` | POST | Admin | `content` | Overwrite questions.yaml |
| `reset_feed` | POST | Admin | `user_id` | Clear last_fed timer |
| `create_user` | POST | Admin | `username`, `password`, `isadmin` | Create new user |
| `delete_user` | POST | Admin | `user_id` | Delete user (not self) |
| `edit_user` | POST | Admin | `user_id`, `username`, `isadmin`, `password`?, `total_points`?, `diamonds`?, `stars`? | Update user |
| `clean_deleted_stats` | POST | Admin | `user_id` | Remove stats for deleted questions |
| `clear_decorations` | POST | Admin | `user_id` | Remove all decorations & refund currency |
| `kill_chicken` | POST | Admin | `user_id` | Set is_dead=1 |
| `revive_chicken` | POST | Admin | `user_id` | Set is_dead=0, clear last_fed |
| `generate_token` | POST | Admin | — | Create access token (1yr expiry) |
| `delete_token` | POST | Admin | `token_id` | Delete access token |
| `toggle_require_token` | POST | Admin | — | Toggle require_access_token setting |

## Push Notifications — `api/push.php`

| Action | Method | Auth | Params | Description |
|--------|--------|------|--------|-------------|
| `vapid_public_key` | GET | Anonymous | — | VAPID public key for subscription |
| `push_debug` | GET | Admin | — | Subscription counts & details |
| `subscribe` | POST | User | `endpoint`, `keys` | Register push subscription |
| `unsubscribe` | POST | User | `endpoint` | Remove push subscription |
| `test_notify` | POST | Admin | `user_id` | Send test push to user |
| `clear_subscriptions` | POST | Admin | `user_id` | Delete all subs for user |
| `send_hungry` | POST | Admin | — | Send hunger alerts to all hungry users |

## AI Questions — `api/ai_questions.php`

| Action | Method | Auth | Params | Description |
|--------|--------|------|--------|-------------|
| `check_key` | GET | Admin | — | Check if OpenAI API key is configured |
| `generate` | POST | Admin | `topic` | Generate 5 Spanish→German vocab pairs via OpenAI |
| `add` | POST | Admin | `questions[]` | Append selected questions to questions.yaml |

## Cron — `api/cron_hungry.php`

| Method | Auth | Params | Description |
|--------|------|--------|-------------|
| GET | Token | `token` (must match cron_secret) | Send hunger push notifications to all hungry users |

## Utility — `api/questions.php`

| Method | Auth | Description |
|--------|------|-------------|
| GET | Anonymous | Returns parsed questions from YAML (also used as library by other endpoints) |
