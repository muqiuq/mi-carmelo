// Vanilla JavaScript Application

let currentUser = null;
let currentLang = 'de'; // derived from user's question_set filename, e.g. questions_es.yaml → 'es'

function langFromQuestionSet(questionSet) {
    if (!questionSet) return 'de';
    const base = questionSet.replace(/\.yaml$/, '');
    const m = base.match(/^questions_([a-z]{2,5})$/i);
    return m ? m[1].toLowerCase() : 'de';
}

document.addEventListener('DOMContentLoaded', () => {
    console.log('Mi Carmelo initialized');
    
    // View Selectors
    const viewLogin = document.getElementById('view-login');
    const viewGame = document.getElementById('view-game');
    const viewSettings = document.getElementById('view-settings');
    const viewAdmin = document.getElementById('view-admin');
    const viewShop = document.getElementById('view-shop');
    const allViews = [viewLogin, viewGame, viewSettings, viewAdmin, viewShop];

    // Auth Selectors
    const formLogin = document.getElementById('form-login');
    const loginError = document.getElementById('login-error');
    const btnLogout = document.getElementById('btn-logout');

    // User/Settings Selectors
    const btnSettings = document.getElementById('btn-settings');
    const btnSettingsBack = document.getElementById('btn-settings-back');
    const btnShop = document.getElementById('btn-shop');
    const btnShopBack = document.getElementById('btn-shop-back');
    const btnAdmin = document.getElementById('btn-admin');
    const btnSleepToggle = document.getElementById('btn-sleep-toggle');
    const formSettings = document.getElementById('form-settings');
    const formPassword = document.getElementById('form-password');
    const settingsMsg = document.getElementById('settings-msg');

    // Game Selectors
    const btnPet = document.getElementById('btn-pet');
    const btnFeed = document.getElementById('btn-feed');
    const chickenSprite = document.getElementById('chicken-sprite');
    const speechBubble = document.getElementById('speech-bubble');
    
    // Challenge Selectors
    const challengeOverlay = document.getElementById('challenge-overlay');
    const challengeProgress = document.getElementById('challenge-progress');
    const challengeQuestion = document.getElementById('challenge-question');
    const formChallenge = document.getElementById('form-challenge');
    const inputChallengeAnswer = document.getElementById('challenge-answer');
    const challengeFeedback = document.getElementById('challenge-feedback');
    const challengeFeedbackTitle = document.getElementById('challenge-feedback-title');
    const challengeFeedbackText = document.getElementById('challenge-feedback-text');
    const btnChallengeNext = document.getElementById('btn-challenge-next');
    const btnChallengeRepeat = document.getElementById('btn-challenge-repeat');
    const btnChallengeSubmit = document.getElementById('btn-challenge-submit');
    const btnChallengeClose = document.getElementById('btn-challenge-close');
    const btnTtsPlay = document.getElementById('btn-tts-play');
    const feedCountdown = document.getElementById('feed-countdown');

    // Countdown timer
    let countdownInterval = null;

    // Challenge State
    let currentChallenge = [];
    let currentQuestionIndex = 0;
    let currentQuestionAttempts = 0;
    let challengeType = 'pet'; // or 'feed'
    let challengeResults = []; // stores objects { question_id, attempts }

    // Sleep state
    let isSleeping = false;
    let lastStats = null;
    let isFirstRender = true;
    let sleepOverride = localStorage.getItem('sleep_override') || 'auto'; // 'auto' | 'sleep' | 'awake'

    function shouldSleep() {
        if (sleepOverride === 'sleep') return true;
        if (sleepOverride === 'awake') return false;
        const h = new Date().getHours();
        return h >= 1 && h < 8;
    }

    function updateSleepToggleLabel() {
        if (!btnSleepToggle) return;
        const labels = {
            auto:  '😴 Auto',
            sleep: '😴 Schläft',
            awake: '☀️ Wach'
        };
        btnSleepToggle.textContent = labels[sleepOverride] || labels.auto;
    }

    function showSleepZ(show) {
        const petArea = document.getElementById('pet-area');
        if (!petArea) return;
        petArea.classList.toggle('is-sleeping', !!show);
        let z = petArea.querySelector('.sleep-zzz');
        if (show) {
            if (!z) {
                z = document.createElement('div');
                z.className = 'sleep-zzz';
                z.innerHTML = '<span class="zchar">z</span><span class="zchar">Z</span><span class="zchar">z</span>';
                petArea.appendChild(z);
            }
        } else if (z) {
            z.remove();
        }
    }

    // Smoothly walk Carmelo from his current position to the bed (right side),
    // then trigger the lie-down animation. Avoids the snap-to-50% jump.
    function walkToBedThenSleep() {
        const petArea = document.getElementById('pet-area');
        if (!petArea) return;
        const areaRect = petArea.getBoundingClientRect();
        const spriteRect = chickenSprite.getBoundingClientRect();
        const currentCenterPx = (spriteRect.left + spriteRect.width / 2) - areaRect.left;
        const currentLeftPct = (currentCenterPx / areaRect.width) * 100;

        // Strip any animation, fix the current position inline
        chickenSprite.classList.remove(...ALL_ANIM_CLASSES);
        chickenSprite.style.left = currentLeftPct + '%';
        chickenSprite.style.bottom = '10px';
        chickenSprite.style.transform = 'translateX(-50%) scaleX(1)';
        // Force reflow so transition starts from current values
        void chickenSprite.offsetWidth;
        // Distance-based duration so speed feels constant
        const distance = Math.abs(85 - currentLeftPct);
        const walkMs = Math.max(700, Math.round(distance * 40));
        chickenSprite.style.transition = `left ${walkMs}ms ease-in-out`;
        chickenSprite.style.left = '85%';
        chickenSprite.classList.add('walk-active');

        setTimeout(() => {
            chickenSprite.classList.remove('walk-active');
            chickenSprite.style.transition = '';
            chickenSprite.style.left = '';
            chickenSprite.style.bottom = '';
            chickenSprite.style.transform = '';
            setAnimation('anim-sleep');
            setTimeout(() => showSleepZ(true), 2000);
        }, walkMs + 50);
    }

    // Wake up at the bed, then smoothly walk back to the idle position.
    function wakeAndWalkBack() {
        setAnimation('anim-wake');
        // anim-wake = 1.4s, ends at left:85% bottom:10px facing left (scaleX(-1))
        setTimeout(() => {
            chickenSprite.classList.remove(...ALL_ANIM_CLASSES);
            chickenSprite.style.left = '85%';
            chickenSprite.style.bottom = '10px';
            chickenSprite.style.transform = 'translateX(-50%) scaleX(-1)';
            void chickenSprite.offsetWidth;
            const walkMs = 1800;
            chickenSprite.style.transition = `left ${walkMs}ms ease-in-out`;
            chickenSprite.style.left = '40%';
            chickenSprite.classList.add('walk-active');
            setTimeout(() => {
                chickenSprite.classList.remove('walk-active');
                // Turn around to face right before idle starts
                chickenSprite.style.transition = 'transform 200ms ease-in-out';
                chickenSprite.style.transform = 'translateX(-50%) scaleX(1)';
                setTimeout(() => {
                    // Apply anim-idle FIRST, then clear inline styles in the
                    // same frame. Otherwise the sprite reverts to CSS default
                    // (left:50%) for one frame before walk-x-narrow (left:40%)
                    // takes over, causing a visible double-jump.
                    chickenSprite.classList.add('anim-idle');
                    chickenSprite.style.transition = '';
                    chickenSprite.style.left = '';
                    chickenSprite.style.bottom = '';
                    chickenSprite.style.transform = '';
                    fetchStats();
                }, 220);
            }, walkMs + 30);
        }, 2200);
    }

    // Animation init
    chickenSprite.classList.add('anim-idle');

    // --- VIEW ROUTING ---
    function showView(viewToShow) {
        allViews.forEach(v => v.classList.add('d-none'));
        viewToShow.classList.remove('d-none');
    }

    // --- AUTHENTICATION ---
    async function checkAuth() {
        const res = await fetch('api/auth.php?action=check');
        const data = await res.json();
        if (data.authenticated) {
            currentUser = data.user;
            initGameView();
        } else {
            showView(viewLogin);
        }
    }

    formLogin.addEventListener('submit', async (e) => {
        e.preventDefault();
        loginError.classList.add('d-none');
        const username = document.getElementById('login-username').value;
        const password = document.getElementById('login-password').value;

        const res = await fetch('api/auth.php?action=login', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({username, password})
        });
        const data = await res.json();

        if (data.success) {
            currentUser = data.user;
            initGameView();
        } else {
            loginError.textContent = data.error || 'Login failed';
            loginError.classList.remove('d-none');
        }
    });

    btnLogout.addEventListener('click', async () => {
        await fetch('api/auth.php?action=logout', { method: 'POST' });
        currentUser = null;
        if (sessionCheckInterval) { clearInterval(sessionCheckInterval); sessionCheckInterval = null; }
        formLogin.reset();
        showView(viewLogin);
    });

    btnSleepToggle.addEventListener('click', () => {
        const cycle = { auto: 'sleep', sleep: 'awake', awake: 'auto' };
        sleepOverride = cycle[sleepOverride] || 'auto';
        localStorage.setItem('sleep_override', sleepOverride);
        updateSleepToggleLabel();
        if (lastStats) updateStatsUI(lastStats);
    });

    // Re-evaluate sleep state every minute (handles auto-mode time crossings)
    setInterval(() => {
        if (lastStats && !lastStats.is_dead) updateStatsUI(lastStats);
    }, 60000);

    // --- INITIALIZE GAME VIEW ---
    let sessionCheckInterval = null;

    async function initGameView() {
        document.getElementById('user-display-name').textContent = `(${currentUser.username})`;
        btnAdmin.classList.toggle('d-none', Number(currentUser.isadmin) !== 1);
        btnSleepToggle.classList.toggle('d-none', Number(currentUser.isadmin) !== 1);
        updateSleepToggleLabel();

        // Load question_set to derive TTS language
        try {
            const sRes = await fetch('api/user.php?action=get_settings');
            const sData = await sRes.json();
            if (sData.success) {
                currentLang = langFromQuestionSet(sData.settings.question_set);
            }
        } catch (_) {}

        await fetchStats();
        showView(viewGame);
        initPush();

        // Poll session validity every 60 seconds
        if (sessionCheckInterval) clearInterval(sessionCheckInterval);
        sessionCheckInterval = setInterval(async () => {
            try {
                const res = await fetch('api/auth.php?action=check');
                const data = await res.json();
                if (!data.authenticated) {
                    clearInterval(sessionCheckInterval);
                    sessionCheckInterval = null;
                    currentUser = null;
                    showView(viewLogin);
                }
            } catch (e) {
                // Network error — skip this check
            }
        }, 60000);
    }
    
    async function fetchStats() {
        const res = await fetch('api/user.php?action=get_stats');
        const data = await res.json();
        if(data.success) {
            updateStatsUI(data.stats);
        }
    }
    
    function updateStatsUI(incoming, skipSleepTransitions = false) {
        // Merge with last known state so partial API responses never wipe existing fields
        const stats = { ...lastStats, ...incoming };
        lastStats = stats;

        document.getElementById('diamond-count').textContent = stats.diamonds || 0;
        document.getElementById('star-count').textContent = stats.stars || 0;
        document.getElementById('point-count').textContent = stats.total_points || 0;
        if (Array.isArray(stats.flower_slots)) {
            renderFlowers(stats.flower_slots);
        }
        if (Array.isArray(stats.lamp_slots)) {
            renderLamps(stats.lamp_slots);
        }
        if (Array.isArray(stats.frame_slots)) {
            renderFrames(stats.frame_slots);
        }
        renderBed(stats.bed_owned);
        // Apply body color
        const petArea = document.getElementById('pet-area');
        const colorClasses = ['pet-color-pink','pet-color-blue','pet-color-green','pet-color-purple','pet-color-white'];
        colorClasses.forEach(c => petArea && petArea.classList.remove(c));
        if (stats.pet_color) {
            const cls = stats.pet_color.replace('color_', 'pet-color-');
            petArea && petArea.classList.add(cls);
        }
        lastStats = stats;
        if (stats.fiesta_cooldown > 0) {
            startFiestaCooldown(stats.fiesta_cooldown);
        }
        fiestaDisabled = stats.fiesta_mode || 'normal';

        const deadOverlay = document.getElementById('dead-overlay');
        const gameButtons = document.getElementById('game-buttons');

        if (stats.is_dead) {
            // Chicken is dead
            speechBubble.classList.add('d-none');
            setAnimation('anim-dead');
            stopCountdown();
            gameButtons.classList.add('d-none');
            deadOverlay.classList.remove('d-none');
            return;
        }

        // Alive — ensure dead overlay hidden, buttons visible
        deadOverlay.classList.add('d-none');
        gameButtons.classList.remove('d-none');

        // Sleep takes priority over hungry/idle when bed owned and night-time
        const wantSleep = !!stats.bed_owned && shouldSleep();
        if (wantSleep) {
            speechBubble.classList.add('d-none');
            if (!isSleeping) {
                // First time entering sleep state in this session
                if (isFirstRender) {
                    setAnimation('anim-asleep');
                    showSleepZ(true);
                } else {
                    walkToBedThenSleep();
                }
                isSleeping = true;
            }
            btnFeed.disabled = true;
            btnFeed.textContent = '😴 Schläft';
            btnFeed.classList.remove('btn-warning');
            btnFeed.classList.add('btn-secondary');
            stopCountdown();
            checkFiestaTime();
            isFirstRender = false;
            return;
        }
        if (isSleeping) {
            if (skipSleepTransitions) return;
            // Just woke up — play wake animation, then walk back to idle.
            // wakeAndWalkBack() calls fetchStats() itself once the walk completes.
            isSleeping = false;
            showSleepZ(false);
            wakeAndWalkBack();
            speechBubble.classList.add('d-none');
            stopCountdown();
            isFirstRender = false;
            return;
        }

        // Handle "hungry" bubble + animation
        if (stats.is_hungry) {
            speechBubble.classList.remove('d-none');
            setAnimation('anim-hungry');
            // Enable feed button
            btnFeed.disabled = false;
            btnFeed.textContent = 'Alimentar';
            btnFeed.classList.remove('btn-secondary');
            btnFeed.classList.add('btn-warning');
            stopCountdown();
        } else {
            speechBubble.classList.add('d-none');
            setAnimation('anim-idle');
            // Disable feed button
            btnFeed.disabled = true;
            btnFeed.textContent = '🚫 Alimentar';
            btnFeed.classList.remove('btn-warning');
            btnFeed.classList.add('btn-secondary');
            // Start countdown if we have next_feed_ts
            if (stats.next_feed_ts) {
                startCountdown(stats.next_feed_ts);
            }
        }

        checkFiestaTime();
        isFirstRender = false;
    }

    // Revive handler — must complete a challenge first
    document.getElementById('btn-revive').addEventListener('click', () => {
        startChallenge('revive');
    });

    function startCountdown(nextFeedTs) {
        stopCountdown();
        feedCountdown.classList.remove('d-none');
        const tick = () => {
            const remaining = nextFeedTs - Math.floor(Date.now() / 1000);
            if (remaining <= 0) {
                stopCountdown();
                fetchStats(); // refresh — chicken should now be hungry
                return;
            }
            const h = Math.floor(remaining / 3600);
            const m = Math.floor((remaining % 3600) / 60);
            const s = remaining % 60;
            feedCountdown.textContent = `Next feed in ${h}h ${String(m).padStart(2,'0')}m ${String(s).padStart(2,'0')}s`;
        };
        tick();
        countdownInterval = setInterval(tick, 1000);
    }

    function stopCountdown() {
        if (countdownInterval) { clearInterval(countdownInterval); countdownInterval = null; }
        feedCountdown.classList.add('d-none');
        feedCountdown.textContent = '';
    }

    // --- SETTINGS ---
    btnSettings.addEventListener('click', async () => {
        settingsMsg.textContent = '';
        const res = await fetch('api/user.php?action=get_settings');
        const data = await res.json();
        if (data.success) {
            document.getElementById('setting-questions').value = data.settings.questions_per_challenge;
            showView(viewSettings);
        }
    });

    btnSettingsBack.addEventListener('click', () => showView(viewGame));

    // --- SHOP ---
    btnShop.addEventListener('click', async () => {
        await loadShop();
        showView(viewShop);
    });

    btnShopBack.addEventListener('click', () => {
        showView(viewGame);
        fetchStats();
    });

    function currencyLabel(currency) {
        if (currency === 'points') return 'Points';
        if (currency === 'diamonds') return 'Diamonds';
        if (currency === 'stars') return 'Stars';
        return currency;
    }

    function currencyIcon(currency) {
        if (currency === 'points') return '🏆';
        if (currency === 'diamonds') return '💎';
        if (currency === 'stars') return '⭐';
        return '🪙';
    }

    function renderFlowers(slots) {
        const decorEl = document.getElementById('pet-decor');
        if (!decorEl) return;
        decorEl.querySelectorAll('.wall-flower').forEach(el => el.remove());

        const flowerSpots = [
            { left: '8%', top: '20%' },
            { left: '20%', top: '12%' },
            { left: '32%', top: '18%' },
            { left: '45%', top: '10%' },
            { left: '58%', top: '17%' },
            { left: '70%', top: '12%' },
            { left: '82%', top: '20%' },
            { left: '14%', top: '34%' },
            { left: '50%', top: '30%' },
            { left: '86%', top: '34%' }
        ];

        const flowerColors = ['', 'flower-red', 'flower-blue', 'flower-purple', 'flower-orange', 'flower-white'];

        slots.forEach(slot => {
            const idx = parseInt(slot, 10);
            if (Number.isNaN(idx) || idx < 0 || idx >= flowerSpots.length) return;
            const flower = document.createElement('div');
            const colorClass = flowerColors[idx % flowerColors.length];
            flower.className = 'wall-flower' + (colorClass ? ' ' + colorClass : '');
            flower.style.left = flowerSpots[idx].left;
            flower.style.top = flowerSpots[idx].top;
            decorEl.appendChild(flower);
        });
    }

    function renderLamps(slots) {
        const decorEl = document.getElementById('pet-decor');
        if (!decorEl) return;

        // Remove existing lamps (keep flowers)
        decorEl.querySelectorAll('.wall-lamp, .floor-lamp').forEach(el => el.remove());

        // Slot 0 = wall-left, Slot 1 = wall-right, Slot 2 = floor lamp
        const lampSpots = [
            { cls: 'wall-lamp', left: '3%', top: '28%' },
            { cls: 'wall-lamp', left: '90%', top: '28%' },
            { cls: 'floor-lamp', left: '8%', bottom: '8%' }
        ];

        slots.forEach(slot => {
            const idx = parseInt(slot, 10);
            if (Number.isNaN(idx) || idx < 0 || idx >= lampSpots.length) return;
            const lamp = document.createElement('div');
            lamp.className = lampSpots[idx].cls;
            lamp.style.left = lampSpots[idx].left;
            if (lampSpots[idx].top) lamp.style.top = lampSpots[idx].top;
            if (lampSpots[idx].bottom) lamp.style.bottom = lampSpots[idx].bottom;
            decorEl.appendChild(lamp);
        });
    }

    function renderFrames(slots) {
        const decorEl = document.getElementById('pet-decor');
        if (!decorEl) return;

        decorEl.querySelectorAll('.picture-frame').forEach(el => el.remove());

        // Slot 0 = left turtle, slot 1 = right cat. Below the wall lamps.
        const frameSpots = [
            { left: '18%', top: '42%', variant: 'frame-turtle', emoji: '\u{1F422}' },
            { left: '78%', top: '42%', variant: 'frame-bunny',  emoji: '\u{1F430}' }
        ];

        slots.forEach(slot => {
            const idx = parseInt(slot, 10);
            if (Number.isNaN(idx) || idx < 0 || idx >= frameSpots.length) return;
            const spot = frameSpots[idx];
            const frame = document.createElement('div');
            frame.className = 'picture-frame ' + spot.variant;
            frame.style.left = spot.left;
            frame.style.top = spot.top;
            const inner = document.createElement('div');
            inner.className = 'frame-inner';
            inner.textContent = spot.emoji;
            frame.appendChild(inner);
            decorEl.appendChild(frame);
        });
    }

    function renderBed(owned) {
        const decorEl = document.getElementById('pet-decor');
        const petArea = document.getElementById('pet-area');
        if (!decorEl || !petArea) return;

        decorEl.querySelectorAll('.bed').forEach(el => el.remove());
        petArea.classList.toggle('has-bed', !!owned);

        if (!owned) return;

        const bed = document.createElement('div');
        bed.className = 'bed';
        bed.innerHTML = `
            <div class="bed-frame"></div>
            <div class="bed-mattress"></div>
            <div class="bed-blanket"></div>
            <div class="bed-pillow"></div>
            <div class="bed-headboard"></div>
            <div class="bed-leg bed-leg-l"></div>
            <div class="bed-leg bed-leg-r"></div>
        `;
        decorEl.appendChild(bed);
    }

    async function loadShop() {
        const listEl = document.getElementById('shop-list');
        const balanceEl = document.getElementById('shop-balance');
        listEl.innerHTML = '<div class="text-muted">Laden...</div>';
        balanceEl.textContent = '';

        try {
            const res = await fetch('api/shop.php?action=list');
            const data = await res.json();
            if (!data.success) {
                listEl.innerHTML = '<div class="text-danger">Shop konnte nicht geladen werden.</div>';
                return;
            }

            balanceEl.innerHTML = `💎 ${data.balances.diamonds} &nbsp; ⭐ ${data.balances.stars} &nbsp; 🏆 ${data.balances.points}`;

            if (!data.items || data.items.length === 0) {
                listEl.innerHTML = '<div class="text-muted">Momentan keine Artikel verfügbar.</div>';
                return;
            }

            listEl.innerHTML = '';
            data.items.forEach(item => {
                const card = document.createElement('div');
                card.className = 'col-12 col-md-6';
                const lockedClass = item.can_afford ? '' : ' shop-item-locked';
                const isImplemented = item.code === 'flower_wall' || item.code === 'small_lamp' || item.code === 'picture_frame' || item.code === 'chicken_house' || item.code === 'diamond_buy' || item.code === 'diamond_buy_3' || item.code.startsWith('color_');
                const buyLabel = item.remaining <= 0 ? 'Ausverkauft' : (isImplemented ? 'Kaufen' : 'Bald verfügbar');

                card.innerHTML = `
                    <div class="card h-100 text-start${lockedClass}">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title mb-1">${escapeHtml(item.name)}</h5>
                            <p class="small text-muted mb-2">${escapeHtml(item.description)}</p>
                            <div class="small mb-1">Preis: <strong>${currencyIcon(item.currency)} ${item.price} ${currencyLabel(item.currency)}</strong></div>
                            <button class="btn btn-outline-primary btn-sm mt-auto btn-shop-buy" data-id="${item.id}" data-can-afford="${item.can_afford ? '1' : '0'}" ${item.remaining <= 0 ? 'disabled' : ''}>
                                ${buyLabel}
                            </button>
                        </div>
                    </div>
                `;

                listEl.appendChild(card);
            });

            listEl.querySelectorAll('.btn-shop-buy').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const itemId = parseInt(btn.dataset.id, 10);
                    const res = await fetch('api/shop.php?action=buy', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ item_id: itemId })
                    });
                    const buyData = await res.json();
                    if (!buyData.success) {
                        alert(buyData.error || 'Kauf konnte nicht abgeschlossen werden.');
                        return;
                    }
                    await fetchStats();
                    showView(viewGame);
                    if (buyData.earned_star) {
                        triggerAnimation('anim-star', 3125);
                    } else if (buyData.pet_color) {
                        spawnHearts();
                    } else {
                        spawnHearts();
                    }
                });
            });
        } catch (e) {
            listEl.innerHTML = '<div class="text-danger">Fehler beim Laden des Shops.</div>';
        }
    }

    // --- MINI SLOT MACHINE ---
    const viewSlot         = document.getElementById('view-slot');
    const btnSlot          = document.getElementById('btn-slot');
    const btnSlotBack      = document.getElementById('btn-slot-back');
    const btnSlotSpin      = document.getElementById('btn-slot-spin');
    const slotBoardEl      = document.getElementById('slot-board');
    const slotResultEl     = document.getElementById('slot-result');
    const slotFruitPicker  = document.getElementById('slot-fruit-picker');
    const slotFactorPicker = document.getElementById('slot-factor-picker');
    const slotCostEl       = document.getElementById('slot-cost');
    const slotBalanceEl    = document.getElementById('slot-balance');
    if (viewSlot) allViews.push(viewSlot);

    let slotConfig    = null;            // { fruits, base_cost, allowed_factor, payout_mult }
    let slotSelected  = [];              // array of fruit emojis
    let slotFactor    = 1;
    let slotPerimeter = [];              // ordered list of cell indices around the perimeter
    let slotSpinning  = false;
    let slotFreeSpins = 0;               // free spins available
    let slotStageEl   = null;            // animated centerpiece element

    // Build a 6×6 grid; only the perimeter cells (20 of them) hold fruits.
    function buildSlotBoard() {
        slotBoardEl.innerHTML = '';
        slotPerimeter = [];
        const fruits = slotConfig.fruits;
        const cells  = [];
        for (let r = 0; r < 6; r++) {
            for (let c = 0; c < 6; c++) {
                const cell = document.createElement('div');
                cell.className = 'slot-cell';
                const onPerimeter = (r === 0 || r === 5 || c === 0 || c === 5);
                if (!onPerimeter) {
                    cell.classList.add('slot-empty');
                } else {
                    // Spread fruits evenly around the ring.
                    // Order: top row L→R, right col T→B (skipping corner already used),
                    // bottom row R→L, left col B→T.
                    cell.dataset.row = r;
                    cell.dataset.col = c;
                }
                slotBoardEl.appendChild(cell);
                cells.push({ el: cell, r, c });
            }
        }
        // Walk perimeter clockwise starting top-left.
        const ring = [];
        for (let c = 0; c < 6; c++)            ring.push(cells[0 * 6 + c]);          // top row
        for (let r = 1; r < 6; r++)            ring.push(cells[r * 6 + 5]);          // right col
        for (let c = 4; c >= 0; c--)           ring.push(cells[5 * 6 + c]);          // bottom row
        for (let r = 4; r >= 1; r--)           ring.push(cells[r * 6 + 0]);          // left col
        ring.forEach((entry, i) => {
            const fruit = fruits[i % fruits.length];
            entry.el.textContent = fruit;
            entry.el.dataset.fruit = fruit;
            slotPerimeter.push(entry.el);
        });
        // Replace ONE random perimeter cell with the radioactive tile.
        if (slotConfig.nuke) {
            const nukeIdx = Math.floor(Math.random() * slotPerimeter.length);
            slotPerimeter[nukeIdx].textContent = slotConfig.nuke;
            slotPerimeter[nukeIdx].dataset.fruit = slotConfig.nuke;
            slotPerimeter[nukeIdx].classList.add('slot-nuke');
        }
        // Build the animated centerpiece (mascot + 3 reels).
        const stage = document.createElement('div');
        stage.className = 'slot-stage';
        stage.innerHTML = `
            <div class="slot-stage-glow"></div>
            <div class="slot-stage-mascot">
                <div class="slot-mascot-chicken">
                    <div class="chicken-body">
                        <div class="chicken-head">
                            <div class="chicken-eye"></div>
                            <div class="chicken-beak">
                                <div class="chicken-beak-top"></div>
                                <div class="chicken-beak-bottom"></div>
                            </div>
                            <div class="chicken-comb"></div>
                        </div>
                        <div class="chicken-wing"></div>
                        <div class="chicken-tail"></div>
                    </div>
                    <div class="chicken-legs">
                        <div class="chicken-leg chicken-leg-left"></div>
                        <div class="chicken-leg chicken-leg-right"></div>
                    </div>
                </div>
            </div>
            <div class="slot-stage-win"></div>
            <div class="slot-stage-reels">
                <div class="slot-reel"><div class="slot-reel-strip"></div></div>
                <div class="slot-reel"><div class="slot-reel-strip"></div></div>
                <div class="slot-reel"><div class="slot-reel-strip"></div></div>
            </div>
            <div class="slot-stage-lights">
                <span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span>
            </div>
        `;
        slotBoardEl.appendChild(stage);
        // Fill each reel strip with the fruit emojis (repeated for seamless scroll).
        const reelFruits = slotConfig.selectable_fruits || slotConfig.fruits;
        stage.querySelectorAll('.slot-reel-strip').forEach((strip, i) => {
            const seq = [];
            for (let n = 0; n < 6; n++) seq.push(reelFruits[(n + i) % reelFruits.length]);
            // Duplicate for infinite loop
            strip.innerHTML = (seq.concat(seq)).map(f => `<span>${f}</span>`).join('');
        });
        slotStageEl = stage;
    }

    function setStageSpinning(on) {
        if (slotStageEl) slotStageEl.classList.toggle('spinning', !!on);
    }

    function showStageWin(fruit) {
        if (!slotStageEl) return;
        const winEl = slotStageEl.querySelector('.slot-stage-win');
        if (!winEl) return;
        winEl.innerHTML = `<span>${fruit}</span><span>${fruit}</span><span>${fruit}</span>`;
        winEl.classList.add('show');
    }

    function clearStageWin() {
        if (!slotStageEl) return;
        const winEl = slotStageEl.querySelector('.slot-stage-win');
        if (!winEl) return;
        winEl.classList.remove('show');
        winEl.innerHTML = '';
    }

    function buildSlotPickers() {
        slotFruitPicker.innerHTML = '';
        const pickerFruits = slotConfig.selectable_fruits || slotConfig.fruits;
        pickerFruits.forEach(f => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'slot-fruit-btn';
            btn.textContent = f;
            btn.dataset.fruit = f;
            btn.addEventListener('click', () => toggleSlotFruit(f));
            slotFruitPicker.appendChild(btn);
        });
        slotFactorPicker.innerHTML = '';
        slotConfig.allowed_factor.forEach(fx => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-warning' + (fx === slotFactor ? ' active' : '');
            btn.textContent = fx + 'x';
            btn.dataset.factor = fx;
            btn.addEventListener('click', () => {
                slotFactor = fx;
                slotFactorPicker.querySelectorAll('.btn').forEach(b =>
                    b.classList.toggle('active', Number(b.dataset.factor) === slotFactor));
                updateSlotCost();
            });
            slotFactorPicker.appendChild(btn);
        });
    }

    function toggleSlotFruit(fruit) {
        if (slotSpinning) return;
        const idx = slotSelected.indexOf(fruit);
        if (idx >= 0) {
            slotSelected.splice(idx, 1);
        } else {
            if (slotSelected.length >= 2) return; // max 2
            slotSelected.push(fruit);
        }
        slotFruitPicker.querySelectorAll('.slot-fruit-btn').forEach(b => {
            b.classList.toggle('selected', slotSelected.includes(b.dataset.fruit));
        });
        updateSlotCost();
    }

    function updateSlotCost() {
        const cost = slotConfig.base_cost * slotFactor * slotSelected.length;
        const useFree = slotFreeSpins > 0;
        if (slotSelected.length === 0) {
            slotCostEl.textContent = 'Wähle 1 oder 2 Früchte';
            btnSlotSpin.disabled = true;
            btnSlotSpin.textContent = 'Spin!';
        } else if (useFree) {
            slotCostEl.innerHTML = `🤩 <b>Free Spin</b> verfügbar — dieser Spin ist gratis (Faktor & Früchte zählen für die Auszahlung)`;
            btnSlotSpin.disabled = false;
            btnSlotSpin.textContent = '🤩 Free Spin!';
        } else {
            slotCostEl.textContent = `Einsatz: ${cost} Punkte (${slotSelected.length} × ${slotConfig.base_cost * slotFactor})`;
            btnSlotSpin.disabled = false;
            btnSlotSpin.textContent = 'Spin!';
        }
    }

    function updateSlotBalanceLabel() {
        const fs = slotFreeSpins > 0 ? ` &nbsp;·&nbsp; 🤩 Free Spins: <b>${slotFreeSpins}</b>` : '';
        slotBalanceEl.innerHTML = `🏆 ${slotConfig.balance} Punkte &nbsp;·&nbsp; Gewinn: ${slotConfig.payout_mult}× Einsatz${fs}`;
    }

    async function openSlot() {
        slotResultEl.textContent = '';
        slotSelected = [];
        slotFactor = 1;
        try {
            const res = await fetch('api/slot.php?action=info');
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Fehler');
            slotConfig = data;
            slotConfig.balance = data.balance;
            slotFreeSpins = data.free_spins || 0;
            updateSlotBalanceLabel();
            buildSlotBoard();
            buildSlotPickers();
            updateSlotCost();
            showView(viewSlot);
        } catch (e) {
            alert('Slot konnte nicht geladen werden.');
        }
    }

    btnSlot.addEventListener('click', openSlot);
    btnSlotBack.addEventListener('click', () => { showView(viewGame); fetchStats(); });

    // Animate the rotating light: visit each perimeter cell, decelerating, total ~5s,
    // and end on the cell whose .dataset.fruit matches `landingFruit` (first match).
    function spinAnimation(landingFruit) {
        return new Promise(resolve => {
            const ringLen = slotPerimeter.length; // 20
            // find target index of any cell holding the landing fruit
            const candidates = [];
            slotPerimeter.forEach((el, i) => { if (el.dataset.fruit === landingFruit) candidates.push(i); });
            const targetIdx = candidates[Math.floor(Math.random() * candidates.length)];

            // Build a sequence of indices: spin ~3.5 full rotations then approach target
            const minSteps = ringLen * 3 + ((targetIdx + ringLen) % ringLen);
            const totalSteps = minSteps + ringLen; // a bit extra for nice deceleration
            const totalMs = 5000;

            // Distribute step delays: cubic ease-out so it slows toward the end.
            const delays = [];
            for (let s = 1; s <= totalSteps; s++) {
                const t = s / totalSteps;          // 0..1
                const eased = 1 - Math.pow(1 - t, 3);
                delays.push(eased * totalMs);
            }

            slotPerimeter.forEach(c => c.classList.remove('slot-active', 'slot-final-win', 'slot-final-lose'));

            let step = 0;
            function tick() {
                if (step > 0) slotPerimeter[(step - 1) % ringLen].classList.remove('slot-active');
                if (step >= totalSteps) {
                    const finalEl = slotPerimeter[targetIdx];
                    finalEl.classList.add('slot-active');
                    resolve(finalEl);
                    return;
                }
                slotPerimeter[step % ringLen].classList.add('slot-active');
                const nextDelay = delays[step] - (delays[step - 1] || 0);
                step++;
                setTimeout(tick, Math.max(20, nextDelay));
            }
            tick();
        });
    }

    function spawnSlotConfetti() {
        const wrap = document.createElement('div');
        wrap.className = 'slot-confetti';
        const symbols = ['⭐','✨','🎉','💫','🌟','🎊'];
        for (let i = 0; i < 60; i++) {
            const s = document.createElement('span');
            s.textContent = symbols[Math.floor(Math.random() * symbols.length)];
            s.style.left = Math.random() * 100 + 'vw';
            s.style.animationDelay = (Math.random() * 0.4) + 's';
            s.style.animationDuration = (1.8 + Math.random() * 1.2) + 's';
            wrap.appendChild(s);
        }
        document.body.appendChild(wrap);
        setTimeout(() => wrap.remove(), 3000);
    }

    function showCherryRefundOverlay() {
        const ov = document.createElement('div');
        ov.className = 'slot-cherry-overlay';
        ov.textContent = '🍒';
        slotBoardEl.appendChild(ov);
        setTimeout(() => ov.remove(), 1500);
    }

    function showFreeSpinOverlay() {
        const ov = document.createElement('div');
        ov.className = 'slot-cherry-overlay slot-freespin-overlay';
        ov.innerHTML = '<div class="freespin-emoji">🤩</div><div class="freespin-label">FREE SPIN!</div>';
        slotBoardEl.appendChild(ov);
        setTimeout(() => ov.remove(), 2000);
    }

    btnSlotSpin.addEventListener('click', async () => {
        if (slotSpinning) return;
        if (slotSelected.length < 1 || slotSelected.length > 2) return;
        slotSpinning = true;
        btnSlotSpin.disabled = true;
        slotResultEl.textContent = '';
        slotPerimeter.forEach(c => c.classList.remove('slot-active', 'slot-final-win', 'slot-final-lose'));
        clearStageWin();
        setStageSpinning(true);

        let data;
        try {
            const useFree = slotFreeSpins > 0;
            const res = await fetch('api/slot.php?action=play', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ fruits: slotSelected, factor: slotFactor, use_free_spin: useFree }),
            });
            data = await res.json();
        } catch (e) {
            slotResultEl.textContent = 'Netzwerkfehler';
            slotSpinning = false;
            setStageSpinning(false);
            btnSlotSpin.disabled = false;
            return;
        }
        if (!data.success) {
            slotResultEl.textContent = data.error || 'Fehler';
            slotSpinning = false;
            setStageSpinning(false);
            updateSlotCost();
            return;
        }

        // Run spins sequentially (one per selected fruit)
        for (let i = 0; i < data.spins.length; i++) {
            const sp = data.spins[i];
            const selLabel = Array.isArray(sp.selected) ? sp.selected.join(' / ') : sp.selected;
            slotResultEl.textContent = data.spins.length > 1
                ? `Spin ${i + 1}/${data.spins.length} für ${selLabel} …`
                : `Spin für ${selLabel} …`;
            const finalEl = await spinAnimation(sp.landing);
            setStageSpinning(false);
            finalEl.classList.remove('slot-active');
            const isNuke   = sp.outcome === 'nuke';
            const isCherry = sp.outcome === 'cherry';
            finalEl.classList.add(sp.win ? 'slot-final-win' : 'slot-final-lose');
            if (sp.win) {
                spawnSlotConfetti();
                showStageWin(sp.landing);
            } else if (isNuke) {
                slotResultEl.textContent = `☢️ Atomschlag! ${selLabel} verloren …`;
            } else if (isCherry) {
                slotResultEl.textContent = `🍒 Cherry Bonus! Einsatz zurück (${sp.payout} Punkte)`;
                showCherryRefundOverlay();
            }
            // Free-spin bonus is awarded only on losses; show it AFTER the lose feedback.
            if (sp.freespin_awarded) {
                await new Promise(r => setTimeout(r, 600));
                slotResultEl.textContent = `🤩 Bonus! Free Spin gewonnen!`;
                showFreeSpinOverlay();
                await new Promise(r => setTimeout(r, 1400));
            }
            await new Promise(r => setTimeout(r, isNuke || isCherry ? 1600 : 900));
            finalEl.classList.remove('slot-final-win', 'slot-final-lose');
        }

        const net = data.net;
        const freeNote = data.used_free_spin ? ' 🤩 (gratis)' : '';
        const anyCherry = Array.isArray(data.spins) && data.spins.some(s => s.outcome === 'cherry');
        slotResultEl.textContent = net > 0
            ? `🎉 Gewonnen!${freeNote} Auszahlung: ${data.total_payout} Punkte`
            : (anyCherry
                ? `🍒 Cherry Bonus! Einsatz zurück (${data.total_payout} Punkte)`
                : (data.used_free_spin
                    ? `Free Spin verbraucht. Try again!`
                    : `Try again!`));
        slotConfig.balance = data.balance;
        slotFreeSpins = data.free_spins || 0;
        updateSlotBalanceLabel();
        slotSpinning = false;
        setStageSpinning(false);
        updateSlotCost();
    });

    // --- ADMIN PANEL ---
    let adminUsers = [];
    let currentDetailUserId = null;

    btnAdmin.addEventListener('click', async () => {
        showView(viewAdmin);
        // Show menu, hide all sections
        document.getElementById('admin-menu').classList.remove('d-none');
        document.querySelectorAll('.admin-section').forEach(s => s.classList.add('d-none'));
        document.querySelectorAll('.btn-admin-section').forEach(b => b.classList.remove('active'));
    });

    // Admin section menu navigation
    document.querySelectorAll('.btn-admin-section').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const sectionId = btn.dataset.section;
            document.querySelectorAll('.admin-section').forEach(s => s.classList.add('d-none'));
            document.querySelectorAll('.btn-admin-section').forEach(b => b.classList.remove('active'));
            document.getElementById(sectionId).classList.remove('d-none');
            btn.classList.add('active');

            if (sectionId === 'admin-section-users') {
                await loadUsers();
                document.getElementById('admin-user-detail').classList.add('d-none');
            } else if (sectionId === 'admin-section-questions') {
                await loadQuestions();
            } else if (sectionId === 'admin-section-push') {
                loadPushDebug();
            } else if (sectionId === 'admin-section-tokens') {
                loadTokens();
            } else if (sectionId === 'admin-section-shop') {
                loadShopAdmin();
            } else if (sectionId === 'admin-section-fiesta') {
                initFiestaAdmin();
            } else if (sectionId === 'admin-section-ai') {
                initAiSection();
            } else if (sectionId === 'admin-section-audio') {
                loadAudioAdmin();
            }
        });
    });

    document.querySelectorAll('.btn-admin-back').forEach(btn => {
        btn.addEventListener('click', () => { showView(viewGame); fetchStats(); });
    });

    document.getElementById('btn-user-detail-back').addEventListener('click', () => {
        document.getElementById('admin-user-detail').classList.add('d-none');
    });

    // Edit user form submit
    document.getElementById('form-edit-user').addEventListener('submit', async (e) => {
        e.preventDefault();
        const uid = currentDetailUserId;
        const newName = document.getElementById('edit-user-username').value.trim();
        const newAdmin = document.getElementById('edit-user-isadmin').checked ? 1 : 0;
        const newPwd = document.getElementById('edit-user-password').value;
        const newPoints = parseInt(document.getElementById('edit-user-points').value, 10) || 0;
        const newDiamonds = parseInt(document.getElementById('edit-user-diamonds').value, 10) || 0;
        const newStars = parseInt(document.getElementById('edit-user-stars').value, 10) || 0;
        const newQuestionSet = document.getElementById('edit-user-question-set').value;
        const res = await fetch('api/admin.php?action=edit_user', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: uid, username: newName, isadmin: newAdmin, password: newPwd, total_points: newPoints, diamonds: newDiamonds, stars: newStars, question_set: newQuestionSet})
        });
        const d = await res.json();
        const msg = document.getElementById('user-detail-msg');
        if (d.success) {
            msg.textContent = 'User updated!';
            msg.className = 'small mb-3 text-success';
            await loadUsers();
            document.getElementById('user-detail-title').textContent = newName;
        } else {
            msg.textContent = d.error || 'Failed to update user';
            msg.className = 'small mb-3 text-danger';
        }
    });

    // Stat +/- buttons with diamond→star conversion
    function applyDiamondStarConversion() {
        const diamondsInput = document.getElementById('edit-user-diamonds');
        const starsInput = document.getElementById('edit-user-stars');
        let diamonds = parseInt(diamondsInput.value, 10) || 0;
        let stars = parseInt(starsInput.value, 10) || 0;
        if (diamonds >= 10) {
            const earned = Math.floor(diamonds / 10);
            stars += earned;
            diamonds -= earned * 10;
            diamondsInput.value = diamonds;
            starsInput.value = stars;
        }
    }

    document.querySelectorAll('.btn-stat-plus').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            const step = parseInt(btn.dataset.step, 10) || 1;
            input.value = (parseInt(input.value, 10) || 0) + step;
            applyDiamondStarConversion();
        });
    });

    document.querySelectorAll('.btn-stat-minus').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            const step = parseInt(btn.dataset.step, 10) || 1;
            const val = (parseInt(input.value, 10) || 0) - step;
            input.value = Math.max(0, val);
        });
    });

    // Detail action buttons
    document.getElementById('btn-detail-reset-feed').addEventListener('click', async () => {
        const btn = document.getElementById('btn-detail-reset-feed');
        btn.disabled = true;
        const res = await fetch('api/admin.php?action=reset_feed', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: currentDetailUserId})
        });
        const d = await res.json();
        const msg = document.getElementById('user-detail-msg');
        if (d.success) {
            msg.textContent = 'Feed timer reset!';
            msg.className = 'small mb-3 text-success';
        } else {
            msg.textContent = d.error || 'Failed';
            msg.className = 'small mb-3 text-danger';
        }
        btn.disabled = false;
    });

    document.getElementById('btn-detail-reset-fiesta').addEventListener('click', async () => {
        const btn = document.getElementById('btn-detail-reset-fiesta');
        btn.disabled = true;
        const res = await fetch('api/admin.php?action=reset_fiesta', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: currentDetailUserId})
        });
        const d = await res.json();
        const msg = document.getElementById('user-detail-msg');
        if (d.success) {
            msg.textContent = 'Fiesta cooldown reset!';
            msg.className = 'small mb-3 text-success';
            if (currentUser && currentDetailUserId == currentUser.id) {
                if (fiestaTimer) { clearInterval(fiestaTimer); fiestaTimer = null; }
                btnFiesta.disabled = false;
                fiestaCountdown.classList.add('d-none');
                checkFiestaTime();
            }
        } else {
            msg.textContent = d.error || 'Failed';
            msg.className = 'small mb-3 text-danger';
        }
        btn.disabled = false;
    });

    document.getElementById('btn-detail-test-push').addEventListener('click', async () => {
        const btn = document.getElementById('btn-detail-test-push');
        btn.disabled = true;
        const res = await fetch('api/push.php?action=test_notify', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: currentDetailUserId})
        });
        const d = await res.json();
        const msg = document.getElementById('user-detail-msg');
        if (d.success) {
            let html = `<strong>Sent: ${d.sent}, Failed: ${d.failed}</strong>`;
            if (d.details && d.details.length) {
                html += '<ul class="list-unstyled mt-1 mb-0">';
                d.details.forEach(det => {
                    const icon = det.status === 'ok' ? '✅' : '❌';
                    let info = `${icon} <code style="font-size:0.7rem">${escapeHtml(det.endpoint)}</code>`;
                    if (det.httpCode) info += ` HTTP ${det.httpCode}`;
                    if (det.message) info += ` — ${escapeHtml(det.message)}`;
                    if (det.body) info += ` — ${escapeHtml(det.body)}`;
                    if (det.removed) info += ' <span class="text-warning">(removed)</span>';
                    html += `<li>${info}</li>`;
                });
                html += '</ul>';
            }
            msg.innerHTML = html;
            msg.className = d.failed > 0 ? 'small mb-3 text-warning' : 'small mb-3 text-success';
        } else {
            msg.textContent = d.error || 'Failed to send';
            msg.className = 'small mb-3 text-danger';
        }
        btn.disabled = false;
    });

    document.getElementById('btn-detail-clear-subs').addEventListener('click', async () => {
        const user = adminUsers.find(u => u.id === currentDetailUserId);
        const name = user ? user.username : 'this user';
        if (!confirm(`Delete all push subscriptions for "${name}"?`)) return;
        const btn = document.getElementById('btn-detail-clear-subs');
        btn.disabled = true;
        const res = await fetch('api/push.php?action=clear_subscriptions', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: currentDetailUserId})
        });
        const d = await res.json();
        const msg = document.getElementById('user-detail-msg');
        if (d.success) {
            msg.textContent = `Deleted ${d.deleted} subscription(s) for ${name}.`;
            msg.className = 'small mb-3 text-success';
            loadPushDebug();
        } else {
            msg.textContent = d.error || 'Failed';
            msg.className = 'small mb-3 text-danger';
        }
        btn.disabled = false;
    });

    document.getElementById('btn-detail-clean-stats').addEventListener('click', async () => {
        const user = adminUsers.find(u => u.id === currentDetailUserId);
        const name = user ? user.username : 'this user';
        if (!confirm(`Statistiken gelöschter Fragen für "${name}" entfernen?`)) return;
        const btn = document.getElementById('btn-detail-clean-stats');
        btn.disabled = true;
        const res = await fetch('api/admin.php?action=clean_deleted_stats', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: currentDetailUserId})
        });
        const d = await res.json();
        const msg = document.getElementById('user-detail-msg');
        if (d.success) {
            msg.textContent = `${d.deleted} gelöschte Frage(n) entfernt.`;
            msg.className = 'small mb-3 text-success';
            openUserDetail(currentDetailUserId);
        } else {
            msg.textContent = d.error || 'Fehler';
            msg.className = 'small mb-3 text-danger';
        }
        btn.disabled = false;
    });

    document.getElementById('btn-detail-clear-decos').addEventListener('click', async () => {
        const user = adminUsers.find(u => u.id === currentDetailUserId);
        const name = user ? user.username : 'this user';
        if (!confirm(`Alle Dekorationen für "${name}" entfernen und Kosten erstatten?`)) return;
        const btn = document.getElementById('btn-detail-clear-decos');
        btn.disabled = true;
        const res = await fetch('api/admin.php?action=clear_decorations', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: currentDetailUserId})
        });
        const d = await res.json();
        const msg = document.getElementById('user-detail-msg');
        if (d.success) {
            let txt = `${d.deleted} Dekoration(en) entfernt. Erstattet:`;
            if (d.refund.points) txt += ` 🏆${d.refund.points}`;
            if (d.refund.diamonds) txt += ` 💎${d.refund.diamonds}`;
            if (d.refund.stars) txt += ` ⭐${d.refund.stars}`;
            msg.textContent = txt;
            msg.className = 'small mb-3 text-success';
            openUserDetail(currentDetailUserId);
        } else {
            msg.textContent = d.error || 'Fehler';
            msg.className = 'small mb-3 text-danger';
        }
        btn.disabled = false;
    });

    document.getElementById('btn-detail-delete').addEventListener('click', async () => {
        const user = adminUsers.find(u => u.id === currentDetailUserId);
        const name = user ? user.username : 'this user';
        if (!confirm(`Are you sure you want to delete user "${name}"?`)) return;
        const res = await fetch('api/admin.php?action=delete_user', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: currentDetailUserId})
        });
        const d = await res.json();
        if (d.success) {
            document.getElementById('admin-user-detail').classList.add('d-none');
            const msg = document.getElementById('admin-user-msg');
            msg.textContent = `User "${name}" deleted.`;
            msg.className = 'mt-2 small text-success';
            await loadUsers();
        }
    });

    document.getElementById('btn-detail-kill').addEventListener('click', async () => {
        const user = adminUsers.find(u => u.id === currentDetailUserId);
        const name = user ? user.username : 'this user';
        if (!confirm(`Kill ${name}'s chicken?`)) return;
        const res = await fetch('api/admin.php?action=kill_chicken', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({user_id: currentDetailUserId})
        });
        const d = await res.json();
        const msg = document.getElementById('user-detail-msg');
        if (d.success) {
            msg.textContent = `${name}'s chicken has been killed!`;
            msg.className = 'small mb-3 text-danger';
        } else {
            msg.textContent = d.error || 'Failed';
            msg.className = 'small mb-3 text-danger';
        }
    });

    async function openUserDetail(userId) {
        currentDetailUserId = userId;
        const user = adminUsers.find(u => u.id === userId);
        if (!user) return;
        const detail = document.getElementById('admin-user-detail');
        document.getElementById('user-detail-title').textContent = user.username;
        document.getElementById('edit-user-username').value = user.username;
        document.getElementById('edit-user-password').value = '';
        document.getElementById('edit-user-isadmin').checked = Number(user.isadmin) === 1;
        document.getElementById('edit-user-points').value = user.total_points || 0;
        document.getElementById('edit-user-diamonds').value = user.diamonds || 0;
        document.getElementById('edit-user-stars').value = user.stars || 0;
        document.getElementById('user-detail-msg').textContent = '';

        // Populate question set selector
        const qsSelect = document.getElementById('edit-user-question-set');
        qsSelect.innerHTML = '<option value="">Default (questions.yaml)</option>';
        try {
            const qsRes = await fetch('api/admin.php?action=list_question_files');
            const qsData = await qsRes.json();
            if (qsData.success) {
                qsData.files.forEach(f => {
                    if (f !== 'questions.yaml') {
                        const opt = document.createElement('option');
                        opt.value = f;
                        opt.textContent = f;
                        qsSelect.appendChild(opt);
                    }
                });
            }
        } catch (_) {}
        qsSelect.value = user.question_set || '';

        // Load stats
        const statsDiv = document.getElementById('user-detail-stats');
        statsDiv.innerHTML = '<div class="text-muted small">Loading stats...</div>';
        detail.classList.remove('d-none');

        const res = await fetch(`api/admin.php?action=user_stats&user_id=${userId}`);
        const d = await res.json();
        if (d.success && d.knowledge.length > 0) {
            let html = '<table class="table table-sm"><thead><tr><th>Question</th><th class="text-center">✅</th><th class="text-center">❌</th><th class="text-center">Learned</th></tr></thead><tbody>';
            d.knowledge.forEach(k => {
                html += `<tr><td class="small">${escapeHtml(k.question)}</td><td class="text-center">${k.correct}</td><td class="text-center">${k.incorrect}</td><td class="text-center">${k.knows_well ? '⭐' : '—'}</td></tr>`;
            });
            html += '</tbody></table>';
            statsDiv.innerHTML = html;
        } else {
            statsDiv.innerHTML = '<div class="text-muted small">No question data yet.</div>';
        }

        // Verb training stats
        if (d.success) {
            let vhtml = '<h6 class="mt-3">📝 Verb-Training</h6>';
            if (d.verb_total > 0) {
                vhtml += `<div class="small mb-2">Insgesamt: <strong>${d.verb_total}</strong> Verben &nbsp; ✅ erste Versuch: <strong>${d.verb_correct_first_try}</strong> &nbsp; ❌ mit Fehler: <strong>${d.verb_incorrect}</strong></div>`;
                vhtml += '<table class="table table-sm"><thead><tr><th>Zeitform</th><th class="text-center">Gesamt</th><th class="text-center">✅ 1. Versuch</th><th class="text-center">❌ Fehler</th></tr></thead><tbody>';
                (d.verb_stats || []).forEach(v => {
                    vhtml += `<tr><td>${escapeHtml(v.tense)}</td><td class="text-center">${v.total}</td><td class="text-center">${v.correct_first_try}</td><td class="text-center">${v.incorrect}</td></tr>`;
                });
                vhtml += '</tbody></table>';
            } else {
                vhtml += '<div class="text-muted small">Noch keine Verben trainiert.</div>';
            }
            statsDiv.innerHTML += vhtml;
        }
    }

    async function loadUsers() {
        const usersRes = await fetch('api/admin.php?action=list_users');
        const usersData = await usersRes.json();
        const listEl = document.getElementById('admin-users-list');
        listEl.innerHTML = '';
        if (usersData.success) {
            adminUsers = usersData.users;
            usersData.users.forEach(u => {
                const item = document.createElement('a');
                item.href = '#';
                item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                item.dataset.uid = u.id;
                item.innerHTML = `<div><strong>${escapeHtml(u.username)}</strong>${Number(u.isadmin) === 1 ? ' <span class="badge bg-info">Admin</span>' : ''}</div><div class="text-muted small">💎 ${u.diamonds || 0} &nbsp; ⭐ ${u.stars || 0} &nbsp; 🏆 ${u.total_points || 0}</div>`;
                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    openUserDetail(u.id);
                });
                listEl.appendChild(item);
            });
        }
    }

    async function loadQuestions() {
        const yamlRes = await fetch('api/admin.php?action=get_yaml');
        const yamlData = await yamlRes.json();
        if (yamlData.success) {
            const questions = parseYamlQuestions(yamlData.content);
            renderQuestionEditor(questions);
        }
    }

    async function loadAdminData() {
        await loadUsers();
        await loadQuestions();
        loadPushDebug();
        loadTokens();
    }

    async function loadPushDebug() {
        const debugDiv = document.getElementById('admin-push-debug');
        try {
            const res = await fetch('api/push.php?action=push_debug');
            const d = await res.json();
            if (!d.success) { debugDiv.textContent = 'Failed to load'; return; }

            let html = '';
            html += `<div class="mb-2"><strong>VAPID Public Key:</strong><br><code class="text-break" style="font-size:0.75rem">${escapeHtml(d.vapid_public_key)}</code></div>`;
            html += `<div class="mb-2"><strong>Total Subscriptions:</strong> ${d.total_subscriptions}</div>`;

            html += '<div class="mb-2"><strong>Per User:</strong></div>';
            html += '<table class="table table-sm mb-2"><thead><tr><th>User</th><th class="text-center">Subscriptions</th></tr></thead><tbody>';
            d.users.forEach(u => {
                html += `<tr><td>${escapeHtml(u.username)}</td><td class="text-center">${u.sub_count}</td></tr>`;
            });
            html += '</tbody></table>';

            if (d.subscriptions.length > 0) {
                html += '<div class="mb-1"><strong>Subscription Endpoints:</strong></div>';
                d.subscriptions.forEach(s => {
                    const shortEndpoint = s.endpoint.length > 60 ? s.endpoint.substring(0, 60) + '...' : s.endpoint;
                    html += `<div class="mb-1"><span class="text-muted">${escapeHtml(s.username)}:</span> <code class="text-break" style="font-size:0.7rem">${escapeHtml(shortEndpoint)}</code> <span class="text-muted">(${s.created_at || 'n/a'})</span></div>`;
                });
            }

            debugDiv.innerHTML = html;
        } catch (e) {
            debugDiv.textContent = 'Error loading debug info';
        }
    }

    async function loadTokens() {
        const listEl = document.getElementById('admin-tokens-list');
        const toggle = document.getElementById('toggle-require-token');
        try {
            const res = await fetch('api/admin.php?action=list_tokens');
            const d = await res.json();
            if (!d.success) { listEl.textContent = 'Failed to load'; return; }

            toggle.checked = d.require_access_token;

            if (d.tokens.length === 0) {
                listEl.innerHTML = '<div class="text-muted small">No tokens yet.</div>';
                return;
            }

            const baseUrl = d.base_url || `${location.protocol}//${location.host}`;
            let html = '<table class="table table-sm"><thead><tr><th>Token</th><th>Expires</th><th></th></tr></thead><tbody>';
            d.tokens.forEach(t => {
                const shortToken = t.token.substring(0, 12) + '...';
                const expired = new Date(t.expires_at + 'Z') < new Date();
                const url = `${baseUrl}/?t=${encodeURIComponent(t.token)}`;
                html += `<tr${expired ? ' class="text-decoration-line-through text-muted"' : ''}>`;
                html += `<td><code style="font-size:0.7rem;cursor:pointer" title="Click to copy URL" onclick="navigator.clipboard.writeText('${url}')">${escapeHtml(shortToken)}</code></td>`;
                html += `<td class="small">${escapeHtml(t.expires_at)}</td>`;
                html += `<td><button class="btn btn-outline-danger btn-sm py-0 px-1 btn-delete-token" data-id="${t.id}">&times;</button></td>`;
                html += '</tr>';
            });
            html += '</tbody></table>';
            listEl.innerHTML = html;

            listEl.querySelectorAll('.btn-delete-token').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const tokenId = parseInt(btn.dataset.id);
                    const res = await fetch('api/admin.php?action=delete_token', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({token_id: tokenId})
                    });
                    const d = await res.json();
                    if (d.success) loadTokens();
                });
            });
        } catch (e) {
            listEl.textContent = 'Error loading tokens';
        }
    }

    async function loadShopAdmin() {
        const container = document.getElementById('admin-shop-content');
        container.textContent = 'Laden...';
        try {
            const res = await fetch('api/admin.php?action=shop_stats');
            const data = await res.json();
            if (!data.success) { container.textContent = 'Fehler'; return; }
            const items = data.items;
            const users = data.users;
            const owned = data.owned || {};
            const currencyIcon = c => c === 'points' ? '🏆' : c === 'diamonds' ? '💎' : '⭐';
            let html = '';
            for (const item of items) {
                html += `<h6 class="mt-3 mb-2">${escapeHtml(item.name)} <span class="text-muted small">(${currencyIcon(item.currency)} ${item.price}, max ${item.max_quantity})</span></h6>`;
                html += '<table class="table table-sm table-bordered mb-0"><thead><tr><th>User</th><th class="text-center">Gekauft</th><th class="text-center">Verfügbar</th></tr></thead><tbody>';
                for (const u of users) {
                    const o = (owned[u.id] && owned[u.id][item.code]) || 0;
                    const remaining = Math.max(0, item.max_quantity - o);
                    html += `<tr><td>${escapeHtml(u.username)}</td><td class="text-center">${o}</td><td class="text-center">${remaining}</td></tr>`;
                }
                html += '</tbody></table>';
            }
            container.innerHTML = html;
        } catch (e) {
            container.textContent = 'Failed to load';
        }
    }

    // --- AI QUESTION GENERATOR ---
    let aiLastTopic = '';

    // --- FIESTA ADMIN ---
    async function initFiestaAdmin() {
        try {
            const res = await fetch('api/admin.php?action=get_fiesta_settings');
            const data = await res.json();
            if (!data.success) return;
            const toggleBtn = document.getElementById('btn-toggle-fiesta');
            updateFiestaToggle(toggleBtn, data.fiesta_mode);
        } catch (e) { console.error(e); }
    }

    function updateFiestaToggle(btn, mode) {
        const styles = {
            normal: ['🕘 Normal (21–01)', 'btn btn-sm btn-outline-secondary'],
            always: ['✅ Always On', 'btn btn-sm btn-outline-success'],
            disabled: ['❌ Disabled', 'btn btn-sm btn-outline-danger']
        };
        const s = styles[mode] || styles.normal;
        btn.textContent = s[0];
        btn.className = s[1];
    }

    document.getElementById('btn-toggle-fiesta').addEventListener('click', async () => {
        const msg = document.getElementById('fiesta-toggle-msg');
        try {
            const res = await fetch('api/admin.php?action=toggle_fiesta', {
                method: 'POST', headers: {'Content-Type': 'application/json'}
            });
            const data = await res.json();
            if (data.success) {
                updateFiestaToggle(document.getElementById('btn-toggle-fiesta'), data.fiesta_mode);
                fiestaDisabled = data.fiesta_mode;
                checkFiestaTime();
                msg.textContent = data.fiesta_mode === 'always' ? 'Always on' : data.fiesta_mode === 'disabled' ? 'Disabled' : 'Normal (21–01)';
                msg.className = 'small mt-1 text-success';
            }
        } catch (e) { msg.textContent = 'Failed'; msg.className = 'small mt-1 text-danger'; }
    });

    async function loadAudioAdmin() {
        const list = document.getElementById('audio-list');
        const empty = document.getElementById('audio-empty');
        list.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';
        empty.classList.add('d-none');
        try {
            const res = await fetch('api/admin.php?action=list_audio');
            const data = await res.json();
            list.innerHTML = '';
            if (!data.success || !data.files.length) {
                empty.classList.remove('d-none');
                return;
            }
            data.files.forEach(f => {
                const item = document.createElement('div');
                item.className = 'list-group-item d-flex justify-content-between align-items-center';
                const textSpan = document.createElement('span');
                textSpan.className = 'text-truncate me-2';
                textSpan.style.maxWidth = '60%';
                textSpan.textContent = f.text;
                textSpan.title = f.text;
                const btnGroup = document.createElement('div');
                btnGroup.className = 'd-flex gap-1 flex-shrink-0';

                const playBtn = document.createElement('button');
                playBtn.className = 'btn btn-sm btn-outline-secondary';
                playBtn.textContent = '▶️';
                playBtn.addEventListener('click', async () => {
                    playBtn.disabled = true;
                    try {
                        const r = await fetch('api/tts.php?text=' + encodeURIComponent(f.text) + '&lang=' + encodeURIComponent(currentLang));
                        if (r.ok) {
                            const blob = await r.blob();
                            const url = URL.createObjectURL(blob);
                            const audio = new Audio(url);
                            audio.play();
                            audio.addEventListener('ended', () => URL.revokeObjectURL(url));
                        }
                    } catch {}
                    playBtn.disabled = false;
                });

                const delBtn = document.createElement('button');
                delBtn.className = 'btn btn-sm btn-outline-danger';
                delBtn.textContent = '🗑️';
                delBtn.addEventListener('click', async () => {
                    if (!confirm(`Delete audio for "${f.text}"?`)) return;
                    delBtn.disabled = true;
                    try {
                        const r = await fetch('api/admin.php?action=delete_audio', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({id: f.id})
                        });
                        const d = await r.json();
                        if (d.success) item.remove();
                        if (!list.children.length) empty.classList.remove('d-none');
                    } catch {}
                    delBtn.disabled = false;
                });

                btnGroup.appendChild(playBtn);
                btnGroup.appendChild(delBtn);
                item.appendChild(textSpan);
                item.appendChild(btnGroup);
                list.appendChild(item);
            });
        } catch {
            list.innerHTML = '<div class="text-danger small">Failed to load audio files.</div>';
        }
    }

    async function initAiSection() {
        const noKey = document.getElementById('ai-no-key');
        const formArea = document.getElementById('ai-form-area');
        try {
            const res = await fetch('api/ai_questions.php?action=check_key');
            const d = await res.json();
            if (!d.has_key) {
                noKey.classList.remove('d-none');
                formArea.classList.add('d-none');
            } else {
                noKey.classList.add('d-none');
                formArea.classList.remove('d-none');
            }
        } catch (e) {
            noKey.classList.remove('d-none');
            formArea.classList.add('d-none');
        }
        document.getElementById('ai-results').classList.add('d-none');
        document.getElementById('ai-error').classList.add('d-none');
        document.getElementById('ai-add-msg').textContent = '';
    }

    async function generateAiQuestions(topic) {
        const spinner = document.getElementById('ai-spinner');
        const errorEl = document.getElementById('ai-error');
        const resultsEl = document.getElementById('ai-results');
        const listEl = document.getElementById('ai-questions-list');
        const btnGen = document.getElementById('btn-ai-generate');

        spinner.classList.remove('d-none');
        errorEl.classList.add('d-none');
        resultsEl.classList.add('d-none');
        btnGen.disabled = true;
        document.getElementById('ai-add-msg').textContent = '';

        try {
            const res = await fetch('api/ai_questions.php?action=generate', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ topic })
            });
            const d = await res.json();
            spinner.classList.add('d-none');
            btnGen.disabled = false;

            if (!d.success) {
                errorEl.textContent = d.error || 'Generation failed.';
                errorEl.classList.remove('d-none');
                return;
            }

            listEl.innerHTML = '';
            d.questions.forEach((q, i) => {
                const div = document.createElement('div');
                div.className = 'form-check border rounded p-2 mb-2';
                const answersStr = q.answers.map(a => escapeHtml(a)).join(' / ');
                div.innerHTML = `
                    <input class="form-check-input ai-q-check" type="checkbox" id="ai-q-${i}" checked data-index="${i}">
                    <label class="form-check-label" for="ai-q-${i}">
                        <strong>${escapeHtml(q.question)}</strong><br>
                        <span class="text-muted small">${answersStr}</span>
                    </label>
                `;
                div.dataset.question = JSON.stringify(q);
                listEl.appendChild(div);
            });

            resultsEl.classList.remove('d-none');
        } catch (e) {
            spinner.classList.add('d-none');
            btnGen.disabled = false;
            errorEl.textContent = 'Network error: ' + (e.message || e) + '. Please try again.';
            errorEl.classList.remove('d-none');
        }
    }

    document.getElementById('btn-ai-generate').addEventListener('click', () => {
        const topic = document.getElementById('ai-topic').value.trim();
        if (!topic) { document.getElementById('ai-topic').focus(); return; }
        aiLastTopic = topic;
        generateAiQuestions(topic);
    });

    document.getElementById('btn-ai-retry').addEventListener('click', () => {
        const topic = aiLastTopic || document.getElementById('ai-topic').value.trim();
        if (!topic) { document.getElementById('ai-topic').focus(); return; }
        generateAiQuestions(topic);
    });

    document.getElementById('btn-ai-add').addEventListener('click', async () => {
        const checks = document.querySelectorAll('#ai-questions-list .ai-q-check:checked');
        if (checks.length === 0) {
            document.getElementById('ai-add-msg').textContent = 'Please select at least one question.';
            document.getElementById('ai-add-msg').className = 'small mt-2 text-warning';
            return;
        }
        const selected = [];
        checks.forEach(cb => {
            const container = cb.closest('[data-question]');
            if (container) selected.push(JSON.parse(container.dataset.question));
        });

        const btn = document.getElementById('btn-ai-add');
        btn.disabled = true;
        try {
            const res = await fetch('api/ai_questions.php?action=add', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ questions: selected })
            });
            const d = await res.json();
            const msg = document.getElementById('ai-add-msg');
            if (d.success) {
                msg.textContent = `${d.added} question(s) added!`;
                msg.className = 'small mt-2 text-success';
                // Uncheck added items
                checks.forEach(cb => { cb.checked = false; cb.disabled = true; });
            } else {
                msg.textContent = d.error || 'Failed to add questions.';
                msg.className = 'small mt-2 text-danger';
            }
        } catch (e) {
            document.getElementById('ai-add-msg').textContent = 'Network error: ' + (e.message || e);
            document.getElementById('ai-add-msg').className = 'small mt-2 text-danger';
        }
        btn.disabled = false;
    });

    document.getElementById('btn-generate-token').addEventListener('click', async () => {
        const btn = document.getElementById('btn-generate-token');
        btn.disabled = true;
        const res = await fetch('api/admin.php?action=generate_token', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: '{}'
        });
        const d = await res.json();
        const msg = document.getElementById('admin-token-msg');
        if (d.success) {
            msg.innerHTML = `<span class="text-success">Token created!</span><br><input type="text" class="form-control form-control-sm mt-1" value="${escapeHtml(d.url)}" readonly onclick="this.select()">`;
            loadTokens();
        } else {
            msg.textContent = d.error || 'Failed';
            msg.className = 'text-danger';
        }
        btn.disabled = false;
    });

    document.getElementById('toggle-require-token').addEventListener('change', async () => {
        const res = await fetch('api/admin.php?action=toggle_require_token', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: '{}'
        });
        const d = await res.json();
        const msg = document.getElementById('admin-token-msg');
        if (d.success) {
            msg.innerHTML = `<span class="text-success">Token requirement ${d.require_access_token ? 'enabled' : 'disabled'}.</span>`;
        }
    });

    // Simple YAML parser for our question format
    function parseYamlQuestions(yaml) {
        const questions = [];
        if (!yaml || !yaml.trim()) return questions;
        const lines = yaml.split('\n');
        let current = null;
        for (const line of lines) {
            const qMatch = line.match(/^- question:\s*"(.+)"$/);
            if (qMatch) {
                current = { question: qMatch[1], answers: [] };
                questions.push(current);
                continue;
            }
            const aMatch = line.match(/^\s+-\s*"(.+)"$/);
            if (aMatch && current) {
                current.answers.push(aMatch[1]);
            }
        }
        return questions;
    }

    // Serialize questions back to YAML
    function questionsToYaml(questions) {
        return questions.map(q => {
            const answers = q.answers.map(a => `    - "${a}"`).join('\n');
            return `- question: "${q.question}"\n  answers:\n${answers}`;
        }).join('\n\n') + '\n';
    }

    // Collect current questions from the DOM editor
    function collectQuestionsFromEditor() {
        const cards = document.querySelectorAll('#admin-questions-list .question-card');
        const questions = [];
        cards.forEach(card => {
            const q = card.querySelector('.question-input').value.trim();
            const answerInputs = card.querySelectorAll('.answer-input');
            const answers = [];
            answerInputs.forEach(inp => {
                const v = inp.value.trim();
                if (v) answers.push(v);
            });
            if (q && answers.length > 0) {
                questions.push({ question: q, answers });
            }
        });
        return questions;
    }

    // Render the structured question editor
    function renderQuestionEditor(questions) {
        const container = document.getElementById('admin-questions-list');
        container.innerHTML = '';
        questions.forEach((q, i) => {
            container.appendChild(createQuestionCard(q, i));
        });
    }

    function createQuestionCard(q, index) {
        const card = document.createElement('div');
        card.className = 'question-card card mb-3';
        const body = document.createElement('div');
        body.className = 'card-body py-2 px-3';

        // Header row: number + delete button
        const header = document.createElement('div');
        header.className = 'd-flex justify-content-between align-items-center mb-2';
        header.innerHTML = `<strong class="small text-muted">Question ${index + 1}</strong>`;
        const btnDel = document.createElement('button');
        btnDel.className = 'btn btn-outline-danger btn-sm py-0 px-1';
        btnDel.textContent = '✕';
        btnDel.title = 'Delete question';
        btnDel.addEventListener('click', () => { if (confirm('Are you sure you want to delete this question?')) { card.remove(); reindexQuestions(); } });
        header.appendChild(btnDel);
        body.appendChild(header);

        // Question input
        const qInput = document.createElement('input');
        qInput.type = 'text';
        qInput.className = 'form-control form-control-sm mb-2 question-input';
        qInput.placeholder = 'Question';
        qInput.value = q.question;
        body.appendChild(qInput);

        // Answers container
        const answersDiv = document.createElement('div');
        answersDiv.className = 'answers-container ps-3';
        q.answers.forEach(a => {
            answersDiv.appendChild(createAnswerRow(a));
        });
        body.appendChild(answersDiv);

        // Add answer button
        const btnAddAnswer = document.createElement('button');
        btnAddAnswer.className = 'btn btn-outline-secondary btn-sm mt-1 ms-3';
        btnAddAnswer.textContent = '+ Answer';
        btnAddAnswer.addEventListener('click', () => {
            answersDiv.appendChild(createAnswerRow(''));
            answersDiv.lastElementChild.querySelector('input').focus();
        });
        body.appendChild(btnAddAnswer);

        card.appendChild(body);
        return card;
    }

    function createAnswerRow(value) {
        const row = document.createElement('div');
        row.className = 'd-flex align-items-center mb-1';
        const inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'form-control form-control-sm answer-input';
        inp.placeholder = 'Answer';
        inp.value = value;
        row.appendChild(inp);
        const btnRm = document.createElement('button');
        btnRm.className = 'btn btn-outline-danger btn-sm ms-1 py-0 px-1';
        btnRm.textContent = '✕';
        btnRm.addEventListener('click', () => row.remove());
        row.appendChild(btnRm);
        return row;
    }

    function reindexQuestions() {
        document.querySelectorAll('#admin-questions-list .question-card').forEach((card, i) => {
            card.querySelector('strong').textContent = `Question ${i + 1}`;
        });
    }

    // Add question button
    document.getElementById('btn-add-question').addEventListener('click', () => {
        const container = document.getElementById('admin-questions-list');
        const index = container.querySelectorAll('.question-card').length;
        const card = createQuestionCard({ question: '', answers: [''] }, index);
        container.appendChild(card);
        card.querySelector('.question-input').focus();
    });

    // Save questions button
    document.getElementById('btn-save-questions').addEventListener('click', async () => {
        const msg = document.getElementById('admin-yaml-msg');
        const questions = collectQuestionsFromEditor();
        if (questions.length === 0) {
            msg.textContent = 'Add at least one question with answers';
            msg.className = 'mt-2 small text-danger';
            return;
        }
        const content = questionsToYaml(questions);
        const res = await fetch('api/admin.php?action=save_yaml', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ content })
        });
        const data = await res.json();
        if (data.success) {
            msg.textContent = 'Questions saved!';
            msg.className = 'mt-2 small text-success';
        } else {
            msg.textContent = data.error || 'Failed to save';
            msg.className = 'mt-2 small text-danger';
        }
    });

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    document.getElementById('form-create-user').addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = document.getElementById('admin-user-msg');
        msg.textContent = '';
        const username = document.getElementById('admin-new-username').value;
        const password = document.getElementById('admin-new-password').value;
        const isadmin = document.getElementById('admin-new-isadmin').checked ? 1 : 0;
        const res = await fetch('api/admin.php?action=create_user', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ username, password, isadmin })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('form-create-user').reset();
            await loadUsers();
            msg.textContent = 'User created!';
            msg.className = 'mt-2 small text-success';
        } else {
            msg.textContent = data.error || 'Failed to create user';
            msg.className = 'mt-2 small text-danger';
        }
    });

    formSettings.addEventListener('submit', async (e) => {
        e.preventDefault();
        const questionsValue = document.getElementById('setting-questions').value;
        const res = await fetch('api/user.php?action=update_settings', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ questions_per_challenge: questionsValue })
        });
        const data = await res.json();
        settingsMsg.textContent = data.success ? 'Settings saved!' : data.error;
        settingsMsg.className = data.success ? 'mt-2 text-success' : 'mt-2 text-danger';
    });

    formPassword.addEventListener('submit', async (e) => {
        e.preventDefault();
        const newPassword = document.getElementById('setting-password').value;
        const res = await fetch('api/user.php?action=change_password', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ new_password: newPassword })
        });
        const data = await res.json();
        settingsMsg.textContent = data.success ? 'Password updated!' : data.error;
        settingsMsg.className = data.success ? 'mt-2 text-success' : 'mt-2 text-danger';
        if (data.success) formPassword.reset();
    });

    // --- GAME ACTIONS (Stubs for Step 6) ---
    const ALL_ANIM_CLASSES = ['anim-idle', 'anim-eat', 'anim-pet', 'anim-star', 'anim-hungry', 'anim-dead', 'anim-sleep', 'anim-wake', 'anim-asleep'];

    function setAnimation(animClass) {
        // No-op if already on this animation — prevents reflow-jump back to
        // CSS default (left:50%) and restart of the keyframe at 0%.
        if (chickenSprite.classList.contains(animClass)) return;
        chickenSprite.classList.remove(...ALL_ANIM_CLASSES);
        void chickenSprite.offsetWidth; // force reflow
        chickenSprite.classList.add(animClass);
    }

    function triggerAnimation(animClass, durationMs) {
        setAnimation(animClass);
        spawnHearts();
        setTimeout(() => {
            // After one-shot animation, restore idle or hungry based on state
            setAnimation(speechBubble.classList.contains('d-none') ? 'anim-idle' : 'anim-hungry');
        }, durationMs);
    }

    function spawnHearts() {
        const area = document.getElementById('pet-area');
        const hearts = ['❤️', '🧡', '💛', '💜', '💕', '💖'];
        for (let i = 0; i < 8; i++) {
            const el = document.createElement('span');
            el.className = 'heart-particle';
            el.textContent = hearts[Math.floor(Math.random() * hearts.length)];
            el.style.left = (40 + Math.random() * 20) + '%';
            el.style.top = (40 + Math.random() * 20) + '%';
            el.style.setProperty('--hx', (Math.random() * 60 - 30) + 'px');
            el.style.setProperty('--hr', (Math.random() * 40 - 20) + 'deg');
            el.style.animationDelay = (Math.random() * 0.6) + 's';
            area.appendChild(el);
            el.addEventListener('animationend', () => el.remove());
        }
    }

    function spawnConfetti() {
        const area = document.getElementById('pet-area');
        const confetti = ['🎊', '🎉', '✨', '⭐', '🌟', '💫', '🟡', '🔴', '🔵', '🟢', '🟣', '🟠'];
        const duration = 20000;
        const interval = 150;
        let elapsed = 0;
        const timer = setInterval(() => {
            elapsed += interval;
            if (elapsed > duration) { clearInterval(timer); return; }
            for (let i = 0; i < 2; i++) {
                const el = document.createElement('span');
                el.className = 'confetti-particle';
                el.textContent = confetti[Math.floor(Math.random() * confetti.length)];
                el.style.left = (Math.random() * 96 + 2) + '%';
                el.style.setProperty('--cx', (Math.random() * 80 - 40) + 'px');
                el.style.setProperty('--cr', (Math.random() * 720 - 360) + 'deg');
                el.style.fontSize = (0.7 + Math.random() * 1.0) + 'rem';
                el.style.animationDuration = (1.8 + Math.random() * 1.4) + 's';
                area.appendChild(el);
                el.addEventListener('animationend', () => el.remove());
            }
        }, interval);
    }

    function spawnSleepHearts() {
        const area = document.getElementById('pet-area');
        if (!area) return;
        document.body.classList.add('has-sleep-hearts');
        const container = document.createElement('div');
        container.className = 'sleep-hearts';
        container.innerHTML = '<span class="zchar">💕</span><span class="zchar">💖</span><span class="zchar">💗</span>';
        area.appendChild(container);
        // Remove after the 3s animation + 2s delay of the 3rd span finishes (5s total)
        setTimeout(() => {
            if (container.parentNode) container.remove();
            document.body.classList.remove('has-sleep-hearts');
        }, 5100);
    }

    // --- CHALLENGE ENGINE (Step 6) ---
    function renderClockSvg(hours, minutes) {
        const h = ((hours % 12) + (minutes / 60));
        const hourAngle = (h * 30) - 90;       // 360/12 per hour
        const minAngle  = (minutes * 6) - 90;  // 360/60 per minute
        const cx = 100, cy = 100, r = 90;
        const polar = (deg, len) => {
            const rad = deg * Math.PI / 180;
            return [cx + Math.cos(rad) * len, cy + Math.sin(rad) * len];
        };
        const [hx, hy] = polar(hourAngle, 50);
        const [mx, my] = polar(minAngle, 75);

        // Hour-tick marks 1..12
        let ticks = '';
        for (let i = 1; i <= 12; i++) {
            const ang = (i * 30) - 90;
            const [x1, y1] = polar(ang, r - 8);
            const [x2, y2] = polar(ang, r);
            ticks += `<line x1="${x1.toFixed(1)}" y1="${y1.toFixed(1)}" x2="${x2.toFixed(1)}" y2="${y2.toFixed(1)}" stroke="#333" stroke-width="3" stroke-linecap="round"/>`;
            // Numbers
            const [nx, ny] = polar(ang, r - 22);
            ticks += `<text x="${nx.toFixed(1)}" y="${(ny + 5).toFixed(1)}" text-anchor="middle" font-size="16" font-family="sans-serif" fill="#333">${i}</text>`;
        }
        return `
            <svg viewBox="0 0 200 200" width="180" height="180" xmlns="http://www.w3.org/2000/svg" aria-label="analog clock">
                <circle cx="${cx}" cy="${cy}" r="${r}" fill="#fff" stroke="#333" stroke-width="4"/>
                ${ticks}
                <line x1="${cx}" y1="${cy}" x2="${hx.toFixed(1)}" y2="${hy.toFixed(1)}" stroke="#000" stroke-width="6" stroke-linecap="round"/>
                <line x1="${cx}" y1="${cy}" x2="${mx.toFixed(1)}" y2="${my.toFixed(1)}" stroke="#000" stroke-width="4" stroke-linecap="round"/>
                <circle cx="${cx}" cy="${cy}" r="5" fill="#000"/>
            </svg>
        `;
    }

    async function startChallenge(type) {
        challengeType = type;

        const btnMap = { pet: btnPet, feed: btnFeed, fiesta: btnFiesta, revive: document.getElementById('btn-revive'), clock: document.getElementById('btn-clock'), verb: document.getElementById('btn-verb') };
        const triggerBtn = btnMap[type] || null;
        let originalHtml = null;
        if (triggerBtn) {
            originalHtml = triggerBtn.innerHTML;
            triggerBtn.disabled = true;
            triggerBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        }

        try {
            const res = await fetch(`api/challenge.php?action=generate&type=${type}`);
            const data = await res.json();
            
            if (data.success) {
                currentChallenge = data.questions;
                currentQuestionIndex = 0;
                challengeResults = [];
                loadQuestion();
                challengeOverlay.classList.remove('d-none');
            } else {
                alert('Could not start challenge: ' + data.error);
            }
        } catch (e) {
            console.error('Error starting challenge:', e);
            alert('Failed to connect to server.');
        } finally {
            if (triggerBtn && originalHtml !== null) {
                triggerBtn.innerHTML = originalHtml;
                triggerBtn.disabled = false;
            }
        }
    }

    function loadQuestion() {
        const q = currentChallenge[currentQuestionIndex];
        currentQuestionAttempts = 0;
        
        challengeProgress.textContent = `Question ${currentQuestionIndex + 1} of ${currentChallenge.length}`;
        challengeQuestion.textContent = q.question;
        // Verb questions ship a multi-line label; render line breaks
        if (q.type === 'verb') {
            challengeQuestion.style.whiteSpace = 'pre-line';
            challengeQuestion.style.fontSize = '1.2rem';
        } else {
            challengeQuestion.style.whiteSpace = '';
            challengeQuestion.style.fontSize = '';
        }
        inputChallengeAnswer.value = '';
        inputChallengeAnswer.disabled = false;
        btnChallengeSubmit.classList.remove('d-none');
        
        const textInput = document.getElementById('challenge-text-input');
        const mcOptions = document.getElementById('challenge-mc-options');
        const qLabel   = document.getElementById('challenge-question-label');
        const clockFace = document.getElementById('challenge-clock-face');

        // Question type label
        if (q.type === 'gap') {
            qLabel.textContent = '✏️ Completa la frase:';
            qLabel.classList.remove('d-none');
        } else if (q.type === 'clock') {
            qLabel.textContent = '🕐';
            qLabel.classList.remove('d-none');
        } else if (q.type === 'verb') {
            qLabel.textContent = '📝 Konjugiere und bilde einen Satz:';
            qLabel.classList.remove('d-none');
        } else {
            qLabel.textContent = '';
            qLabel.classList.add('d-none');
        }

        // Clock face SVG
        if (q.type === 'clock' && clockFace) {
            clockFace.innerHTML = renderClockSvg(q.hours, q.minutes);
            clockFace.classList.remove('d-none');
        } else if (clockFace) {
            clockFace.innerHTML = '';
            clockFace.classList.add('d-none');
        }

        if (q.options && q.options.length > 0) {
            // Multiple choice mode
            textInput.classList.add('d-none');
            inputChallengeAnswer.removeAttribute('required');
            btnChallengeSubmit.classList.add('d-none');
            mcOptions.classList.remove('d-none');
            mcOptions.innerHTML = '';
            q.options.forEach(opt => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-outline-primary btn-lg';
                btn.textContent = opt;
                btn.addEventListener('click', () => handleMcSelection(opt, mcOptions));
                mcOptions.appendChild(btn);
            });
        } else {
            // Normal text input mode
            textInput.classList.remove('d-none');
            inputChallengeAnswer.setAttribute('required', '');
            mcOptions.classList.add('d-none');
            mcOptions.innerHTML = '';
        }
        
        hideFeedback();
        
        if (!q.options) {
            setTimeout(() => inputChallengeAnswer.focus(), 100);
        }
    }

    function handleMcSelection(selected, mcOptions) {
        const q = currentChallenge[currentQuestionIndex];
        currentQuestionAttempts++;

        // Disable all MC buttons
        mcOptions.querySelectorAll('button').forEach(btn => {
            btn.disabled = true;
            const isCorrect = q.answers.some(a => a.trim().toLowerCase() === btn.textContent.trim().toLowerCase());
            if (isCorrect) {
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-success');
            } else if (btn.textContent === selected) {
                btn.classList.remove('btn-outline-primary');
                btn.classList.add('btn-danger');
            }
        });

        const isCorrect = q.answers.some(a => a.trim().toLowerCase() === selected.trim().toLowerCase());

        if (isCorrect) {
            challengeResults.push({ id: q.id, attempts: currentQuestionAttempts });
            showFeedback('success', "Correct! 🎉", '', q.answers[0]);
            btnChallengeNext.classList.remove('d-none');
            btnChallengeNext.focus();
        } else {
            const correctAnswers = q.answers.join(" OR ");
            showFeedback('danger', "Incorrect 😔", `The correct answer was: ${correctAnswers}`, q.answers[0]);
            btnChallengeRepeat.classList.remove('d-none');
            btnChallengeRepeat.focus();
        }
    }
    
    function hideFeedback() {
        challengeFeedback.classList.add('d-none');
        challengeFeedbackText.classList.add('d-none');
        btnChallengeNext.classList.add('d-none');
        btnChallengeRepeat.classList.add('d-none');
        btnTtsPlay.classList.add('d-none');
        btnTtsPlay.removeAttribute('data-tts-text');
    }

    function showFeedback(type, title, text = '', ttsText = '') {
        challengeFeedback.className = `mt-4 p-3 rounded text-center alert alert-${type}`;
        challengeFeedbackTitle.textContent = title;
        
        if (text) {
            challengeFeedbackText.textContent = text;
            challengeFeedbackText.classList.remove('d-none');
        } else {
            challengeFeedbackText.classList.add('d-none');
        }

        if (ttsText) {
            btnTtsPlay.dataset.ttsText = ttsText;
            btnTtsPlay.classList.remove('d-none');
        }
    }

    async function handleSubmission(e) {
        e.preventDefault();
        
        const q = currentChallenge[currentQuestionIndex];
        const userAnswer = inputChallengeAnswer.value.trim().toLowerCase();
        
        // Validation logic for multiple generic answers
        let isCorrect;
        if (q.type === 'clock') {
            const norm = (s) => String(s).toLowerCase()
                .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
                .replace(/[^\p{L}\s]/gu, ' ')
                .replace(/\s+/g, ' ')
                .trim();
            const userN = norm(inputChallengeAnswer.value);
            isCorrect = userN.length > 0 && q.answers.some(a => norm(a) === userN);
        } else if (q.type === 'verb') {
            const norm = (s) => String(s).toLowerCase()
                .replace(/[.!?;,]+$/g, '')
                .replace(/\s+/g, ' ')
                .trim();
            const userN = norm(inputChallengeAnswer.value);
            isCorrect = userN.length > 0 && q.answers.some(a => norm(a) === userN);
        } else {
            isCorrect = q.answers.some(a => a.trim().toLowerCase() === userAnswer);
        }
        
        currentQuestionAttempts++;
        
        inputChallengeAnswer.disabled = true;
        btnChallengeSubmit.classList.add('d-none');

        if (isCorrect) {
            challengeResults.push({ id: q.id, attempts: currentQuestionAttempts, tense: q.tense });
            showFeedback('success', "Correct! 🎉", '', q.answers[0]);
            btnChallengeNext.classList.remove('d-none');
            btnChallengeNext.focus();
        } else if (q.type === 'gap') {
            // For gap questions: ask OpenAI if the answer is grammatically acceptable
            // Lock all navigation buttons while the request is in flight
            btnChallengeNext.classList.add('d-none');
            btnChallengeRepeat.classList.add('d-none');
            // Show spinner in feedback area (bypass showFeedback to use innerHTML for the spinner)
            challengeFeedback.className = 'mt-4 p-3 rounded text-center alert alert-info';
            challengeFeedbackTitle.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Checking…';
            challengeFeedbackText.classList.add('d-none');
            btnTtsPlay.classList.add('d-none');
            let aiAccepted = false;
            let aiExplanation = null;
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 12000); // 12s > PHP's 10s curl timeout
                const res = await fetch('api/challenge.php?action=check_gap_answer', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        sentence:    q.question,
                        user_answer: inputChallengeAnswer.value.trim(),
                        lang:        currentLang,
                    }),
                    signal: controller.signal,
                });
                clearTimeout(timeoutId);
                const data = await res.json();
                aiAccepted = !!data.valid;
                aiExplanation = data.explanation || null;
            } catch (err) {
                console.error('Gap answer check failed:', err);
            }
            if (aiAccepted) {
                challengeResults.push({ id: q.id, attempts: currentQuestionAttempts });
                const note = aiExplanation
                    ? `${aiExplanation} (expected: ${q.answers[0]})`
                    : `expected: ${q.answers[0]}`;
                showFeedback('success', "Correct! 🎉", note, q.answers[0]);
                btnChallengeNext.classList.remove('d-none');
                btnChallengeNext.focus();
            } else {
                const correctAnswers = q.answers.join(" OR ");
                const note = aiExplanation ? `${aiExplanation} (expected: ${correctAnswers})` : `The correct answer was: ${correctAnswers}`;
                showFeedback('danger', "Incorrect 😔", note, q.answers[0]);
                btnChallengeRepeat.classList.remove('d-none');
                btnChallengeRepeat.focus();
            }
        } else if (q.type === 'verb') {
            // For verb questions: ask OpenAI if the conjugated sentence is grammatically acceptable
            btnChallengeNext.classList.add('d-none');
            btnChallengeRepeat.classList.add('d-none');
            challengeFeedback.className = 'mt-4 p-3 rounded text-center alert alert-info';
            challengeFeedbackTitle.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Checking…';
            challengeFeedbackText.classList.add('d-none');
            btnTtsPlay.classList.add('d-none');
            let aiAccepted = false;
            let aiExplanation = null;
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 17000);
                const res = await fetch('api/challenge.php?action=check_verb_answer', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        verb:        q.verb,
                        tense:       q.tense,
                        person:      q.person,
                        expected:    q.answers[0],
                        user_answer: inputChallengeAnswer.value.trim(),
                    }),
                    signal: controller.signal,
                });
                clearTimeout(timeoutId);
                const data = await res.json();
                aiAccepted = !!data.valid;
                aiExplanation = data.explanation || null;
            } catch (err) {
                console.error('Verb answer check failed:', err);
            }
            if (aiAccepted) {
                challengeResults.push({ id: q.id, attempts: currentQuestionAttempts, tense: q.tense });
                const note = aiExplanation
                    ? `${aiExplanation} (e.g.: ${q.answers[0]})`
                    : `e.g.: ${q.answers[0]}`;
                showFeedback('success', "Correct! 🎉", note, q.answers[0]);
                btnChallengeNext.classList.remove('d-none');
                btnChallengeNext.focus();
            } else {
                const note = aiExplanation ? `${aiExplanation} (e.g.: ${q.answers[0]})` : `e.g.: ${q.answers[0]}`;
                showFeedback('danger', "Incorrect 😔", note, q.answers[0]);
                btnChallengeRepeat.classList.remove('d-none');
                btnChallengeRepeat.focus();
            }
        } else {
            const correctAnswers = q.answers.join(" OR ");
            showFeedback('danger', "Incorrect 😔", `The correct answer was: ${correctAnswers}`, q.answers[0]);
            btnChallengeRepeat.classList.remove('d-none');
            btnChallengeRepeat.focus();
        }
    }

    formChallenge.addEventListener('submit', handleSubmission);

    btnTtsPlay.addEventListener('click', async () => {
        const text = btnTtsPlay.dataset.ttsText;
        if (!text) return;
        btnTtsPlay.disabled = true;
        btnTtsPlay.textContent = '⏳ ...';
        try {
            const res = await fetch('api/tts.php?text=' + encodeURIComponent(text) + '&lang=' + encodeURIComponent(currentLang));
            const ct = res.headers.get('content-type') || '';
            if (!res.ok || !ct.includes('audio/')) {
                const body = await res.text();
                console.error('TTS error:', res.status, ct, body);
                let msg = `TTS failed (${res.status})`;
                try { const d = JSON.parse(body); if (d.error) msg = d.error; } catch {}
                btnTtsPlay.textContent = '❌ ' + msg;
                btnTtsPlay.disabled = false;
                setTimeout(() => { btnTtsPlay.textContent = '🔊 Escuchar'; }, 4000);
                return;
            }
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const audio = new Audio(url);
            audio.play();
            audio.addEventListener('ended', () => URL.revokeObjectURL(url));
            btnTtsPlay.disabled = false;
            btnTtsPlay.textContent = '🔊 Escuchar';
        } catch (e) {
            btnTtsPlay.textContent = '❌ ' + e.message;
            btnTtsPlay.disabled = false;
            setTimeout(() => { btnTtsPlay.textContent = '🔊 Escuchar'; }, 4000);
        }
    });

    btnChallengeNext.addEventListener('click', () => {
        currentQuestionIndex++;
        if (currentQuestionIndex < currentChallenge.length) {
            loadQuestion();
        } else {
            finishChallenge();
        }
    });
    
    btnChallengeRepeat.addEventListener('click', () => {
        const q = currentChallenge[currentQuestionIndex];
        if (q.options && q.options.length > 0) {
            // Re-shuffle and re-render MC options
            const mcOptions = document.getElementById('challenge-mc-options');
            mcOptions.innerHTML = '';
            const shuffled = [...q.options].sort(() => Math.random() - 0.5);
            shuffled.forEach(opt => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-outline-primary btn-lg';
                btn.textContent = opt;
                btn.addEventListener('click', () => handleMcSelection(opt, mcOptions));
                mcOptions.appendChild(btn);
            });
        } else {
            inputChallengeAnswer.value = '';
            inputChallengeAnswer.disabled = false;
            btnChallengeSubmit.classList.remove('d-none');
            inputChallengeAnswer.focus();
        }
        hideFeedback();
    });

    btnChallengeClose.addEventListener('click', () => {
        challengeOverlay.classList.add('d-none');
    });

    async function finishChallenge() {
        challengeOverlay.classList.add('d-none');
        
        try {
            const res = await fetch('api/challenge.php?action=submit', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    type: challengeType,
                    results: challengeResults
                })
            });
            const data = await res.json();
            
            if(data.success) {
                const wasSleeping = isSleeping;
                updateStatsUI(data.stats, wasSleeping);
                
                if (challengeType === 'pet' && wasSleeping) {
                    // Only spawn sleep-hearts and nothing else (no wake, no anim-pet, no anim-star)
                    spawnSleepHearts();
                    console.log("spawnSleepHearts");
                } else if (data.earned_star) {
                    triggerAnimation('anim-star', 3125);
                } else if (challengeType === 'revive') {
                    triggerAnimation('anim-pet', 4500);
                } else if (challengeType === 'fiesta') {
                    startFiestaCooldown(data.stats.fiesta_cooldown || 300);
                    spawnConfetti();
                } else if (challengeType === 'pet') {
                    triggerAnimation('anim-pet', 4500);
                } else {
                    triggerAnimation('anim-eat', 4000);
                }
            } else {
                alert('Error submitting results: ' + data.error);
            }
        } catch (e) {
            console.error('Error:', e);
        }
    }

    btnPet.addEventListener('click', () => {
        startChallenge('pet');
    });
    
    btnFeed.addEventListener('click', () => {
        if (btnFeed.disabled) return;
        startChallenge('feed');
    });

    const btnClock = document.getElementById('btn-clock');
    if (btnClock) {
        btnClock.addEventListener('click', () => {
            if (btnClock.disabled) return;
            startChallenge('clock');
        });
    }

    const btnVerb = document.getElementById('btn-verb');
    if (btnVerb) {
        btnVerb.addEventListener('click', () => {
            if (btnVerb.disabled) return;
            startChallenge('verb');
        });
    }

    // --- FIESTA ---
    const btnFiesta = document.getElementById('btn-fiesta');
    const fiestaCountdown = document.getElementById('fiesta-countdown');
    let fiestaTimer = null;
    let fiestaDisabled = 'normal';

    function checkFiestaTime() {
        const isHungry = !speechBubble.classList.contains('d-none');
        if (fiestaDisabled === 'disabled' || isHungry) {
            btnFiesta.classList.add('d-none');
            fiestaCountdown.classList.add('d-none');
            if (fiestaTimer) { clearInterval(fiestaTimer); fiestaTimer = null; }
            btnFiesta.disabled = false;
            return;
        }
        if (fiestaDisabled === 'always') {
            btnFiesta.classList.remove('d-none');
            return;
        }
        const h = new Date().getHours();
        const visible = (h >= 21 || h < 1);
        btnFiesta.classList.toggle('d-none', !visible);
        if (!visible) fiestaCountdown.classList.add('d-none');
    }

    function startFiestaCooldown(seconds) {
        btnFiesta.disabled = true;
        if (fiestaTimer) clearInterval(fiestaTimer);
        let remaining = seconds;
        const tick = () => {
            if (remaining <= 0) {
                clearInterval(fiestaTimer);
                fiestaTimer = null;
                btnFiesta.disabled = false;
                fiestaCountdown.classList.add('d-none');
                checkFiestaTime();
                return;
            }
            const m = Math.floor(remaining / 60);
            const s = remaining % 60;
            fiestaCountdown.textContent = `Fiesta in ${m}:${String(s).padStart(2, '0')}`;
            fiestaCountdown.classList.remove('d-none');
            remaining--;
        };
        tick();
        fiestaTimer = setInterval(tick, 1000);
    }

    checkFiestaTime();
    setInterval(checkFiestaTime, 60000);

    btnFiesta.addEventListener('click', () => {
        if (btnFiesta.disabled) return;
        startChallenge('fiesta');
    });

    // --- PUSH NOTIFICATIONS ---
    const btnNotifications = document.getElementById('btn-notifications');
    let pushSubscription = null;

    async function initPush() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
        btnNotifications.classList.remove('d-none');

        const reg = await navigator.serviceWorker.register('sw.js');
        pushSubscription = await reg.pushManager.getSubscription();
        updateNotificationButton();
    }

    function updateNotificationButton() {
        if (pushSubscription) {
            btnNotifications.textContent = '\u{1F514} Notifications On';
            btnNotifications.classList.remove('btn-outline-secondary');
            btnNotifications.classList.add('btn-outline-success');
        } else {
            btnNotifications.textContent = '\u{1F515} Notifications Off';
            btnNotifications.classList.remove('btn-outline-success');
            btnNotifications.classList.add('btn-outline-secondary');
        }
    }

    btnNotifications.addEventListener('click', async () => {
        if (pushSubscription) {
            // Unsubscribe
            const endpoint = pushSubscription.endpoint;
            await pushSubscription.unsubscribe();
            pushSubscription = null;
            await fetch('api/push.php?action=unsubscribe', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({endpoint})
            });
            updateNotificationButton();
        } else {
            // Subscribe
            const perm = await Notification.requestPermission();
            if (perm !== 'granted') return;

            const keyRes = await fetch('api/push.php?action=vapid_public_key');
            const keyData = await keyRes.json();
            if (!keyData.success) return;

            const applicationServerKey = urlBase64ToUint8Array(keyData.publicKey);
            const reg = await navigator.serviceWorker.ready;
            pushSubscription = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey
            });

            const subJson = pushSubscription.toJSON();
            await fetch('api/push.php?action=subscribe', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    endpoint: subJson.endpoint,
                    keys: subJson.keys
                })
            });
            updateNotificationButton();
        }
    });

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(base64);
        const arr = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    // Send hunger alerts button (admin)
    document.getElementById('btn-send-hungry').addEventListener('click', async () => {
        const btn = document.getElementById('btn-send-hungry');
        const msg = document.getElementById('admin-push-msg');
        btn.disabled = true;
        msg.textContent = 'Sending...';
        msg.className = 'mt-2 small text-muted';
        const res = await fetch('api/push.php?action=send_hungry', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({})
        });
        const d = await res.json();
        if (d.success) {
            msg.textContent = `Done! Sent: ${d.sent}, Failed: ${d.failed}`;
            msg.className = 'mt-2 small text-success';
        } else {
            msg.textContent = d.error || 'Failed';
            msg.className = 'mt-2 small text-danger';
        }
        btn.disabled = false;
    });

    // Kick off
    checkAuth();
});
