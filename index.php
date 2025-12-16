<?php
// Simple Wichteln registration and reveal script.

declare(strict_types=1);

// Configuration and setup helpers
function loadEnv(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $env = parse_ini_file($path, false, INI_SCANNER_RAW);

    return is_array($env) ? $env : [];
}

function ensureDataDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

function loadParticipants(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $xml = simplexml_load_file($path);
    if ($xml === false) {
        return [];
    }

    $names = [];
    foreach ($xml->participant as $participant) {
        $names[] = (string) $participant;
    }

    return $names;
}

function saveParticipants(string $path, array $names): void
{
    $xml = new SimpleXMLElement('<participants></participants>');
    foreach ($names as $name) {
        $xml->addChild('participant', $name);
    }

    $xml->asXML($path);
}

function generatePairings(array $names): array
{
    $givers = array_values($names);
    $recipientPool = $givers;
    $attempts = 0;
    $maxAttempts = 200;
    $valid = false;
    $pairings = [];

    while ($attempts < $maxAttempts && !$valid) {
        $attempts++;
        shuffle($recipientPool);
        $valid = true;
        foreach ($givers as $index => $giver) {
            if ($giver === $recipientPool[$index]) {
                $valid = false;
                break;
            }
        }
    }

    if (!$valid) {
        // Fallback: rotate list by one to avoid direct matches.
        array_push($recipientPool, array_shift($recipientPool));
    }

    foreach ($givers as $index => $giver) {
        $pairings[$giver] = [$recipientPool[$index]];
    }

    if (count($givers) > 1 && count($givers) % 2 === 1) {
        $extraGiver = $givers[array_rand($givers)];
        $extraRecipientChoices = array_values(array_filter(
            $givers,
            fn(string $name) => $name !== $extraGiver && !in_array($name, $pairings[$extraGiver], true)
        ));

        if (!empty($extraRecipientChoices)) {
            $pairings[$extraGiver][] = $extraRecipientChoices[array_rand($extraRecipientChoices)];
        }
    }

    return $pairings;
}

function savePairings(string $path, array $pairings): void
{
    $xml = new SimpleXMLElement('<pairings></pairings>');
    $xml->addAttribute('generatedAt', (new DateTime())->format(DateTime::ATOM));

    foreach ($pairings as $giver => $recipients) {
        $pairElement = $xml->addChild('pair');
        $pairElement->addAttribute('giver', $giver);
        foreach ($recipients as $recipient) {
            $pairElement->addChild('recipient', $recipient);
        }
    }

    $xml->asXML($path);
}

function loadPairings(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $xml = simplexml_load_file($path);
    if ($xml === false) {
        return [];
    }

    $pairs = [];
    foreach ($xml->pair as $pair) {
        $giver = (string) $pair['giver'];
        $pairs[$giver] = [];
        foreach ($pair->recipient as $recipient) {
            $pairs[$giver][] = (string) $recipient;
        }
    }

    return $pairs;
}

// Paths and environment
$env = loadEnv(__DIR__ . '/.env');
$deadlineString = $env['DEADLINE'] ?? null;
$deadline = null;
$deadlineStatus = 'missing';

if ($deadlineString) {
    try {
        $deadline = new DateTime($deadlineString);
        $deadlineStatus = 'configured';
    } catch (Exception $e) {
        $deadlineStatus = 'invalid';
    }
}

$now = new DateTime('now');
$isBeforeDeadline = $deadline instanceof DateTime ? $now < $deadline : false;

// Data setup
$dataDir = __DIR__ . '/data';
$participantsPath = $dataDir . '/participants.xml';
$pairingsPath = $dataDir . '/pairings.xml';
ensureDataDirectory($dataDir);

$participants = loadParticipants($participantsPath);
$pairings = [];
$messages = [];
$state = $isBeforeDeadline ? 'register' : 'reveal';

