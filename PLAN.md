# Implementation Plan: Micarmelo (Virtual Pet Chicken)

## Step 1: Project Setup & Infrastructure
- Initialize the project structure (folders for frontend, backend, database, and assets).
- Set up the index HTML file with Twitter Bootstrap 5.3.8 (Mobile First approach).
- Create basic PHP routing structure and API endpoints layout (omitting closing `?>` tags).
- Create the `questions.yaml` file structure with initial sample questions.

## Step 2: Database & Backend Data Handling
- Set up the SQLite database schema:
  - `users` (id, username, password_hash, isadmin, last_fed, diamonds, stars, correct_streak_count, total_points, questions_per_challenge).
  - `user_knowledge` (user_id, question_id, correct_attempts, incorrect_attempts, knows_well_threshold).
- Create a PHP script to automatically initialize the SQLite database and create default users (`carmelo`/`queen`) on the first run.
- Create a PHP service to parse the `questions.yaml` file to use as the challenge question pool.

## Step 3: User Authentication & Settings
- Build login and registration UI. Default logins provided: `carmelo` (user) and `queen` (admin).
- Implement PHP backend login, session management, and admin flag checks.
- Add user settings panel (change own password, configure questions per challenge: min 3, max 5).
- Add admin panel:
  - Configure/manage users.
  - Edit `questions.yaml` file (interface to view and save YAML content).

## Step 4: Frontend Shell & UI Components
- Build the main UI layout (Status bar for Diamonds, Stars, and Points). The app supports multiple users, each with their own chicken and score.
- Build the central interaction area for the baby chicken.
- Add main action buttons (Feed, Pet).
- Implement the "Hungry" speech bubble UI ("I'm hungry :-( Feed me") dependent on state.

## Step 5: Baby Chicken Animations
- Implement CSS/JavaScript-based animations for the baby chicken:
  - Idle state.
  - Feeding animation.
  - Petting animation.
  - Star achievement animation.

## Step 6: The Challenge Engine (Core Gameplay)
- **Backend API:** Endpoint to fetch a set of random questions.
  - Must respect: out of a 3-question challenge, at least 1 word the user "knows well".
- **Frontend UI:** 
  - Challenge modal/overlay.
  - Question display area.
  - Text input for the answer.
  - Progress indicator during a challenge.
  - Feedback UI: "Correct" (with Next button) and "Incorrect" (showing correct answer + Repeat button).

## Step 7: Rewards System & Analytics
- **Backend API:** Endpoint to report success/failure.
- Calculate Points: 10 pts for 1st try, 5 pts for 2nd try, 1 pt for 3rd+ try.
- Track knowledge: Update `user_knowledge` per question.
- Track Diamonds/Stars:
  - Every 10 correct first-try words = +1 Diamond.
  - Every 10 Diamonds = +1 Star.
- **Frontend Integration:** Update the UI counters dynamically, show points. Trigger the "Star earned" animation.

## Step 8: Tying it Together (The Game Loop)
- Connect actions to challenges:
  - Clicking "Feed" opens a personalized, multi-question challenge based on user settings (3-5 questions) and knowledge. Completing it triggers the feeding animation and resets the hunger timer.
  - Clicking "Pet" opens a 1-question challenge. Completing it triggers the petting animation.
- Ensure the state persists between reloads via the PHP/SQLite backend.

## Step 9: Testing & Refinement
- Automated end-to-end tests with a suitable framework (e.g., Playwright or Cypress).
- End-to-end testing of all core loops:
  - YAML parsing accuracy.
  - Answer validation (including multiple correct answer support).
  - Rewards miscalculation edges.
  - Mobile responsiveness and layout testing.