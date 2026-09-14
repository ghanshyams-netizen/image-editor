<?php
/*
 * image-editor-png.php
 *
 * TEST FLOW (MULTI-IMAGE):
 * 1. Put this file and any number of images (jpg/jpeg/png/gif/webp)
 *    in the same folder. Every image in the folder is auto-detected
 *    and shown as its own card - no code change needed to add more.
 * 2. Open this PHP file through XAMPP/WAMP.
 * 3. First screen shows a gallery of every image found in this folder.
 * 4. Click any image -> editor opens for THAT image only.
 * 5. Only ONE editable object can exist per image (each image has its own).
 * 6. Save -> editor closes and that image's object data is saved in
 *    editor-data.json, keyed by filename, so every image keeps its own
 *    design without overwriting the others.
 * 7. Click an image again -> its own saved object is loaded and can be edited.
 * 8. Dynamic generation demo: pick the image + enter a value for {{NAME}}
 *    without manually editing.
 * 9. Generate Final PNG -> saved design settings for the selected image are
 *    reused and only the value changes.
 *
 * No database is used. All saved designs are stored in editor-data.json,
 * keyed by image filename.
 */

$dataFile = __DIR__ . DIRECTORY_SEPARATOR . 'editor-data.json';

// Auto-detect every image file sitting next to this script.
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$images = [];

foreach (scandir(__DIR__) as $file) {

    if ($file === '.' || $file === '..') {
        continue;
    }

    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . $file;

    if (!is_file($fullPath)) {
        continue;
    }

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if (in_array($extension, $allowedExtensions, true)) {
        $images[] = $file;
    }
}

natcasesort($images);
$images = array_values($images);

$defaultImage = $images[0] ?? null;

function loadAllEditorData($dataFile) {

    if (!is_file($dataFile)) {
        return [];
    }

    $raw = @file_get_contents($dataFile);
    $decoded = json_decode($raw ?: '', true);

    if (!is_array($decoded)) {
        return [];
    }

    // Backward compatibility: older versions of this file stored a single
    // design directly (not keyed by image name). Migrate it automatically.
    if (isset($decoded['object']) || isset($decoded['image'])) {
        $legacyImage = $decoded['image'] ?? null;
        return $legacyImage ? [$legacyImage => $decoded] : [];
    }

    return $decoded;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    header('Content-Type: application/json; charset=utf-8');

    $raw = $_POST['data'] ?? '';
    $imageName = basename((string) ($_POST['image'] ?? ''));
    $decoded = json_decode($raw, true);

    if (!is_array($decoded) || $imageName === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid editor JSON or missing image name.'
        ]);
        exit;
    }

    $decoded['image'] = $imageName;
    $decoded['saved_at'] = date('Y-m-d H:i:s');

    $allData = loadAllEditorData($dataFile);
    $allData[$imageName] = $decoded;

    $ok = @file_put_contents(
        $dataFile,
        json_encode(
            $allData,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );

    if ($ok === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Could not save editor-data.json. Check folder permissions.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Design saved successfully.'
    ]);
    exit;
}

$savedDataByImage = loadAllEditorData($dataFile);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🖼️ Image Editor Studio</title>

<script src="https://unpkg.com/konva@10/konva.min.js"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap');

:root {
    --bg-1: #eef2ff;
    --bg-2: #f5f3ff;
    --ink: #1e1b2e;
    --muted: #6b7280;
    --border: #e5e7eb;
    --card: #ffffff;
    --brand: #6366f1;
    --brand-2: #8b5cf6;
    --brand-dark: #4f46e5;
    --success: #10b981;
    --success-dark: #059669;
    --danger: #ef4444;
    --danger-dark: #dc2626;
    --radius-lg: 18px;
    --radius-md: 12px;
    --radius-sm: 8px;
    --shadow-soft: 0 10px 30px rgba(79, 70, 229, .10);
    --shadow-card: 0 18px 45px rgba(30, 27, 46, .10);
}

* {
    box-sizing: border-box;
}

::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}

::-webkit-scrollbar-track {
    background: transparent;
}

::-webkit-scrollbar-thumb {
    background: #d4d4f7;
    border-radius: 20px;
    border: 2px solid transparent;
    background-clip: content-box;
}

::-webkit-scrollbar-thumb:hover {
    background: #b8b8f2;
    background-clip: content-box;
}

