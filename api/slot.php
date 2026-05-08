<?php
/**
 * Mini Slot Machine.
 *
 * Mechanics
 * ---------
 *  Cost per selected fruit = 100 * factor   (factor ∈ {1,2,5})
 *  Each selected fruit is one independent spin.
 *  P(win) = 0.20, payout multiplier = 4 → expected return per bet = 0.8 (RTP 80%).
 *
 * The user's fruit selection has NO influence on the outcome:
 *  - the engine first decides win/lose at fixed probability
 *  - on a win, the landing fruit is chosen from the user's selection
 *  - on a loss, the landing fruit is chosen from the OTHER fruits
 *  so the visual result merely reflects whether the engine declared a win.
 */
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

const SLOT_FRUITS         = ['🍒', '🍋', '🍇', '🍉', '🍓', '🔔'];
const SLOT_CHERRY         = '🍒';   // bonus tile — not selectable; refunds the bet (1×) on a loss
const SLOT_SELECTABLE     = ['🍋', '🍇', '🍉', '🍓', '🔔']; // SLOT_FRUITS without cherry
const SLOT_NUKE           = '☢️';   // radioactive tile — landing here is always a loss
const SLOT_FREESPIN       = '🤩';   // bonus reward overlay — awards a free spin
const SLOT_BASE_COST      = 200;
const SLOT_ALLOWED_FACTOR = [1, 2, 5];
// One spin per click. Picking 2 fruits costs twice as much but raises the hit chance.
const SLOT_WIN_PROB_PCT_1 = 20;     // hit chance when 1 fruit selected (RTP ≈ 0.9 with cherry)
const SLOT_WIN_PROB_PCT_2 = 40;     // hit chance when 2 fruits selected (RTP ≈ 0.9 with cherry)
const SLOT_PAYOUT_MULT    = 4;      // win returns 4 * bet → net +3 * bet
const SLOT_CHERRY_LOSS_PCT= 10;     // when losing, chance to land on 🍒 → refund (full cost)
const SLOT_NUKE_LOSS_PCT  = 12;     // when losing (and not cherry), chance to land on ☢️
const SLOT_FREESPIN_PCT   = 5;      // when losing, 1/20 chance to award a free spin (🤩)

if (!isset($_SESSION['slot_free_spins'])) $_SESSION['slot_free_spins'] = 0;