// Handle requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';

    if ($state === 'register') {
        if ($deadlineStatus !== 'configured') {
            $messages[] = ['type' => 'error', 'text' => 'Bitte das Enddatum in der .env Datei konfigurieren.'];
        } elseif ($name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Bitte gib einen Namen ein.'];
        } else {
            $normalizedNames = array_map('mb_strtolower', $participants);
            if (in_array(mb_strtolower($name), $normalizedNames, true)) {
                $messages[] = ['type' => 'error', 'text' => 'Dieser Name ist bereits vergeben.'];
            } else {
                $participants[] = $name;
                saveParticipants($participantsPath, $participants);
                $messages[] = ['type' => 'success', 'text' => 'Du bist dabei! Dein Name wurde gespeichert.'];
            }
        }
    } else {
        // Reveal mode
        if ($name === '') {
            $messages[] = ['type' => 'error', 'text' => 'Bitte gib deinen Namen ein, um dein Wichtelkind zu sehen.'];
        } else {
            if (!file_exists($pairingsPath) && count($participants) > 0) {
                $pairings = generatePairings($participants);
                savePairings($pairingsPath, $pairings);
            } else {
                $pairings = loadPairings($pairingsPath);
            }

            $matchedKey = null;
            foreach (array_keys($pairings) as $giver) {
                if (mb_strtolower($giver) === mb_strtolower($name)) {
                    $matchedKey = $giver;
                    break;
                }
            }

            if ($matchedKey === null) {
                $messages[] = ['type' => 'error', 'text' => 'Dein Name wurde nicht gefunden.'];
            } else {
                $assignments = $pairings[$matchedKey] ?? [];
                if (empty($assignments)) {
                    $messages[] = ['type' => 'error', 'text' => 'Dir wurde noch niemand zugewiesen.'];
                } else {
                    $recipientText = count($assignments) > 1 ? 'Diese Personen beschenkst du:' : 'Diese Person beschenkst du:';
                    $details = '<ul class="assignment-list">';
                    foreach ($assignments as $recipient) {
                        $details .= '<li>' . htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8') . '</li>';
                    }
                    $details .= '</ul>';
                    $messages[] = ['type' => 'success', 'html' => $recipientText . $details];
                }
            }
        }
    }
}

if ($state === 'reveal' && empty($pairings) && file_exists($pairingsPath)) {
    $pairings = loadPairings($pairingsPath);
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="Rich BBQ.png">
    <meta property="og:title" content="Grilloutine Wichteln">
    <meta property="og:image" content="Rich BBQ.png">
    <meta property="og:type" content="website">
    <meta property="og:description" content="Grilloutine Wichteln - Die lustige Wichtelaktion für alle Grillfreunde!">
    <meta property="og:url" content="https://yourwebsite.com">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Grilloutine Wichteln">
    <meta name="twitter:image" content="Rich BBQ.png">
    <meta name="twitter:description" content="Grilloutine Wichteln - Die lustige Wichtelaktion für alle Grillfreunde!">
    <title>Grilloutine Wichteln</title>
    <style>
        :root {
            --bg: #5a0f2d;
            --gold: #d4af37;
            --card: rgba(255, 255, 255, 0.06);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Trebuchet MS', 'Segoe UI', sans-serif;
            background: radial-gradient(circle at top, #701224 0%, var(--bg) 45%, #901224 100%);
            color: var(--gold);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            width: min(900px, 92vw);
            padding: 1.5rem;
            background: linear-gradient(135deg, rgba(255,255,255,0.08), rgba(255,255,255,0.02));
            border: 1px solid rgba(212, 175, 55, 0.4);
            border-radius: 28px;
            box-shadow: 0 15px 45px rgba(0,0,0,0.35);
            position: relative;
            overflow: hidden;
        }

        .container::before, .container::after {
            content: '';
            position: absolute;
            background: radial-gradient(circle, rgba(212,175,55,0.25), transparent 60%);
            filter: blur(8px);
            animation: float 12s ease-in-out infinite;
        }

        .container::before {
            width: 220px;
            height: 220px;
            top: -60px;
            left: -40px;
        }

        .container::after {
            width: 180px;
            height: 180px;
            bottom: -40px;
            right: -30px;
            animation-delay: -4s;
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-12px); }
            100% { transform: translateY(0px); }
        }

        .logo {
            width: min(500px, 80vw);
            height: min(500px, 80vw);
            border-radius: 50%;
 
            margin: 0 auto 1rem auto;
            border: 2px solid rgba(212,175,55,0.65);
            display: grid;
            place-items: center;
            background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.2), rgba(212,175,55,0.15));
            box-shadow: 0 10px 30px rgba(0,0,0,0.4);
            animation: glow 6s ease-in-out infinite;
        }

        .logo span {
            font-size: 2.8rem;
            letter-spacing: 0.12em;
            font-weight: 700;
            text-transform: uppercase;
        }

        @keyframes glow {
            0% { box-shadow: 0 10px 30px rgba(212,175,55,0.2); }
            50% { box-shadow: 0 10px 40px rgba(212,175,55,0.45); }
            100% { box-shadow: 0 10px 30px rgba(212,175,55,0.2); }
        }

        h1 {
            text-align: center;
            margin: 0 0 1.5rem 0;
            letter-spacing: 0.08em;
            font-size: 2.1rem;
        }

        .panel {
            background: var(--card);
            border: 1px solid rgba(212, 175, 55, 0.3);
            border-radius: 18px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            backdrop-filter: blur(4px);
            animation: fadeIn 0.8s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .status .badge {
            padding: 0.4rem 0.75rem;
            border-radius: 999px;
            border: 1px solid rgba(212,175,55,0.4);
            background: rgba(212,175,55,0.12);
            font-size: 0.95rem;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.08);
        }

        form {
            display: grid;
            gap: 0.75rem;
            margin-top: 0.5rem;
        }

        label { font-weight: 600; letter-spacing: 0.04em; }

        input[type="text"] {
            padding: 0.9rem 1rem;
            border-radius: 12px;
            border: 1px solid rgba(212,175,55,0.35);
            background: rgba(255,255,255,0.08);
            color: var(--gold);
            font-size: 1rem;
            transition: border-color 0.3s ease, transform 0.2s ease;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: rgba(255, 217, 102, 0.9);
            transform: translateY(-1px);
        }

        button {
            padding: 0.95rem 1.2rem;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, #f1d17a, #c9a12f);
            color: #3d1c13;
            font-weight: 800;
            letter-spacing: 0.06em;
            cursor: pointer;
            box-shadow: 0 10px 20px rgba(0,0,0,0.35);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        button:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(0,0,0,0.4); }
        button:active { transform: translateY(0); }

        .messages { display: grid; gap: 0.6rem; margin-top: 0.75rem; }

        .message {
            padding: 0.9rem 1rem;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.16);
            animation: fadeIn 0.6s ease;
        }

        .message.success { border-color: rgba(155, 216, 110, 0.4); background: rgba(36, 150, 92, 0.18); }
        .message.error { border-color: rgba(255, 100, 100, 0.4); background: rgba(179, 52, 52, 0.2); }

        .assignment-list { margin: 0.6rem 0 0 0; padding-left: 1.2rem; }

        .note { font-size: 0.95rem; opacity: 0.85; }

        .footer { text-align: center; margin-top: 1.5rem; font-size: 0.95rem; opacity: 0.8; }
    </style>
