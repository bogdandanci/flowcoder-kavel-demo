<?php
/**
 * Kavel Interest Tracker API
 * Handles likes, shares, and lead submissions
 * Storage: JSON files in data/ directory
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$statsFile = $dataDir . '/stats.json';
$leadsFile = $dataDir . '/leads.json';
$bidsFile  = $dataDir . '/bids.json';

// --- Helpers ---

function loadJson(string $path, $default = null) {
    if (!file_exists($path)) return $default;
    $raw = file_get_contents($path);
    return json_decode($raw, true) ?? $default;
}

function saveJson(string $path, $data): bool {
    $fp = fopen($path, 'c');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    return true;
}

function sanitizeId(string $id): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
}

function getStats(): array {
    global $statsFile;
    return loadJson($statsFile, []);
}

function ensureKavel(array &$stats, string $id): void {
    if (!isset($stats[$id])) {
        $stats[$id] = [
            'likes' => 0,
            'shares' => ['whatsapp' => 0, 'facebook' => 0, 'link' => 0]
        ];
    }
}

// --- Route ---

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Support JSON body for POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['action'])) {
    $body = json_decode(file_get_contents('php://input'), true);
    if ($body) {
        $action = $body['action'] ?? '';
        $_POST = array_merge($_POST, $body);
    }
}

switch ($action) {

    case 'stats':
        $stats = getStats();
        $kavelId = sanitizeId($_GET['kavel_id'] ?? '');
        if ($kavelId && isset($stats[$kavelId])) {
            echo json_encode(['ok' => true, 'data' => [$kavelId => $stats[$kavelId]]]);
        } else {
            echo json_encode(['ok' => true, 'data' => $stats]);
        }
        break;

    case 'like':
        $kavelId = sanitizeId($_POST['kavel_id'] ?? '');
        if (!$kavelId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'kavel_id verplicht']);
            break;
        }
        $stats = getStats();
        ensureKavel($stats, $kavelId);
        $stats[$kavelId]['likes']++;
        saveJson($statsFile, $stats);
        echo json_encode(['ok' => true, 'likes' => $stats[$kavelId]['likes']]);
        break;

    case 'share':
        $kavelId = sanitizeId($_POST['kavel_id'] ?? '');
        $platform = sanitizeId($_POST['platform'] ?? '');
        if (!$kavelId || !$platform) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'kavel_id en platform verplicht']);
            break;
        }
        $allowed = ['whatsapp', 'facebook', 'link'];
        if (!in_array($platform, $allowed)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'platform moet whatsapp, facebook of link zijn']);
            break;
        }
        $stats = getStats();
        ensureKavel($stats, $kavelId);
        $stats[$kavelId]['shares'][$platform]++;
        saveJson($statsFile, $stats);
        $total = array_sum($stats[$kavelId]['shares']);
        echo json_encode(['ok' => true, 'shares' => $stats[$kavelId]['shares'], 'total_shares' => $total]);
        break;

    case 'lead':
        $naam = trim($_POST['naam'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefoon = trim($_POST['telefoon'] ?? '');
        $kavelId = sanitizeId($_POST['kavel_id'] ?? '');
        $bericht = trim($_POST['bericht'] ?? '');

        if (!$naam || !$email) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Naam en e-mail zijn verplicht']);
            break;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ongeldig e-mailadres']);
            break;
        }

        $leads = loadJson($leadsFile, []);
        $leads[] = [
            'timestamp' => date('c'),
            'naam' => htmlspecialchars($naam, ENT_QUOTES, 'UTF-8'),
            'email' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
            'telefoon' => htmlspecialchars($telefoon, ENT_QUOTES, 'UTF-8'),
            'kavel_id' => $kavelId,
            'bericht' => htmlspecialchars($bericht, ENT_QUOTES, 'UTF-8'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        saveJson($leadsFile, $leads);
        echo json_encode(['ok' => true, 'message' => 'Bedankt! We nemen contact met u op.']);
        break;

    case 'bids':
        // GET all bids or bids for a specific kavel
        $bids = loadJson($bidsFile, []);
        $kavelId = sanitizeId($_GET['kavel_id'] ?? '');
        if ($kavelId) {
            $kavelBids = array_filter($bids, fn($b) => $b['kavel_id'] === $kavelId);
            $kavelBids = array_values($kavelBids);
            usort($kavelBids, fn($a,$b) => $b['bedrag'] - $a['bedrag']);
            $highest = $kavelBids[0]['bedrag'] ?? 0;
            echo json_encode(['ok' => true, 'bids' => $kavelBids, 'highest' => $highest, 'count' => count($kavelBids)]);
        } else {
            // Return highest bid + count per kavel
            $summary = [];
            foreach ($bids as $bid) {
                $kid = $bid['kavel_id'];
                if (!isset($summary[$kid])) $summary[$kid] = ['highest' => 0, 'count' => 0];
                $summary[$kid]['count']++;
                if ($bid['bedrag'] > $summary[$kid]['highest']) $summary[$kid]['highest'] = $bid['bedrag'];
            }
            echo json_encode(['ok' => true, 'summary' => $summary, 'all_bids' => $bids]);
        }
        break;

    case 'bid':
        $kavelId = sanitizeId($_POST['kavel_id'] ?? '');
        $naam    = trim($_POST['naam'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $bedrag  = intval($_POST['bedrag'] ?? 0);
        $telefoon = trim($_POST['telefoon'] ?? '');

        if (!$kavelId || !$naam || !$email || $bedrag <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Kavel, naam, e-mail en bod zijn verplicht']);
            break;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ongeldig e-mailadres']);
            break;
        }
        if ($bedrag < 1000) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Minimum bod is €1.000']);
            break;
        }

        $bids = loadJson($bidsFile, []);

        // Check minimum: must beat current highest by at least €1.000
        $kavelBids = array_filter($bids, fn($b) => $b['kavel_id'] === $kavelId);
        $highest = 0;
        foreach ($kavelBids as $b) { if ($b['bedrag'] > $highest) $highest = $b['bedrag']; }
        if ($highest > 0 && $bedrag < $highest + 1000) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => "Uw bod moet minimaal €" . number_format($highest + 1000, 0, ',', '.') . " zijn"]);
            break;
        }

        $bids[] = [
            'id'        => uniqid(),
            'timestamp' => date('c'),
            'kavel_id'  => $kavelId,
            'naam'      => htmlspecialchars($naam, ENT_QUOTES, 'UTF-8'),
            'email'     => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
            'telefoon'  => htmlspecialchars($telefoon, ENT_QUOTES, 'UTF-8'),
            'bedrag'    => $bedrag,
        ];
        saveJson($bidsFile, $bids);

        // New highest
        $newHighest = max($highest, $bedrag);
        echo json_encode([
            'ok'      => true,
            'message' => 'Uw bod is geregistreerd!',
            'highest' => $newHighest,
            'count'   => count(array_filter($bids, fn($b) => $b['kavel_id'] === $kavelId))
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Onbekende actie. Gebruik: stats, like, share, lead, bid, bids']);
}