if (!isset($_SESSION['slot_free_spins'])) $_SESSION['slot_free_spins'] = 0;

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'info') {
    $stmt = $pdo->prepare("SELECT total_points FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    echo json_encode([
        'success'           => true,
        'balance'           => (int)($row['total_points'] ?? 0),
        'fruits'            => SLOT_FRUITS,
        'selectable_fruits' => SLOT_SELECTABLE,
        'cherry'            => SLOT_CHERRY,
        'nuke'              => SLOT_NUKE,
        'freespin'          => SLOT_FREESPIN,
        'free_spins'        => (int)$_SESSION['slot_free_spins'],
        'base_cost'         => SLOT_BASE_COST,
        'allowed_factor'    => SLOT_ALLOWED_FACTOR,
        'payout_mult'       => SLOT_PAYOUT_MULT,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'play') {
    $data       = json_decode(file_get_contents('php://input'), true) ?? [];
    $fruitsIn   = $data['fruits'] ?? [];
    $factor     = (int)($data['factor'] ?? 0);
    $useFree    = !empty($data['use_free_spin']) && (int)$_SESSION['slot_free_spins'] > 0;

    // Validate factor
    if (!in_array($factor, SLOT_ALLOWED_FACTOR, true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid factor']);
        exit;
    }
    // Validate fruit selection (1 or 2 distinct fruits from the catalogue)
    if (!is_array($fruitsIn) || count($fruitsIn) < 1 || count($fruitsIn) > 2) {
        echo json_encode(['success' => false, 'error' => 'Wähle 1 oder 2 Früchte']);
        exit;
    }
    $fruitsIn = array_values(array_unique(array_map('strval', $fruitsIn)));
    foreach ($fruitsIn as $f) {
        if (!in_array($f, SLOT_SELECTABLE, true)) {
            echo json_encode(['success' => false, 'error' => 'Diese Frucht ist nicht wählbar']);
            exit;
        }
    }
    if (count($fruitsIn) < 1) {
        echo json_encode(['success' => false, 'error' => 'Wähle 1 oder 2 Früchte']);
        exit;
    }

    $bet       = SLOT_BASE_COST * $factor;
    $totalCost = $useFree ? 0 : ($bet * count($fruitsIn));

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT total_points FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $balance = (int)$stmt->fetchColumn();

        if ($balance < $totalCost) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Nicht genug Points']);
            exit;
        }

        // Consume the free spin BEFORE the spin runs (so a server crash doesn't grant infinite spins).
        if ($useFree) {
            $_SESSION['slot_free_spins'] = max(0, (int)$_SESSION['slot_free_spins'] - 1);
        }

        // Deduct cost up front
        if ($totalCost > 0) {
            $upd = $pdo->prepare("UPDATE users SET total_points = total_points - ? WHERE id = ?");
            $upd->execute([$totalCost, $_SESSION['user_id']]);
        }

        // ONE spin per click. Picking 2 fruits doubles the cost AND raises the hit chance.
        $nFruits     = count($fruitsIn);
        $winProbPct  = ($nFruits >= 2) ? SLOT_WIN_PROB_PCT_2 : SLOT_WIN_PROB_PCT_1;
        $win         = random_int(1, 100) <= $winProbPct;
        $otherFruits = array_values(array_diff(SLOT_SELECTABLE, $fruitsIn));

        if ($win) {
            // Pick one of the user's selected fruits as the landing tile.
            $landing = $fruitsIn[random_int(0, $nFruits - 1)];
            $payout  = $bet * SLOT_PAYOUT_MULT;     // payout always = 4 × bet (one bet unit)
            $outcome = 'win';
        } else {
            $roll = random_int(1, 100);
            if ($roll <= SLOT_CHERRY_LOSS_PCT) {
                $landing = SLOT_CHERRY;
                $payout  = $totalCost;              // refund full cost
                $outcome = 'cherry';
            } elseif ($roll <= SLOT_CHERRY_LOSS_PCT + SLOT_NUKE_LOSS_PCT) {
                $landing = SLOT_NUKE;
                $payout  = 0;
                $outcome = 'nuke';
            } else {
                $landing = $otherFruits[random_int(0, count($otherFruits) - 1)];
                $payout  = 0;
                $outcome = 'lose';
            }
        }

        // Free-spin bonus: 1/20 chance on any losing spin
        $awardedFree = false;
        if (!$win && random_int(1, 100) <= SLOT_FREESPIN_PCT) {
            $_SESSION['slot_free_spins'] = (int)$_SESSION['slot_free_spins'] + 1;
            $awardedFree = true;
        }

        $spins = [[
            'selected'         => $fruitsIn,
            'landing'          => $landing,
            'win'              => $win,
            'outcome'          => $outcome,
            'bet'              => $bet,
            'payout'           => $payout,
            'freespin_awarded' => $awardedFree,
        ]];
        $totalPayout = $payout;

        if ($totalPayout > 0) {
            $upd = $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
            $upd->execute([$totalPayout, $_SESSION['user_id']]);
        }

        $pdo->commit();

        $newBal = $pdo->prepare("SELECT total_points FROM users WHERE id = ?");
        $newBal->execute([$_SESSION['user_id']]);

        echo json_encode([
            'success'        => true,
            'spins'          => $spins,
            'total_cost'     => $totalCost,
            'total_payout'   => $totalPayout,
            'net'            => $totalPayout - $totalCost,
            'balance'        => (int)$newBal->fetchColumn(),
            'used_free_spin' => $useFree,
            'free_spins'     => (int)$_SESSION['slot_free_spins'],
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Server error']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unsupported action']);
