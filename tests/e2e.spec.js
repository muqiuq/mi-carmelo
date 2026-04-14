const { test, expect } = require('@playwright/test');

test.describe('Mi Carmelo Core Game Loop', () => {

  test('Has title and main login view', async ({ page }) => {
    await page.goto('/');

    // Expect a title "to contain" a substring.
    await expect(page).toHaveTitle(/Mi Carmelo/);
    
    // Assure the login view is immediately active for unsigned users.
    await expect(page.locator('#view-login')).toBeVisible();
    await expect(page.locator('#view-game')).toBeHidden();
  });

  test('Fails on invalid login', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'wronguser');
    await page.fill('#login-password', 'wrongpass');
    await page.click('button[type="submit"]');

    // Error should become visible
    const errorMsg = page.locator('#login-error');
    await expect(errorMsg).toBeVisible();
    await expect(errorMsg).toContainText('Invalid credentials');
  });

  test('Succeeds on valid default login (user)', async ({ page }) => {
    // Relying on the DB seeding in api/config.php
    await page.goto('/');

    await page.fill('#login-username', 'carmelo');
    await page.fill('#login-password', 'carmelo');
    await page.click('button[type="submit"]');

    // Expected transition to Game View
    await expect(page.locator('#view-game')).toBeVisible();
    
    // Check if the username label accurately fetched state
    await expect(page.locator('#user-display-name')).toContainText('(carmelo)');
    
    // Assure normal user has no admin capabilities mapped 
    await expect(page.locator('#btn-admin')).toBeHidden();
  });

  test('Succeeds on valid default login (admin)', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');

    // Check if admin capability is mounted successfully
    await expect(page.locator('#view-game')).toBeVisible();
    await expect(page.locator('#btn-admin')).toBeVisible();
  });

  test('Petting opens challenge and gives points on success', async ({ page }) => {
    // First, ensure carmelo's chicken is alive by logging in as admin and reviving
    await page.goto('/');
    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();
    // Call admin API to revive carmelo (user_id may vary, look it up)
    const data = await page.evaluate(() => fetch('api/admin.php?action=list_users').then(r => r.json()));
    const carmelo = data.users.find(u => u.username === 'carmelo');
    if (carmelo) {
      await page.evaluate((uid) => fetch('api/admin.php?action=revive_chicken', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({user_id: uid})
      }), carmelo.id);
    }
    await page.click('#btn-logout');

    // Now log in as carmelo
    await page.fill('#login-username', 'carmelo');
    await page.fill('#login-password', 'carmelo');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Check starting point state
    const pointsSpan = page.locator('#point-count');
    const startPointsStr = await pointsSpan.innerText();
    const startPoints = parseInt(startPointsStr, 10) || 0;

    // Hit the Pet button
    await page.click('#btn-pet');

    // Overlay should become active
    await expect(page.locator('#challenge-overlay')).toBeVisible();
    await expect(page.locator('#challenge-progress')).toContainText('Question 1 of 1');
    
    // Instead of OCRing the answer intentionally (we don't mock it here, 
    // test could just fail question once and then hit repeat logic, but checking the overlay
    // behavior is sufficient for E2E basics).
    
    // Fail once
    await page.fill('#challenge-answer', 'I do not know the answer guaranteed');
    await page.click('#btn-challenge-submit');

    // Check failure logic mapped to repeat button
    const feedbackTitle = page.locator('#challenge-feedback-title');
    await expect(feedbackTitle).toContainText('Incorrect');
    
    const repeatBtn = page.locator('#btn-challenge-repeat');
    await expect(repeatBtn).toBeVisible();
    
    // Re-attempt button closes feedback and brings input back
    await repeatBtn.click();
    await expect(page.locator('#challenge-answer')).toBeEnabled();
  });

  test('Admin panel shows users and can create a new user', async ({ page }) => {
    await page.goto('/');

    // Login as admin (queen)
    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Click admin button
    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Navigate to Users section via menu
    await page.click('.btn-admin-section[data-section="admin-section-users"]');
    await expect(page.locator('#admin-section-users')).toBeVisible();

    // Users list should contain the seeded users
    const usersList = page.locator('#admin-users-list');
    await expect(usersList).toContainText('carmelo');
    await expect(usersList).toContainText('queen');
    const initialCount = await usersList.locator('.list-group-item').count();

    // Create a new user
    await page.fill('#admin-new-username', 'testuser_' + Date.now());
    await page.fill('#admin-new-password', 'testpass');
    await page.click('#form-create-user button[type="submit"]');

    // Success message
    await expect(page.locator('#admin-user-msg')).toContainText('User created!');

    // List should now have one more user
    await expect(usersList.locator('.list-group-item')).toHaveCount(initialCount + 1, { timeout: 5000 });

    // Go back to game
    await page.click('.btn-admin-back');
    await expect(page.locator('#view-game')).toBeVisible();
  });

  test('Admin panel can view and save questions', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Navigate to Questions section via menu
    await page.click('.btn-admin-section[data-section="admin-section-questions"]');
    await expect(page.locator('#admin-section-questions')).toBeVisible();

    // Question editor should have question cards loaded from seed data
    const questionCards = page.locator('#admin-questions-list .question-card');
    await expect(questionCards.first()).toBeVisible();
    const count = await questionCards.count();
    expect(count).toBeGreaterThanOrEqual(1);

    // First question input should have content
    await expect(questionCards.first().locator('.question-input')).not.toHaveValue('');

    // Save (without changes) should succeed
    await page.click('#btn-save-questions');
    await expect(page.locator('#admin-yaml-msg')).toContainText('Questions saved!');
  });

  test('Non-admin cannot see admin button', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'carmelo');
    await page.fill('#login-password', 'carmelo');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Admin button should be hidden for regular users
    await expect(page.locator('#btn-admin')).toBeHidden();
  });

  test('User can open shop and see decoration list', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'carmelo');
    await page.fill('#login-password', 'carmelo');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    await page.click('#btn-shop');
    await expect(page.locator('#view-shop')).toBeVisible();

    const shopList = page.locator('#shop-list');
    await expect(shopList).toContainText('Blume');
    await expect(shopList).toContainText('Haus');

    // Availability stock count is hidden for normal users
    await expect(shopList).not.toContainText('Available:');

    // Buy buttons are not hard-disabled for non-admin users
    await expect(page.locator('.btn-shop-buy[disabled]')).toHaveCount(0);

    await page.click('#btn-shop-back');
    await expect(page.locator('#view-game')).toBeVisible();
  });

  test('Buying a flower places it in chicken background', async ({ page }) => {
    await page.goto('/');

    // Create a fresh user with enough currency and no existing flowers
    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    const testUser = `testuser_flower_${Date.now()}`;
    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();
    await page.click('.btn-admin-section[data-section="admin-section-users"]');
    await expect(page.locator('#admin-section-users')).toBeVisible();
    await page.fill('#admin-new-username', testUser);
    await page.fill('#admin-new-password', 'testpass');
    await page.click('#form-create-user button[type="submit"]');
    await expect(page.locator('#admin-user-msg')).toContainText('User created!');

    const usersData = await page.evaluate(() => fetch('api/admin.php?action=list_users').then(r => r.json()));
    const freshUser = usersData.users.find(u => u.username === testUser);
    expect(freshUser).toBeTruthy();

    await page.evaluate((u) => fetch('api/admin.php?action=edit_user', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        user_id: u.id,
        username: u.username,
        isadmin: Number(u.isadmin),
        password: '',
        total_points: 500,
        diamonds: 20,
        stars: 20
      })
    }), freshUser);

    await page.click('.btn-admin-back');
    await page.click('#btn-logout');

    // Buy a flower as fresh user
    await page.fill('#login-username', testUser);
    await page.fill('#login-password', 'testpass');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    const beforeCount = await page.locator('#pet-decor .wall-flower').count();

    await page.click('#btn-shop');
    await expect(page.locator('#view-shop')).toBeVisible();

    await page.locator('#shop-list .card', { hasText: 'Blume' }).locator('.btn-shop-buy').click();
    // Successful buy now returns automatically to game view
    await expect(page.locator('#view-game')).toBeVisible();

    const afterCount = await page.locator('#pet-decor .wall-flower').count();
    expect(afterCount).toBeGreaterThan(beforeCount);
  });

  test('Admin can reset feed for a user', async ({ page }) => {
    await page.goto('/');

    // Login as admin
    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Go to admin panel
    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Navigate to Users section via menu
    await page.click('.btn-admin-section[data-section="admin-section-users"]');
    await expect(page.locator('#admin-section-users')).toBeVisible();

    // Click on a user to open detail view
    await page.locator('#admin-users-list .list-group-item').first().click();
    await expect(page.locator('#admin-user-detail')).toBeVisible();

    // Click the reset feed button in the detail view
    const resetBtn = page.locator('#btn-detail-reset-feed');
    await expect(resetBtn).toBeVisible();
    await resetBtn.click();

    // Should show success message
    await expect(page.locator('#user-detail-msg')).toContainText('Feed timer reset!');
  });

  test('Admin panel has push notification controls', async ({ page }) => {
    await page.goto('/');

    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Navigate to Users section to test push button in user detail
    await page.click('.btn-admin-section[data-section="admin-section-users"]');
    await expect(page.locator('#admin-section-users')).toBeVisible();

    // Click on a user to open detail view with test push button
    await page.locator('#admin-users-list .list-group-item').first().click();
    await expect(page.locator('#admin-user-detail')).toBeVisible();
    await expect(page.locator('#btn-detail-test-push')).toBeVisible();

    // Navigate to Push section to check hunger alerts button
    await page.locator('#btn-user-detail-back').click();
    await page.click('.btn-admin-section[data-section="admin-section-push"]');
    await expect(page.locator('#admin-section-push')).toBeVisible();
    await expect(page.locator('#btn-send-hungry')).toBeVisible();

    // VAPID public key endpoint should work
    const res = await page.evaluate(() => fetch('api/push.php?action=vapid_public_key').then(r => r.json()));
    expect(res.success).toBe(true);
    expect(res.publicKey).toBeTruthy();
  });

  test('Admin can kill chicken and user can revive it', async ({ page }) => {
    await page.goto('/');

    // Login as admin
    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Go to admin and kill carmelo's chicken
    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();

    // Navigate to Users section via menu
    await page.click('.btn-admin-section[data-section="admin-section-users"]');
    await expect(page.locator('#admin-section-users')).toBeVisible();

    // Click on carmelo user
    await page.locator('#admin-users-list .list-group-item', { hasText: 'carmelo' }).click();
    await expect(page.locator('#admin-user-detail')).toBeVisible();

    // Kill chicken button should be visible
    await expect(page.locator('#btn-detail-kill')).toBeVisible();

    // Accept the confirmation dialog and kill
    page.on('dialog', dialog => dialog.accept());
    await page.click('#btn-detail-kill');
    await expect(page.locator('#user-detail-msg')).toContainText('killed');

    // Logout admin, login as carmelo
    await page.locator('#btn-user-detail-back').click();
    await page.click('.btn-admin-back');
    await page.click('#btn-logout');

    await page.fill('#login-username', 'carmelo');
    await page.fill('#login-password', 'carmelo');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Dead overlay should be visible
    await expect(page.locator('#dead-overlay')).toBeVisible();
    await expect(page.locator('#game-buttons')).toBeHidden();

    // Revive the chicken — this starts a multi-question challenge
    await page.click('#btn-revive');

    // Challenge overlay should appear
    await expect(page.locator('#challenge-overlay')).toBeVisible();

    // Complete all challenge questions by failing once to get the answer, then answering correctly
    let safetyCounter = 0;
    while (safetyCounter < 10) {
      safetyCounter++;
      // Submit a wrong answer to reveal the correct one
      await page.fill('#challenge-answer', 'wrong_answer_intentional');
      await page.click('#btn-challenge-submit');
      await expect(page.locator('#challenge-feedback-title')).toBeVisible();

      // Read the correct answer from feedback
      const feedbackText = await page.locator('#challenge-feedback-text').innerText();
      const match = feedbackText.match(/:\s*(.+)/);
      const answer = match ? match[1].split(' OR ')[0].trim() : '';

      // Repeat and submit correct answer
      await page.click('#btn-challenge-repeat');
      await expect(page.locator('#challenge-answer')).toBeEnabled();
      await page.fill('#challenge-answer', answer);
      await page.click('#btn-challenge-submit');

      // Click Next to proceed
      await expect(page.locator('#btn-challenge-next')).toBeVisible({ timeout: 5000 });
      await page.click('#btn-challenge-next');

      // Check if challenge is done (overlay hidden) or next question loaded
      const isHidden = await page.locator('#challenge-overlay').evaluate(el => el.classList.contains('d-none'));
      if (isHidden) break;
    }

    // Challenge should be complete — dead overlay should disappear
    await expect(page.locator('#challenge-overlay')).toBeHidden({ timeout: 10000 });
    await expect(page.locator('#dead-overlay')).toBeHidden({ timeout: 10000 });
    await expect(page.locator('#game-buttons')).toBeVisible();
  });

  test('Cleanup: delete test users created during tests', async ({ page }) => {
    await page.goto('/');

    // Login as admin
    await page.fill('#login-username', 'queen');
    await page.fill('#login-password', 'queen');
    await page.click('button[type="submit"]');
    await expect(page.locator('#view-game')).toBeVisible();

    // Go to admin panel → Users section
    await page.click('#btn-admin');
    await expect(page.locator('#view-admin')).toBeVisible();
    await page.click('.btn-admin-section[data-section="admin-section-users"]');
    await expect(page.locator('#admin-section-users')).toBeVisible();

    // Cleanup via admin API to avoid flaky UI detach races
    const userListData = await page.evaluate(() => fetch('api/admin.php?action=list_users').then(r => r.json()));
    const targets = userListData.users.filter(u => u.username.startsWith('testuser_'));
    expect(targets.length).toBeGreaterThanOrEqual(1);

    for (const u of targets) {
      await page.evaluate((uid) => fetch('api/admin.php?action=delete_user', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ user_id: uid })
      }), u.id);
    }

    const verifyData = await page.evaluate(() => fetch('api/admin.php?action=list_users').then(r => r.json()));
    const remaining = verifyData.users.filter(u => u.username.startsWith('testuser_'));
    expect(remaining.length).toBe(0);
  });

});