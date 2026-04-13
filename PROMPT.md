- Name of the App: Mi Carmelo
- JavaScript App with PHP Backend
- Twitter Bootstrap  5.3.8
- Vanilla JavaScript
- Database SQLITE
- Mobile First App
- Always test what you implemented
- challenge question database: YAML file with question answer set. Each answer can have multiple correct answers
- A little chick that needs feeding every 8 hours (configurable)
- To buy food a challenge must be completed
- the challenge is free random questions from the database
- one can always "pet" the baby chicken
- The baby chicken is animated
- the giving food feature is animated
- Every 10 correct words (in the first try) gives one diamond
- ten diamonds turn into 1 star (with animation when reached)
- the challenge: display question - user has box to enter response - if correct: show correct and user can go next with button, if not correct, display not correct and the correct answer and a Repeat button - display progress during the the challenge
- for the pet challenge only one question must be answered
- if the chicken is hungry, it will show a bubble "I'm hungry :-( Feed me"
- the feed button is grayed out with a 🚫 emoji when the chicken is not hungry, with a countdown timer showing time until next feed
- In the database it should store which challenges (question / answers) the player already knows well
- in every 3 question challenge there should be at least one word the user knows well
- the number of question per challenge can be configured by the user itself (3 min, 5 max)
- For every correct answer at the first try it gives 10 points, for every at the second try 5 points, for every at the third try or more 1 point
- a admin and user login is required (users in the database with isadmin)
- the database should be created at the first run
- default logins: user = carmelo (isadmin = no), admin = queen (isadmin = yes)
- users can change their password
- each user has it's own chicken and own score
- automated end to end tests with a suitable framework 
- No "?>" at the end of PHP files if they are not required
- All parameters for example how often the chicken needs feeding, scoring, etc, is in one config file somewhere
- NEVER RUN node or npm directly on the host devices. always in the container!
- All external dependencies (Bootstrap CSS/JS) are downloaded locally into vendor/ — no CDN
- Proper .gitignore for db/, node_modules/, test artifacts, OS files
- Runs in a podman container on macOS with a proper Containerfile
- Entrypoint script handles file permissions for volume-mounted development

Chicken Sprite:
- The chicken is a pure CSS-drawn sprite (no emojis) with individual body parts: head, beak (top/bottom), eye, comb, wing, tail, legs

Animations:
- default: Chicken walking around (16s cycle, left to right and back, starting/ending at center) and looks around
- hungry: chicken is "shouting" hunger (by opening its mouth more and less and jumping from one leg to another)
- pet: chicken is happy and dances a little (3.6s)
- eat: chicken pecking animation (3s)
- star: celebration animation when earning a star (2.5s)

Admin Panel:
- List all users with their stats
- Create new users
- Edit users (username, password, admin flag)
- Delete users (with confirmation)
- Reset feed timer for a specific user
- Structured question editor UI (add/edit/delete individual questions and answers, not raw YAML)
- Confirm dialog when deleting a question

Push Notifications:
- Web Push Notifications using Service Worker + VAPID keys
- Users can toggle notifications on/off from the game view
- Admin can send a test notification to any user
- Admin can send hunger alerts to all users with hungry chickens