body {
    margin: 0;
    font-family: 'Inter', Arial, sans-serif;
    color: var(--ink);
    background:
        radial-gradient(1100px 500px at 8% -10%, #e0e7ff 0%, transparent 60%),
        radial-gradient(900px 500px at 100% 0%, #ede9fe 0%, transparent 55%),
        linear-gradient(180deg, var(--bg-1) 0%, var(--bg-2) 100%);
    min-height: 100vh;
}

/* =========================
   IMAGE LIST / FIRST SCREEN
   ========================= */

#imageScreen {
    min-height: 100vh;
    padding: 48px 40px 60px;
}

.page-title {
    margin: 0 0 6px;
    font-family: 'Poppins', 'Inter', sans-serif;
    font-size: 32px;
    font-weight: 800;
    letter-spacing: -.02em;
    background: linear-gradient(90deg, #e57146, #f6a75c 60%, #d946ef);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    text-align: center;
}

.page-subtitle {
    text-align: center;
    color: var(--muted);
    font-size: 14.5px;
    margin: 0 0 30px;
}

.dynamic-generator-card {
    width: min(760px, 92vw);
    margin: 0 auto 26px;
    background: linear-gradient(180deg, #ffffff 0%, #fbfaff 100%);
    border: 1px solid #eceafc;
    border-radius: var(--radius-lg);
    padding: 22px 24px;
    box-shadow: var(--shadow-card);
    position: relative;
    overflow: hidden;
}

.dynamic-generator-card::before {
    content: "";
    position: absolute;
    inset: 0 0 auto 0;
    height: 4px;
        background: linear-gradient(135deg, #dbf163, #5cf68a);
    box-shadow: 0 6px 16px rgba(99, 102, 241, .35);
}

.dynamic-generator-card .section-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    letter-spacing: .06em;
    color: var(--brand-dark);
}

.dynamic-generator-card .section-title::before {
    content: "\2728";
    font-size: 14px;
}

.dynamic-generator-card input {
    width: 100%;
    padding: 11px 13px;
    border: 1.5px solid #e2e0fb;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-family: inherit;
    background: #fbfbff;
    transition: border-color .15s, box-shadow .15s, background .15s;
}

.dynamic-generator-card input:focus {
    outline: none;
    border-color: var(--brand);
    background: #fff;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, .12);
}

.image-card {
    width: 300px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 12px;
    margin: 0 auto;
    cursor: pointer;
    box-shadow: var(--shadow-card);
    transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    position: relative;
}

.image-card::after {
    content: "\1F58C\FE0F  Click to edit";
    position: absolute;
    top: 20px;
    right: 20px;
    background: rgba(17, 24, 39, .78);
    color: #fff;
    font-size: 11.5px;
    font-weight: 600;
    padding: 6px 11px;
    border-radius: 999px;
    opacity: 0;
    transform: translateY(-4px);
    transition: opacity .2s ease, transform .2s ease;
    pointer-events: none;
}

.image-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 22px 48px rgba(79, 70, 229, .18);
    border-color: #ddd8fb;
}

.image-card:hover::after {
    opacity: 1;
    transform: translateY(0);
}

.image-card img {
    display: block;
    width: 100%;
    height: 220px;
    object-fit: contain;
    background:
        linear-gradient(45deg, #f3f4f8 25%, transparent 25%) 0 0/16px 16px,
        linear-gradient(-45deg, #f3f4f8 25%, transparent 25%) 0 0/16px 16px,
        linear-gradient(45deg, transparent 75%, #f3f4f8 75%) 0 0/16px 16px,
        linear-gradient(-45deg, transparent 75%, #f3f4f8 75%) 0 0/16px 16px,
        #fcfcff;
    border-radius: var(--radius-md);
}

.image-name {
    padding: 14px 6px 6px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--ink);
}

.image-name::before {
    content: "\1F5BC\FE0F";
    font-size: 15px;
}

.image-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 22px;
    justify-content: center;
    max-width: 1100px;
    margin: 0 auto;
}

.empty-state {
    width: min(760px, 92vw);
    margin: 40px auto;
    text-align: center;
    color: var(--muted);
    background: var(--card);
    border: 1px dashed #d7d4f5;
    border-radius: var(--radius-lg);
    padding: 40px 20px;
}

.dynamic-generator-card select {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid #e2e0fb;
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-family: inherit;
    background: #fbfbff;
    cursor: pointer;
    transition: border-color .15s, box-shadow .15s;
}

.dynamic-generator-card select:focus {
    outline: none;
    border-color: var(--brand);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, .12);
}

/* =========================
   EDITOR MODAL
   ========================= */

#editorScreen {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(23, 20, 41, .78);
    backdrop-filter: blur(4px);
    z-index: 1000;
    animation: fadeIn .18s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes popIn {
    from { opacity: 0; transform: translateY(10px) scale(.985); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.editor-window {
    width: min(1250px, 96vw);
    height: min(850px, 94vh);
    margin: 3vh auto;
    background: #fff;
    border-radius: 20px;
    overflow: hidden;
    display: grid;
    grid-template-rows: 62px 1fr;
    box-shadow: 0 30px 90px rgba(0,0,0,.4);
    animation: popIn .22s cubic-bezier(.2,.8,.3,1);
}

.editor-header {
    background: linear-gradient(120deg, #1b1533 0%, #241c46 55%, #2f1f52 100%);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 18px;
}

.editor-title {
    font-weight: 700;
    font-family: 'Poppins', sans-serif;
    display: flex;
    align-items: center;
    gap: 9px;
    letter-spacing: .01em;
}

.editor-title::before {
    content: "\1F3A8";
    font-size: 17px;
}

.header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

button {
    border: 0;
    border-radius: 9px;
    padding: 9px 14px;
    cursor: pointer;
    background: #383154;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    font-family: 'Inter', sans-serif;
    transition: background .15s ease, transform .1s ease, box-shadow .15s ease;
}

button:hover {
    background: #4a4270;
    transform: translateY(-1px);
}

button:active {
    transform: translateY(0);
}

.primary {
        background: linear-gradient(135deg, #dbf163, #5cf68a);
    box-shadow: 0 6px 16px rgba(99, 102, 241, .35);
}

.primary:hover {
    background: linear-gradient(135deg, #5457e5, #7c4ff0);
    box-shadow: 0 8px 20px rgba(99, 102, 241, .45);
}

.success {
    background: linear-gradient(135deg, #10b981, #059669);
    box-shadow: 0 6px 16px rgba(16, 185, 129, .35);
}

.success:hover {
    background: linear-gradient(135deg, #14c98c, #047857);
    box-shadow: 0 8px 20px rgba(16, 185, 129, .4);
}

.danger {
    background: linear-gradient(135deg, #f87171, #dc2626);
    box-shadow: 0 6px 16px rgba(220, 38, 38, .3);
}

.danger:hover {
    background: linear-gradient(135deg, #fb8585, #b91c1c);
    box-shadow: 0 8px 20px rgba(220, 38, 38, .4);
}

.editor-body {
    min-height: 0;
    display: grid;
    grid-template-columns: 200px minmax(450px, 1fr) 270px;
    background: #f6f5fb;
}

.toolbar {
    padding: 18px 16px;
    border-right: 1px solid var(--border);
    background: #fff;
    overflow-y: auto;
}

.canvas-area {
    min-width: 0;
    min-height: 0;
    overflow: auto;
    padding: 30px;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background:
        linear-gradient(45deg, #e9e8f5 25%, transparent 25%) 0 0/22px 22px,
        linear-gradient(-45deg, #e9e8f5 25%, transparent 25%) 0 0/22px 22px,
        linear-gradient(45deg, transparent 75%, #e9e8f5 75%) 0 0/22px 22px,
        linear-gradient(-45deg, transparent 75%, #e9e8f5 75%) 0 0/22px 22px,
        #f7f6fc;
}

#stage-container {
    background: #fff;
    box-shadow: 0 16px 40px rgba(30, 27, 46, .22);
    display: inline-block;
    border-radius: 4px;
    overflow: hidden;
}

.properties {
    padding: 18px 16px;
    border-left: 1px solid var(--border);
    overflow-y: auto;
    background: #fff;
}

.section-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #7c7794;
    margin-bottom: 12px;
}

.tool-button {
    width: 100%;
    margin-bottom: 8px;
    background: linear-gradient(180deg, #f2f1fd, #eae7fc);
    color: #362f5c;
    border: 1px solid #e1defa;
    text-align: left;
    padding: 10px 14px;
}

.tool-button:hover {
    background: linear-gradient(180deg, #e7e4fb, #ddd9f9);
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(99, 102, 241, .15);
}

label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: #565a6b;
    margin: 10px 0 5px;
}

input[type="text"],
input[type="number"],
select,
input[type="color"] {
    width: 100%;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 8px 9px;
    background: #fff;
    font-family: inherit;
    font-size: 13.5px;
    transition: border-color .15s, box-shadow .15s;
}

input[type="text"]:focus,
input[type="number"]:focus,
select:focus {
    outline: none;
    border-color: var(--brand);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, .15);
}

input[type="color"] {
    height: 38px;
    padding: 3px;
    cursor: pointer;
}

input[type="range"] {
    width: 100%;
    accent-color: var(--brand);
    cursor: pointer;
}

input[type="checkbox"] {
    accent-color: var(--brand);
    width: 15px;
    height: 15px;
    cursor: pointer;
}

.row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
}

.check-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.check-row label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0;
    font-size: 12.5px;
    cursor: pointer;
}

.hint {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.55;
}

.status {
    margin-top: 9px;
    min-height: 18px;
    font-size: 12.5px;
    font-weight: 600;
}

.hidden {
    display: none !important;
}

.zoom-row {
    display: flex;
    gap: 6px;
    align-items: center;
}

.zoom-row button {
    flex: 1;
    padding: 8px 6px;
}

.zoom-value {
    width: 60px;
    text-align: center;
    font-size: 12.5px;
    font-weight: 700;
    color: #4b5563;
}

hr {
    border: 0;
    border-top: 1px solid var(--border);
    margin: 18px 0;
}

@media (max-width: 1000px) {
    .editor-body {
        grid-template-columns: 180px minmax(350px, 1fr);
    }

    .properties {
        grid-column: 1 / -1;
        border-left: 0;
        border-top: 1px solid var(--border);
    }

    .editor-window {
        height: 96vh;
        margin: 2vh auto;
    }
}

@media (max-width: 700px) {
    #imageScreen {
        padding: 24px 16px;
    }

    .page-title {
        font-size: 24px;
    }

    .image-card {
        width: 100%;
        max-width: 350px;
    }

    .editor-body {
        display: block;
        overflow-y: auto;
    }

    .toolbar,
    .properties {
        border: 0;
        border-bottom: 1px solid var(--border);
    }

    .canvas-area {
        min-height: 650px;
    }
}
</style>
</head>

<body>

<!-- ==========================================
     SCREEN 1: DEFAULT IMAGE
     ========================================== -->

<div id="imageScreen">

    <h1 class="page-title">My Images</h1>
    <p class="page-subtitle">Design once, generate unlimited personalized PNGs in seconds</p>

    <div class="dynamic-generator-card">

        <div class="section-title">Dynamic PNG Generator</div>

        <p class="hint" style="margin-top:0;">
            Example: save <strong>{{NAME}}</strong> in the editor, then generate a PNG
            by changing only the value. Position, font, background, rotation and other
            saved settings remain unchanged.
        </p>

        <label>Image</label>
        <select id="dynamicImageSelect" onchange="onDynamicImageChange()">
            <?php foreach ($images as $imgFile): ?>
            <option value="<?= htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8') ?>
            </option>
            <?php endforeach; ?>
        </select>

        <div class="row" style="margin-top:9px;">
            <div>
                <label>Dynamic key</label>
                <input id="dynamicKeyInput" type="text" value="NAME" placeholder="NAME">
            </div>
            <div>
                <label>Value</label>
                <input id="dynamicValueInput" type="text" value="Ghanshyam Sharma" placeholder="Enter value">
            </div>
        </div>

        <button
            class="primary"
            style="width:100%;margin-top:10px"
            onclick="generateDynamicPNG()"
        >
            ⚡ Generate Final PNG
        </button>

        <div id="generatorStatus" class="status"></div>

    </div>


    <div id="savedJsonOutputWrap" style="max-width:1200px;margin:24px auto;">
        <div class="section-title">Saved JSON</div>
        <pre id="savedJsonOutput" class="hidden" style="background:#111827;color:#e5e7eb;padding:16px;border-radius:10px;overflow:auto;white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.5;"></pre>
    </div>

    <?php if (empty($images)): ?>

    <div class="empty-state">
        No images found in this folder. Add a .jpg, .jpeg, .png, .gif or .webp
        file next to this PHP file and refresh the page.
    </div>

    <?php else: ?>

    <div class="image-grid">

        <?php foreach ($images as $imgFile): ?>
        <div class="image-card" onclick="openEditor('<?= htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8') ?>')">

            <img
                src="<?= htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8') ?>"
                alt="<?= htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8') ?>"
                onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('beforeend', '<div style=&quot;padding:50px 10px;text-align:center;color:#dc2626&quot;><?= htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8') ?> not found</div>');"
            >

            <div class="image-name">
                <?= htmlspecialchars($imgFile, ENT_QUOTES, 'UTF-8') ?>
            </div>

        </div>
        <?php endforeach; ?>

    </div>

    <?php endif; ?>

</div>


<!-- ==========================================
     SCREEN 2: EDITOR
     ========================================== -->

<div id="editorScreen">

    <div class="editor-window">

        <div class="editor-header">

            <div class="editor-title">
                Edit: <span id="editorImageTitle">-</span>
            </div>

            <div class="header-actions">
                <button onclick="closeEditor()">✕ Close</button>
                <button onclick="saveDesign()" class="success">💾 Save & Close</button>
                <button onclick="exportPNG()" class="primary">⬇ Export Final PNG</button>
            </div>

        </div>

        <div class="editor-body">

            <!-- LEFT TOOLBAR -->
            <aside class="toolbar">

                <div class="section-title">
                    Object
                </div>

                <button
                    id="addTextButton"
                    class="tool-button"
                    onclick="addText()"
                >
                    🔤 Text
                </button>

                <button
                    id="addRectButton"
                    class="tool-button"
                    onclick="addRect()"
                >
                    ▭ Rectangle
                </button>

                <button
                    id="addCircleButton"
                    class="tool-button"
                    onclick="addCircle()"
                >
                    ⬤ Circle
                </button>

                <button
                    id="addStarButton"
                    class="tool-button"
                    onclick="addStar()"
                >
                    ★ Star
                </button>

                <div
                    id="objectLimitMessage"
                    class="hint"
                    style="margin-top:10px;"
                >
                    Only one object is allowed per image.
                </div>

                <hr style="border:0;border-top:1px solid #e5e7eb;margin:18px 0;">

                <div class="section-title">
                    Zoom
                </div>

                <div class="zoom-row">
                    <button onclick="zoomOut()">−</button>
                    <div id="zoomValue" class="zoom-value">100%</div>
                    <button onclick="zoomIn()">+</button>
                </div>

                <button
                    style="width:100%;margin-top:7px"
                    onclick="resetZoom()"
                >
                    ⟲ Reset
                </button>

                <div id="status" class="status"></div>

            </aside>


            <!-- CANVAS -->
            <main class="canvas-area">

                <div id="stage-container"></div>

            </main>


            <!-- RIGHT PROPERTIES -->
            <aside class="properties">

                <div class="section-title">
                    Image Adjustments
                </div>

                <label>Brightness</label>
                <input id="imgBrightness" type="range" min="-1" max="1" step="0.05" value="0" oninput="updateImageFilters()">

                <label>Contrast</label>
                <input id="imgContrast" type="range" min="-100" max="100" step="1" value="0" oninput="updateImageFilters()">

                <label>Blur</label>
                <input id="imgBlur" type="range" min="0" max="30" step="1" value="0" oninput="updateImageFilters()">

                <div class="check-row">
                    <label>
                        <input id="imgGrayscale" type="checkbox" onchange="updateImageFilters()">
                        Grayscale
                    </label>
                    <label>
                        <input id="imgSepia" type="checkbox" onchange="updateImageFilters()">
                        Sepia
                    </label>
                </div>

                <div class="row" style="margin-top:10px;">
                    <button onclick="resetImageFilters()">⟲ Reset Image</button>
                    <button onclick="rotateImage90()">↻ Rotate 90°</button>
                </div>

                <hr style="border:0;border-top:1px solid #e5e7eb;margin:18px 0;">

                <div class="section-title">
                    Selected Object
                </div>

                <div id="noSelection" class="hint">
                    Add one object or select the existing object.
                </div>

                <div id="propertiesForm" class="hidden">

                    <div id="textProperties">

                        <label>Text</label>

                        <input
                            id="propText"
                            type="text"
                            oninput="updateTextProp()"
                        >

                        <label>Dynamic key (optional)</label>

                        <input
                            id="propDynamicKey"
                            type="text"
                            placeholder="NAME"
                            oninput="updateTextProp()"
                        >

                        <div class="hint" style="margin-top:6px;">
                            Use <strong>{{NAME}}</strong> as the text or set the key here.
                        </div>

                        <div class="row">

                            <div>
                                <label>Font family</label>

                                <select
                                    id="propFontFamily"
                                    onchange="updateTextProp()"
                                >
                                    <option value="Arial">Arial</option>
                                    <option value="Verdana">Verdana</option>
                                    <option value="Tahoma">Tahoma</option>
                                    <option value="Georgia">Georgia</option>
                                    <option value="Times New Roman">Times New Roman</option>
                                    <option value="Courier New">Courier New</option>
                                    <option value="Trebuchet MS">Trebuchet MS</option>
                                    <option value="Impact">Impact</option>
                                    <option value="Comic Sans MS">Comic Sans MS</option>
                                </select>
                            </div>

                            <div>
                                <label>Font size</label>

                                <div style="display:grid;grid-template-columns:1fr 72px;gap:7px;align-items:center;">
                                    <input id="propFontSizeRange" type="range" min="8" max="180" step="1" value="42" oninput="syncFontSizeFromRange()">
                                    <input id="propFontSize" type="number" min="8" max="180" step="1" value="42" oninput="syncFontSizeFromNumber()">
                                </div>
                            </div>

                        </div>

                        <label>Text color</label>

                        <input
                            id="propFill"
                            type="color"
                            onchange="updateTextProp()"
                        >

                        <div class="row">
                            <div>
                                <label>Text background</label>
                                <input
                                    id="propTextBg"
                                    type="color"
                                    onchange="updateTextProp()"
                                >
                            </div>
                            <div>
                                <label>Background padding</label>
                                <input
                                    id="propTextPadding"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="1"
                                    oninput="updateTextProp()"
                                >
                            </div>
                        </div>

                        <div class="check-row">
                            <label>
                                <input
                                    id="propTextBgEnabled"
                                    type="checkbox"
                                    onchange="updateTextProp()"
                                >
                                Background
                            </label>
                        </div>

                        <div style="margin-top:10px;">
                            <label>Font style</label>
                            <select id="propFontStyle" onchange="updateTextProp()">
                                <option value="normal">Normal</option>
                                <option value="bold">Bold</option>
                                <option value="italic">Italic</option>
                                <option value="bold italic">Bold Italic</option>
                            </select>
                        </div>

                        <div class="check-row">
                            <label>
                                <input
                                    id="propUnderline"
                                    type="checkbox"
                                    onchange="updateTextProp()"
                                >
                                Underline
                            </label>
                        </div>

                        <label>Text align</label>

                        <select
                            id="propAlign"
                            onchange="updateTextProp()"
                        >
                            <option value="left">Left</option>
                            <option value="center">Center</option>
                            <option value="right">Right</option>
                        </select>

                    </div>


                    <div id="shapeProperties" class="hidden">

                        <label>Shape fill</label>

                        <input
                            id="propShapeFill"
                            type="color"
                            onchange="updateShapeProp()"
                        >

                        <div class="row">

                            <div>
                                <label>Stroke</label>

                                <input
                                    id="propStroke"
                                    type="color"
                                    onchange="updateShapeProp()"
                                >
                            </div>

                            <div>
                                <label>Stroke width</label>

                                <input
                                    id="propStrokeWidth"
                                    type="number"
                                    min="0"
                                    step="1"
                                    oninput="updateShapeProp()"
                                >
                            </div>

                        </div>

                    </div>


                    <div class="row">

                        <div>
                            <label>Opacity</label>

                            <input
                                id="propOpacity"
                                type="number"
                                min="0"
                                max="1"
                                step="0.05"
                                oninput="updateCommonProp()"
                            >
                        </div>

                        <div>
                            <label>Rotation</label>

                            <input
                                id="propRotation"
                                type="number"
                                step="1"
                                oninput="updateRotationProp()"
                            >
                        </div>

                    </div>


                    <button
                        style="width:100%;margin-top:12px"
                        onclick="deleteSelected()"
                        class="danger"
                    >
                        🗑 Delete Object
                    </button>

                </div>

            </aside>

        </div>

    </div>

</div>


<script>
/*
|--------------------------------------------------------------------------
| PHP DATA
|--------------------------------------------------------------------------
*/

const IMAGES = <?= json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

let savedDataByImage = <?= json_encode(
    $savedDataByImage,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

// Name of the image currently open in the editor.
let currentImage = null;

// Saved design data for currentImage (kept in sync with savedDataByImage).
let savedData = null;


/*
|--------------------------------------------------------------------------
| EDITOR VARIABLES
|--------------------------------------------------------------------------
*/

let stage = null;
let backgroundLayer = null;
let objectLayer = null;
let transformer = null;

let backgroundImageNode = null;
let selectedNode = null;

let zoom = 1;

const DESIGN_WIDTH = 900;
const DESIGN_HEIGHT = 600;


function updateDynamicKeyInputForImage(imageName) {

    const input = document.getElementById('dynamicKeyInput');

    if (!input) {
        return;
    }

    const data = savedDataByImage[imageName];

    if (data && data.object && data.object.attrs) {
        const attrs = data.object.attrs;
        const key = attrs.dynamicKey || inferDynamicKey(attrs.textValue || '');

        if (key) {
            input.value = key;
            return;
        }
    }

    input.value = 'NAME';
}


function onDynamicImageChange() {

    const select = document.getElementById('dynamicImageSelect');

    if (select) {
        updateDynamicKeyInputForImage(select.value);
    }
}


document.addEventListener('DOMContentLoaded', function() {

    const select = document.getElementById('dynamicImageSelect');
    const firstImage = select ? select.value : (IMAGES[0] || null);

    if (firstImage) {
        updateDynamicKeyInputForImage(firstImage);
    }
});


/*
|--------------------------------------------------------------------------
| OPEN / CLOSE EDITOR
|--------------------------------------------------------------------------
*/

function openEditor(imageName) {

    if (!imageName) {
        return;
    }

    currentImage = imageName;
    savedData = savedDataByImage[imageName] || null;

    const titleEl = document.getElementById('editorImageTitle');
    if (titleEl) {
        titleEl.textContent = imageName;
    }

    document.getElementById('editorScreen').style.display = 'block';

    initEditor();

    loadSavedObject();

    updateObjectButtons();
}


function closeEditor() {

    document.getElementById('editorScreen').style.display = 'none';

    if (stage) {
        stage.destroy();
        stage = null;
        backgroundLayer = null;
        objectLayer = null;
        transformer = null;
        backgroundImageNode = null;
        selectedNode = null;
    }
}


/*
|--------------------------------------------------------------------------
| INIT
|--------------------------------------------------------------------------
*/

function initEditor() {

    if (stage) {
        stage.destroy();
    }

    stage = new Konva.Stage({
        container: 'stage-container',
        width: DESIGN_WIDTH,
        height: DESIGN_HEIGHT
    });

    backgroundLayer = new Konva.Layer({
        listening: false
    });

    objectLayer = new Konva.Layer();

    stage.add(backgroundLayer);
    stage.add(objectLayer);

    transformer = new Konva.Transformer({
        rotateEnabled: true,

        enabledAnchors: [
            'top-left',
            'top-center',
            'top-right',
            'middle-left',
            'middle-right',
            'bottom-left',
            'bottom-center',
            'bottom-right'
        ],

        boundBoxFunc: function(oldBox, newBox) {

            if (newBox.width < 10 || newBox.height < 10) {
                return oldBox;
            }

            return newBox;
        }
    });

    objectLayer.add(transformer);

    loadBackgroundImage();

    bindStageEvents();

    setZoom(1);

    selectNode(null);
}


/*
|--------------------------------------------------------------------------
| LOAD DEFAULT IMAGE
|--------------------------------------------------------------------------
*/

function loadBackgroundImage() {

    const img = new Image();

    img.onload = function() {

        const scale = Math.min(
            DESIGN_WIDTH / img.width,
            DESIGN_HEIGHT / img.height
        );

        const width = img.width * scale;
        const height = img.height * scale;

        backgroundImageNode = new Konva.Image({

            image: img,

            x: (DESIGN_WIDTH - width) / 2,

            y: (DESIGN_HEIGHT - height) / 2,

            width: width,

            height: height,

            listening: false,

            offsetX: 0,
            offsetY: 0
        });

        backgroundLayer.add(backgroundImageNode);

        applySavedImageSettings();

        backgroundLayer.batchDraw();

    };

    img.onerror = function() {

        setStatus(
            currentImage + ' not found in the same folder.',
            true
        );
    };

    img.src = currentImage;
}


/*
|--------------------------------------------------------------------------
| STAGE EVENTS
|--------------------------------------------------------------------------
*/

function bindStageEvents() {

    stage.on('click tap', function(e) {

        if (e.target === stage) {
            selectNode(null);
            return;
        }

        if (e.target === transformer) {
            return;
        }

        if (e.target === backgroundImageNode) {
            selectNode(null);
            return;
        }

        selectNode(e.target);
    });


    stage.on('dragend transformend', function(e) {

        if (e.target === transformer) {
            return;
        }

        updatePropertiesPanel();
    });


    stage.on('dblclick dbltap', function(e) {

        const node = e.target;

        if (!node || node === backgroundImageNode) {
            return;
        }

        const textBox =
            node.getAttr('customType') === 'TextBox'
                ? node
                : node.getParent() && node.getParent().getAttr('customType') === 'TextBox'
                    ? node.getParent()
                    : null;

        if (textBox) {

            const current = textBox.getAttr('textValue') || '';

            const value = window.prompt(
                'Edit text:',
                current
            );

            if (value !== null) {

                textBox.setAttr('textValue', value);
                refreshTextBox(textBox);
                selectNode(textBox);
                updatePropertiesPanel();
            }
        } else if (node.getClassName() === 'Text') {

            const current = node.text();

            const value = window.prompt(
                'Edit text:',
                current
            );

            if (value !== null) {
                node.text(value);
                objectLayer.batchDraw();
                updatePropertiesPanel();
            }
        }
    });
}


/*
|--------------------------------------------------------------------------
| ONE OBJECT ONLY
|--------------------------------------------------------------------------
*/

function canAddObject() {

    const count = getObjectNodes().length;

    if (count >= 1) {

        setStatus(
            'Only one object is allowed on this image.',
            true
        );

        return false;
    }

    return true;
}


function getObjectNodes() {

    if (!objectLayer) {
        return [];
    }

    return objectLayer.getChildren().filter(function(node) {

        return node !== transformer;
    });
}


function updateObjectButtons() {

    const hasObject = getObjectNodes().length >= 1;

    const buttons = [
        'addTextButton',
        'addRectButton',
        'addCircleButton',
        'addStarButton'
    ];

    buttons.forEach(function(id) {

        const button = document.getElementById(id);

        if (button) {
            button.disabled = hasObject;
            button.style.opacity = hasObject ? '0.45' : '1';
            button.style.cursor = hasObject
                ? 'not-allowed'
                : 'pointer';
        }
    });
}


/*
|--------------------------------------------------------------------------
| SELECT OBJECT
|--------------------------------------------------------------------------
*/

function selectNode(node) {

    selectedNode = node;

    if (!node) {

        transformer.nodes([]);

    } else {

        transformer.nodes([node]);

        transformer.moveToTop();
    }

    updatePropertiesPanel();
}


/*
|--------------------------------------------------------------------------
| ADD TEXT
|--------------------------------------------------------------------------
*/

function addText() {

    if (!canAddObject()) {
        return;
    }

    const group = createTextBox({
        x: 120,
        y: 100,
        text: '{{NAME}}',
        dynamicKey: 'NAME',
        fontFamily: 'Arial',
        fontSize: 42,
        fontStyle: 'bold',
        textColor: '#ffffff',
        backgroundColor: '#111827',
        backgroundEnabled: true,
        padding: 10,
        cornerRadius: 6
    });

    objectLayer.add(group);

    selectNode(group);

    updateObjectButtons();

    objectLayer.batchDraw();
}

function inferDynamicKey(text) {

    const match = String(text || '').match(/^\{\{\s*([A-Za-z0-9_]+)\s*\}\}$/);

    return match ? match[1].toUpperCase() : '';
}


function replaceDynamicText(node, key, value) {
    if (!node) return false;
    const normalizedKey = String(key || '').trim().toUpperCase();
    if (!normalizedKey) return false;
    const isTextBox = node.getAttr('customType') === 'TextBox';
    const sourceText = String(isTextBox ? node.getAttr('textValue') || '' : node.text() || '');
    const savedDynamicKey = String(isTextBox ? node.getAttr('dynamicKey') || '' : '').trim().toUpperCase();
    const inferredKey = inferDynamicKey(sourceText);
    const matches = savedDynamicKey === normalizedKey || inferredKey === normalizedKey || sourceText.trim().toUpperCase() === normalizedKey;
    if (!matches) return false;
    if (isTextBox) {
        node.setAttr('textValue', String(value == null ? '' : value));
        node.setAttr('dynamicKey', normalizedKey);
        refreshTextBox(node);
    } else if (node.getClassName() === 'Text') {
        node.text(String(value == null ? '' : value));
    } else return false;
    return true;
}


function escapeRegExp(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}


function createTextBox(options) {

    const group = new Konva.Group({
        x: Number(options.x) || 0,
        y: Number(options.y) || 0,
        rotation: Number(options.rotation) || 0,
        scaleX: Number(options.scaleX) || 1,
        scaleY: Number(options.scaleY) || 1,
        opacity: options.opacity == null ? 1 : Number(options.opacity),
        draggable: true,
        name: 'editable-object',
        customType: 'TextBox',
        dynamicKey: String(options.dynamicKey || inferDynamicKey(options.text || '') || ''),
        textValue: options.text || 'Your Text',
        textColor: options.textColor || '#ffffff',
        backgroundColor: options.backgroundColor || '#111827',
        backgroundEnabled: options.backgroundEnabled !== false,
        textPadding: Number(options.padding) || 10,
        cornerRadius: Number(options.cornerRadius) || 6,
        fontFamily: options.fontFamily || 'Arial',
        fontSize: Number(options.fontSize) || 42,
        fontStyle: options.fontStyle || 'bold',
        textDecoration: options.textDecoration || '',
        align: options.align || 'left'
    });

    const textNode = new Konva.Text({
        x: Number(group.getAttr('textPadding') || 0),
        y: Number(group.getAttr('textPadding') || 0),
        text: group.getAttr('textValue') || 'Your Text',
        fontFamily: group.getAttr('fontFamily') || 'Arial',
        fontSize: Number(group.getAttr('fontSize') || 42),
        fontStyle: group.getAttr('fontStyle') || 'normal',
        textDecoration: group.getAttr('textDecoration') || '',
        fill: group.getAttr('textColor') || '#ffffff',
        align: group.getAttr('align') || 'left',
        padding: 0
    });

    const bgNode = new Konva.Rect({
        x: 0,
        y: 0,
        width: textNode.width() + Number(group.getAttr('textPadding') || 0) * 2,
        height: textNode.height() + Number(group.getAttr('textPadding') || 0) * 2,
        fill: group.getAttr('backgroundEnabled') !== false ? group.getAttr('backgroundColor') || '#111827' : 'rgba(0,0,0,0)',
        cornerRadius: Number(group.getAttr('cornerRadius') || 6),
        listening: false
    });

    group.add(bgNode);
    group.add(textNode);

    return group;
}

function getTextChild(group) {
    return group && group.findOne('Text');
}

function getTextBgChild(group) {
    return group && group.findOne('Rect');
}

function refreshTextBox(group) {

    if (!group || group.getAttr('customType') !== 'TextBox') {
        return;
    }

    const textNode = getTextChild(group);
    const bgNode = getTextBgChild(group);

    if (!textNode || !bgNode) {
        return;
    }

    textNode.position({
        x: Number(group.getAttr('textPadding') || 0),
        y: Number(group.getAttr('textPadding') || 0)
    });

    textNode.text(group.getAttr('textValue') || 'Your Text');
    textNode.fontFamily(group.getAttr('fontFamily') || 'Arial');
    textNode.fontSize(Number(group.getAttr('fontSize') || 42));
    textNode.fontStyle(group.getAttr('fontStyle') || 'normal');
    textNode.textDecoration(group.getAttr('textDecoration') || '' || '');
    textNode.fill(group.getAttr('textColor') || '#ffffff');
    textNode.align(group.getAttr('align') || 'left' || 'left');

    bgNode.width(textNode.width() + Number(group.getAttr('textPadding') || 0) * 2);
    bgNode.height(textNode.height() + Number(group.getAttr('textPadding') || 0) * 2);
    bgNode.fill(
        group.getAttr('backgroundEnabled') !== false
            ? group.getAttr('backgroundColor') || '#111827'
            : 'rgba(0,0,0,0)'
    );
    bgNode.cornerRadius(Number(group.getAttr('cornerRadius') || 6));

    objectLayer.batchDraw();
}


/*
|--------------------------------------------------------------------------
| ADD RECTANGLE
|--------------------------------------------------------------------------
*/

function addRect() {

    if (!canAddObject()) {
        return;
    }

    const rect = new Konva.Rect({

        x: 180,

        y: 180,

        width: 220,

        height: 120,

        fill: '#ef4444',

        stroke: '#991b1b',

        strokeWidth: 3,

        cornerRadius: 12,

        draggable: true,

        name: 'editable-object'
    });

    objectLayer.add(rect);

    selectNode(rect);

    updateObjectButtons();

    objectLayer.batchDraw();
}


/*
|--------------------------------------------------------------------------
| ADD CIRCLE
|--------------------------------------------------------------------------
*/

function addCircle() {

    if (!canAddObject()) {
        return;
    }

    const circle = new Konva.Circle({

        x: 300,

        y: 300,

        radius: 70,

        fill: '#22c55e',

        stroke: '#166534',

        strokeWidth: 3,

        draggable: true,

        name: 'editable-object'
    });

    objectLayer.add(circle);

    selectNode(circle);

    updateObjectButtons();

    objectLayer.batchDraw();
}


/*
|--------------------------------------------------------------------------
| ADD STAR
|--------------------------------------------------------------------------
*/

function addStar() {

    if (!canAddObject()) {
        return;
    }

    const star = new Konva.Star({

        x: 450,

        y: 280,

        numPoints: 5,

        innerRadius: 35,

        outerRadius: 70,

        fill: '#f59e0b',

        stroke: '#92400e',

        strokeWidth: 3,

        draggable: true,

        name: 'editable-object'
    });

    objectLayer.add(star);

    selectNode(star);

    updateObjectButtons();

    objectLayer.batchDraw();
}


/*
|--------------------------------------------------------------------------
| DELETE OBJECT
|--------------------------------------------------------------------------
*/

function deleteSelected() {

    if (!selectedNode) {
        return;
    }

    selectedNode.destroy();

    selectedNode = null;

    transformer.nodes([]);

    objectLayer.batchDraw();

    updatePropertiesPanel();

    updateObjectButtons();
}


/*
|--------------------------------------------------------------------------
| PROPERTIES
|--------------------------------------------------------------------------
*/

function updatePropertiesPanel() {

    const node = selectedNode;

    const noSelection =
        document.getElementById('noSelection');

    const form =
        document.getElementById('propertiesForm');

    const textProperties =
        document.getElementById('textProperties');

    const shapeProperties =
        document.getElementById('shapeProperties');

    if (!node) {

        noSelection.classList.remove('hidden');
        form.classList.add('hidden');

        return;
    }

    noSelection.classList.add('hidden');
    form.classList.remove('hidden');

    const isTextBox =
        node.getAttr('customType') === 'TextBox';

    if (isTextBox) {

        textProperties.classList.remove('hidden');
        shapeProperties.classList.add('hidden');

        document.getElementById('propText').value =
            node.getAttr('textValue') || '';

        document.getElementById('propDynamicKey').value =
            node.getAttr('dynamicKey') || inferDynamicKey(node.getAttr('textValue') || '');

        document.getElementById('propFontFamily').value =
            node.getAttr('fontFamily') || 'Arial';

        const currentFontSize = Number(node.getAttr('fontSize') || 42);
        document.getElementById('propFontSize').value = currentFontSize;
        document.getElementById('propFontSizeRange').value = Math.max(8, Math.min(180, currentFontSize));

        document.getElementById('propFill').value =
            normalizeColor(
                node.getAttr('textColor') || '#ffffff',
                '#ffffff'
            );

        document.getElementById('propTextBg').value =
            normalizeColor(
                node.getAttr('backgroundColor') || '#111827',
                '#111827'
            );

        document.getElementById('propTextPadding').value =
            Number(Number(node.getAttr('textPadding') || 0)) || 0;

        document.getElementById('propTextBgEnabled').checked =
            node.getAttr('backgroundEnabled') !== false !== false;

        const textChild = getTextChild(node);
        const style =
            (textChild && textChild.fontStyle()) || node.getAttr('fontStyle') || '';

        document.getElementById('propFontStyle').value =
            style || 'normal';

        document.getElementById('propUnderline').checked =
            ((textChild && textChild.textDecoration()) || node.getAttr('textDecoration') || '').includes('underline');

        document.getElementById('propAlign').value =
            node.getAttr('align') || 'left' || 'left';

    } else {

        textProperties.classList.add('hidden');
        shapeProperties.classList.remove('hidden');

        document.getElementById('propShapeFill').value =
            normalizeColor(
                node.fill(),
                '#cccccc'
            );

        document.getElementById('propStroke').value =
            normalizeColor(
                node.stroke(),
                '#000000'
            );

        document.getElementById('propStrokeWidth').value =
            node.strokeWidth() || 0;
    }

    document.getElementById('propOpacity').value =
        Number(node.opacity()).toFixed(2);

    document.getElementById('propRotation').value =
        Math.round(node.rotation());
}


/*
|--------------------------------------------------------------------------
| TEXT PROPERTIES
|--------------------------------------------------------------------------
*/

function syncFontSizeFromRange() {
    const value = Number(document.getElementById('propFontSizeRange').value) || 42;
    document.getElementById('propFontSize').value = value;
    updateTextProp();
}

function syncFontSizeFromNumber() {
    let value = Number(document.getElementById('propFontSize').value) || 42;
    value = Math.max(8, Math.min(180, value));
    document.getElementById('propFontSize').value = value;
    document.getElementById('propFontSizeRange').value = value;
    updateTextProp();
}

function updateTextProp() {

    const node = selectedNode;

    if (!node || node.getAttr('customType') !== 'TextBox') {
        return;
    }

    const textValue =
        document.getElementById('propText').value;

    node.setAttr('textValue', textValue);

    const enteredDynamicKey = document.getElementById('propDynamicKey').value.trim().toUpperCase();
    const inferredDynamicKey = inferDynamicKey(textValue);
    node.setAttr('dynamicKey', enteredDynamicKey || inferredDynamicKey || '');

    node.setAttr('fontFamily',
        document.getElementById('propFontFamily').value
    );

    node.setAttr('fontSize',
        Number(
            document.getElementById('propFontSize').value
        ) || 1
    );

    node.setAttr('textColor',
        document.getElementById('propFill').value
    );

    node.setAttr('backgroundColor',
        document.getElementById('propTextBg').value
    );

    node.setAttr('textPadding',
        Number(
            document.getElementById('propTextPadding').value
        ) || 0
    );

    node.setAttr('backgroundEnabled',
        document.getElementById('propTextBgEnabled').checked
    );

    const style = document.getElementById('propFontStyle').value || 'normal';

    node.setAttr('fontStyle', style);

    node.setAttr('textDecoration',
        document.getElementById('propUnderline').checked
            ? 'underline'
            : ''
    );

    node.setAttr('align',
        document.getElementById('propAlign').value
    );

    refreshTextBox(node);
    updatePropertiesPanel();
}


/*
|--------------------------------------------------------------------------
| COMMON PROPERTIES
|--------------------------------------------------------------------------
*/

function updateCommonProp() {

    const node = selectedNode;

    if (!node) {
        return;
    }

    node.opacity(
        Number(
            document.getElementById('propOpacity').value
        )
    );

    objectLayer.batchDraw();
}


function updateRotationProp() {

    const node = selectedNode;

    if (!node) {
        return;
    }

    node.rotation(
        Number(
            document.getElementById('propRotation').value
        ) || 0
    );

    objectLayer.batchDraw();
}


/*
|--------------------------------------------------------------------------
| SHAPE PROPERTIES
|--------------------------------------------------------------------------
*/

function updateShapeProp() {

    const node = selectedNode;

    if (
        !node ||
        node.getAttr('customType') === 'TextBox'
    ) {
        return;
    }

    node.fill(
        document.getElementById('propShapeFill').value
    );

    node.stroke(
        document.getElementById('propStroke').value
    );

    node.strokeWidth(
        Number(
            document.getElementById('propStrokeWidth').value
        ) || 0
    );

    objectLayer.batchDraw();
}


/*
|--------------------------------------------------------------------------
| COLOR
|--------------------------------------------------------------------------
*/

function normalizeColor(value, fallback) {

    if (!value || typeof value !== 'string') {
        return fallback;
    }

    if (value.startsWith('#')) {
        return value;
    }

    const match =
        value.match(
            /rgba?\((\d+),\s*(\d+),\s*(\d+)/
        );

    if (match) {

        return '#' + [
            match[1],
            match[2],
            match[3]
        ]
        .map(function(v) {
            return Number(v)
                .toString(16)
                .padStart(2, '0');
        })
        .join('');
    }

    return fallback;
}


/*
|--------------------------------------------------------------------------
| IMAGE ADJUSTMENTS
|--------------------------------------------------------------------------
*/

function getImageFilterState() {

    if (!backgroundImageNode) {
        return {
            brightness: 0,
            contrast: 0,
            blur: 0,
            grayscale: false,
            sepia: false,
            rotation: 0,
            scaleX: 1,
            scaleY: 1
        };
    }

    return {
        brightness: Number(backgroundImageNode.getAttr('imageBrightness') || 0),
        contrast: Number(backgroundImageNode.getAttr('imageContrast') || 0),
        blur: Number(backgroundImageNode.getAttr('imageBlur') || 0),
        grayscale: backgroundImageNode.getAttr('imageGrayscale') === true,
        sepia: backgroundImageNode.getAttr('imageSepia') === true,
        rotation: Number(backgroundImageNode.rotation() || 0),
        scaleX: Number(backgroundImageNode.scaleX() || 1),
        scaleY: Number(backgroundImageNode.scaleY() || 1)
    };
}

function updateImageFilters() {

    if (!backgroundImageNode) {
        return;
    }

    const brightness =
        Number(document.getElementById('imgBrightness').value || 0);

    const contrast =
        Number(document.getElementById('imgContrast').value || 0);

    const blur =
        Number(document.getElementById('imgBlur').value || 0);

    const grayscale =
        document.getElementById('imgGrayscale').checked;

    const sepia =
        document.getElementById('imgSepia').checked;

    backgroundImageNode.setAttr('imageBrightness', brightness);
    backgroundImageNode.setAttr('imageContrast', contrast);
    backgroundImageNode.setAttr('imageBlur', blur);
    backgroundImageNode.setAttr('imageGrayscale', grayscale);
    backgroundImageNode.setAttr('imageSepia', sepia);

    const filters = [];

    if (brightness !== 0) {
        filters.push(Konva.Filters.Brighten);
    }

    if (contrast !== 0) {
        filters.push(Konva.Filters.Contrast);
    }

    if (blur > 0) {
        filters.push(Konva.Filters.Blur);
    }

    if (grayscale) {
        filters.push(Konva.Filters.Grayscale);
    }

    if (sepia) {
        filters.push(Konva.Filters.Sepia);
    }

    backgroundImageNode.filters(filters);
    backgroundImageNode.brightness(brightness);
    backgroundImageNode.contrast(contrast);
    backgroundImageNode.blurRadius(blur);

    if (filters.length) {
        backgroundImageNode.cache();
    } else {
        backgroundImageNode.clearCache();
    }

    backgroundLayer.batchDraw();
}

function applySavedImageSettings() {

    const settings =
        savedData && savedData.imageSettings
            ? savedData.imageSettings
            : {};

    const brightness =
        Number(settings.brightness || 0);

    const contrast =
        Number(settings.contrast || 0);

    const blur =
        Number(settings.blur || 0);

    const grayscale =
        settings.grayscale === true;

    const sepia =
        settings.sepia === true;

    const rotation =
        Number(settings.rotation || 0);

    const scaleX =
        Number(settings.scaleX || 1);

    const scaleY =
        Number(settings.scaleY || 1);

    backgroundImageNode.setAttr('imageBrightness', brightness);
    backgroundImageNode.setAttr('imageContrast', contrast);
    backgroundImageNode.setAttr('imageBlur', blur);
    backgroundImageNode.setAttr('imageGrayscale', grayscale);
    backgroundImageNode.setAttr('imageSepia', sepia);

    backgroundImageNode.rotation(rotation);
    backgroundImageNode.scaleX(scaleX);
    backgroundImageNode.scaleY(scaleY);

    document.getElementById('imgBrightness').value = brightness;
    document.getElementById('imgContrast').value = contrast;
    document.getElementById('imgBlur').value = blur;
    document.getElementById('imgGrayscale').checked = grayscale;
    document.getElementById('imgSepia').checked = sepia;

    updateImageFilters();
}

function resetImageFilters() {

    if (!backgroundImageNode) {
        return;
    }

    backgroundImageNode.rotation(0);
    backgroundImageNode.scaleX(1);
    backgroundImageNode.scaleY(1);

    document.getElementById('imgBrightness').value = 0;
    document.getElementById('imgContrast').value = 0;
    document.getElementById('imgBlur').value = 0;
    document.getElementById('imgGrayscale').checked = false;
    document.getElementById('imgSepia').checked = false;

    updateImageFilters();
}

function rotateImage90() {

    if (!backgroundImageNode) {
        return;
    }

    backgroundImageNode.rotation(
        backgroundImageNode.rotation() + 90
    );

    backgroundLayer.batchDraw();
}

/*
|--------------------------------------------------------------------------
| SAVE JSON
|--------------------------------------------------------------------------
|
| IMPORTANT:
| We save only the editable object data.
| The actual image remains my-image.jpeg.
|
*/

function getObjectData() {

    const node = getObjectNodes()[0];

    if (!node) {
        return null;
    }

    if (node.getAttr('customType') === 'TextBox') {
        const explicitKey = String(node.getAttr('dynamicKey') || '').trim().toUpperCase();
        const inferredKey = inferDynamicKey(node.getAttr('textValue') || '');
        node.setAttr('dynamicKey', explicitKey || inferredKey || '');
    }

    const raw = node.toObject();

    return {
        id: raw.attrs?.id || null,

        type: node.getAttr('customType') || node.getClassName(),

        attrs: raw.attrs || {}
    };
}


function displaySavedJSON(data) {
    const output = document.getElementById('savedJsonOutput');
    if (!output) return;

    output.textContent = JSON.stringify(data, null, 2);
    output.classList.remove('hidden');
}


function saveDesign() {

    const object = getObjectData();

    const data = {

        version: 1,

        image: currentImage,

        canvas: {
            width: DESIGN_WIDTH,
            height: DESIGN_HEIGHT
        },

        imageSettings: getImageFilterState(),

        object: object
    };

    const form = new FormData();

    form.append(
        'action',
        'save'
    );

    form.append(
        'image',
        currentImage
    );

    form.append(
        'data',
        JSON.stringify(data)
    );

    setStatus('Saving...');

    fetch(window.location.href, {

        method: 'POST',

        body: form

    })
    .then(function(response) {

        return response.json();

    })
    .then(function(result) {

        if (!result.success) {
            throw new Error(result.message);
        }

        // Important: update the in-page copy too, keyed by this image.
        // Otherwise reopening the modal on the same page would use
        // the old PHP-rendered savedData value.
        savedDataByImage[currentImage] = data;
        savedData = data;

        // Print the complete JSON of the current image on the page.
        displaySavedJSON(data);

        setStatus(
            'Saved. Closing editor...'
        );

        /*
         * Requirement:
         * After save, editor must close.
         */
        setTimeout(function() {

            closeEditor();

        }, 250);

    })
    .catch(function(error) {

        setStatus(
            error.message,
            true
        );

    });
}


/*
|--------------------------------------------------------------------------
| LOAD SAVED JSON
|--------------------------------------------------------------------------
*/

function loadSavedObject() {

    if (
        !savedData ||
        !savedData.object
    ) {
        return;
    }

    const object = savedData.object;

    if (!object.attrs) {
        return;
    }

    let node = null;

    switch (object.type) {

        case 'TextBox':
            node = createTextBox({
                x: object.attrs.x,
                y: object.attrs.y,
                rotation: object.attrs.rotation,
                scaleX: object.attrs.scaleX,
                scaleY: object.attrs.scaleY,
                opacity: object.attrs.opacity,
                text: object.attrs.textValue,
                dynamicKey: object.attrs.dynamicKey || inferDynamicKey(object.attrs.textValue || ''),
                textColor: object.attrs.textColor,
                backgroundColor: object.attrs.backgroundColor,
                backgroundEnabled: object.attrs.backgroundEnabled,
                padding: object.attrs.textPadding,
                cornerRadius: object.attrs.cornerRadius,
                fontFamily: object.attrs.fontFamily,
                fontSize: object.attrs.fontSize,
                fontStyle: object.attrs.fontStyle,
                textDecoration: object.attrs.textDecoration,
                align: object.attrs.align
            });
            break;

        case 'Text':
            // Backward compatibility with the old JSON format.
            node = new Konva.Text(
                object.attrs
            );
            break;

        case 'Rect':
            node = new Konva.Rect(
                object.attrs
            );
            break;

        case 'Circle':
            node = new Konva.Circle(
                object.attrs
            );
            break;

        case 'Star':
            node = new Konva.Star(
                object.attrs
            );
            break;

        default:
            return;
    }

    node.draggable(true);

    objectLayer.add(node);

    selectNode(node);

    objectLayer.batchDraw();

    updateObjectButtons();

    setStatus(
        'Previously saved object loaded.'
    );
}


/*
|--------------------------------------------------------------------------
| PNG EXPORT
|--------------------------------------------------------------------------
|
| The background image is included because it is a real
| Konva image node on the background layer.
|
| Transformer is hidden temporarily.
|
*/

function downloadStagePNG(filename) {

    if (!stage) {
        throw new Error('Editor stage is not ready.');
    }

    const wasVisible = transformer ? transformer.visible() : false;

    if (transformer) {
        transformer.visible(false);
    }

    stage.draw();

    try {

        const dataURL = stage.toDataURL({
            mimeType: 'image/png',
            pixelRatio: 2,
            imageSmoothingEnabled: true
        });

        const link = document.createElement('a');
        link.download = filename || ('edited-image-' + Date.now() + '.png');
        link.href = dataURL;
        document.body.appendChild(link);
        link.click();
        link.remove();

        return true;

    } finally {

        if (transformer) {
            transformer.visible(wasVisible);
        }

        stage.draw();
    }
}


function waitForEditorReady(timeout = 5000) {

    return new Promise(function(resolve, reject) {

        const started = Date.now();

        const timer = setInterval(function() {

            const hasBackground = !!backgroundImageNode;
            const hasObject = getObjectNodes().length > 0;

            if (hasBackground && hasObject) {
                clearInterval(timer);
                resolve();
                return;
            }

            if (Date.now() - started > timeout) {
                clearInterval(timer);
                reject(new Error('Could not load saved image/design in time.'));
            }

        }, 50);
    });
}


async function generateDynamicPNG() {

    const imageSelect = document.getElementById('dynamicImageSelect');
    const targetImage = imageSelect ? imageSelect.value : null;

    const key =
        document.getElementById('dynamicKeyInput').value.trim().toUpperCase();

    const value =
        document.getElementById('dynamicValueInput').value;

    const status = document.getElementById('generatorStatus');

    if (!targetImage) {
        status.textContent = 'No image selected.';
        status.style.color = '#dc2626';
        return;
    }

    if (!key) {
        status.textContent = 'Please enter a dynamic key, e.g. NAME.';
        status.style.color = '#dc2626';
        return;
    }

    const targetData = savedDataByImage[targetImage];

    if (!targetData || !targetData.object) {
        status.textContent = 'First open the editor for "' + targetImage + '" and save a design with a dynamic text.';
        status.style.color = '#dc2626';
        return;
    }

    status.textContent = 'Generating final PNG...';
    status.style.color = '#059669';

    try {

        // The editor is initialized programmatically; the user does not need to open it.
        currentImage = targetImage;
        savedData = targetData;

        document.getElementById('editorScreen').style.display = 'none';
        initEditor();
        loadSavedObject();

        await waitForEditorReady();

        const nodes = getObjectNodes();
        let node = null;
        for (let i = 0; i < nodes.length; i++) {
            const candidate = nodes[i];
            if (candidate.getAttr('customType') !== 'TextBox') continue;
            const candidateKey = String(candidate.getAttr('dynamicKey') || '').trim().toUpperCase();
            const candidateText = String(candidate.getAttr('textValue') || '');
            if (candidateKey === key || inferDynamicKey(candidateText) === key || candidateText.trim().toUpperCase() === key) {
                node = candidate; break;
            }
        }
        if (!node) {
            throw new Error('Dynamic key "' + key + '" was not found. Open Editor, select the text, set Dynamic key to ' + key + ', then Save & Close.');
        }
        if (!replaceDynamicText(node, key, value)) {
            throw new Error('Could not replace dynamic key "' + key + '".');
        }

        objectLayer.batchDraw();
        backgroundLayer.batchDraw();

        downloadStagePNG(
            'dynamic-' + key.toLowerCase() + '-' + Date.now() + '.png'
        );

        status.textContent = 'Final PNG generated successfully.';

    } catch (error) {

        console.error(error);
        status.textContent = error.message || 'PNG generation failed.';
        status.style.color = '#dc2626';

    } finally {

        closeEditor();
    }
}


function exportPNG() {

    if (!stage) {
        return;
    }

    try {

        downloadStagePNG(
            'edited-image-' + Date.now() + '.png'
        );

        setStatus(
            'Final PNG exported successfully.'
        );

    } catch (error) {

        console.error(error);

        setStatus(
            'PNG export failed.',
            true
        );
    }
}


/*
|--------------------------------------------------------------------------
| ZOOM
|--------------------------------------------------------------------------
*/

function setZoom(value) {

    zoom =
        Math.max(
            0.25,
            Math.min(2, value)
        );

    stage.scale({
        x: zoom,
        y: zoom
    });

    document.getElementById(
        'zoomValue'
    ).textContent =
        Math.round(zoom * 100) + '%';

    stage.container().style.width =
        DESIGN_WIDTH * zoom + 'px';

    stage.container().style.height =
        DESIGN_HEIGHT * zoom + 'px';

    stage.draw();
}


function zoomIn() {

    setZoom(
        zoom + 0.1
    );
}


function zoomOut() {

    setZoom(
        zoom - 0.1
    );
}


function resetZoom() {

    setZoom(1);
}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

function setStatus(message, error = false) {

    const el =
        document.getElementById('status');

    el.textContent = message;

    el.style.color =
        error
            ? '#dc2626'
            : '#059669';
}
</script>


</body>
</html>