</head>
<body>
<div class="container">
    <div class="logo"><span>
        <img src="Rich BBQ.png" alt="Rich BBQ Logo" style="max-width: 100%; max-height: min(400px, 75vw);">
    </span></div>

    <h1>Grilloutine Wichteln</h1>

    <div class="panel status">
        <div>
            <div>Heutiges Datum: <strong><?= htmlspecialchars($now->format('d.m.Y H:i')) ?></strong></div>
            <div>Deadline: <strong><?= $deadline instanceof DateTime ? htmlspecialchars($deadline->format('d.m.Y H:i')) : 'nicht gesetzt' ?></strong></div>
        </div>
        <div class="badge">
            <?= $state === 'register' ? 'Anmeldung offen' : 'Auslosung gestartet' ?>
        </div>
    </div>

    <?php if ($deadlineStatus === 'missing' || $deadlineStatus === 'invalid'): ?>
        <div class="panel">
            <p class="note">Bitte lege eine <code>.env</code> Datei im Projektverzeichnis an und setze <code>DEADLINE=YYYY-MM-DD HH:MM</code>.</p>
        </div>
    <?php endif; ?>

    <?php if ($state === 'register'): ?>
        <div class="panel">
            <h2>Anmeldung</h2>
            <form method="post" autocomplete="off">
                <label for="name">Dein Nickname</label>
                <input type="text" id="name" name="name" placeholder="z.B. Sternenlicht" required>
                <button type="submit">Name speichern</button>
            </form>
            <p class="note">Bereits angemeldete Personen: <?= count($participants) ?>.</p>
        </div>
    <?php else: ?>
        <div class="panel">
            <h2>Dein Wichtelkind</h2>
            <form method="post" autocomplete="off">
                <label for="name">Dein Nickname</label>
                <input type="text" id="name" name="name" placeholder="Dein gespeicherter Name" required>
                <button type="submit">Anzeigen</button>
            </form>
            <p class="note">Die Auslosung erfolgt automatisch beim ersten Besuch nach der Deadline.</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($messages)): ?>
        <div class="messages">
            <?php foreach ($messages as $message): ?>
                <div class="message <?= $message['type'] ?? 'info' ?>">
                    <?php if (isset($message['html'])): ?>
                        <?= $message['html'] ?>
                    <?php else: ?>
                        <?= htmlspecialchars($message['text'] ?? '') ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="footer"> Grillchill Edition! Viel Spaß beim wichteln!</div>
</div>
</body>
</html>
