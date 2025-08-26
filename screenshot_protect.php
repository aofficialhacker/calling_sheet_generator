<?php
/**
 * Screenshot / Print / Copy deterrence overlay (best-effort).
 * NOTE: True screenshot blocking isn't possible on the web;
 * this adds strong deterrents (watermark, print/copy/PrtSc intercepts,
 * blur on tab switch, and session-bound identity overlay).
 *
 * Usage: include this file near the end of <head> or just after <body> opens.
 * Requires PHP session (email/username optional). Safe to include multiple times.
 */

if (session_status() === PHP_SESSION_NONE) { @session_start(); }

$__scr_user = null;
$possible_keys = ['team_leader_email','tl_email','email','username','user_email','user_name'];
foreach ($possible_keys as $k) {
    if (!empty($_SESSION[$k])) { $__scr_user = $_SESSION[$k]; break; }
}
if (!$__scr_user && !empty($_SESSION['team_leader'])) {
    // common pattern: ['email' => ...] etc.
    if (!empty($_SESSION['team_leader']['email'])) $__scr_user = $_SESSION['team_leader']['email'];
    elseif (!empty($_SESSION['team_leader']['username'])) $__scr_user = $_SESSION['team_leader']['username'];
}

$__scr_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$__scr_time = date('Y-m-d H:i:s');
$__scr_tag  = ($__scr_user ? $__scr_user . ' • ' : '') . $__scr_ip . ' • ' . $__scr_time;

// Allow page to override via $SCREENSHOT_PROTECT_ENABLED (default true)
$__enabled = true;
if (isset($SCREENSHOT_PROTECT_ENABLED)) { $__enabled = (bool)$SCREENSHOT_PROTECT_ENABLED; }
?>
<?php if ($__enabled): ?>
<style>
  /* Hide all content when printing */
  @media print {
    body * { visibility: hidden !important; }
    #__print_blocker { visibility: visible !important; position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; font: 700 18px/1.4 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 24px; }
  }

  /* Blur when tab not focused */
  html.__blurred .__protect-target {
    filter: blur(8px) saturate(0.7);
    transition: filter .2s ease;
  }

  /* Watermark overlay */
  #__wm_overlay {
    position: fixed; inset: 0; pointer-events: none; z-index: 2147483646;
    opacity: .16;
  }
  #__wm_overlay .__wm_cell {
    position: absolute; white-space: nowrap; user-select: none;
    transform: rotate(-25deg);
    font: 700 14px/1.1 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    text-shadow: 0 0 1px rgba(0,0,0,.15);
  }

  /* Print blocker message (visible only in print) */
  #__print_blocker { display: none; }
</style>
<div id="__print_blocker" aria-hidden="true">
  Printing/saving is disabled for this page. Contact your administrator.
</div>
<script>
(function(){
  const tag = <?php echo json_encode($__scr_tag ?? ""); ?>;
  const page = location.pathname.split('/').pop() || 'page';
  const seed = Math.random().toString(36).slice(2);
  const overlayId = "__wm_overlay";
  const targetClass = "__protect-target";

  // Mark main containers as protected (best effort)
  try {
    document.addEventListener("DOMContentLoaded", () => {
      const main = document.querySelector("main") || document.querySelector("#app") || document.body;
      if (main && !main.classList.contains(targetClass)) main.classList.add(targetClass);
    });
  } catch(e){}

  // Build a tiled watermark overlay
  function buildOverlay() {
    let overlay = document.getElementById(overlayId);
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = overlayId;
      document.body.appendChild(overlay);
    } else {
      overlay.innerHTML = "";
    }
    const W = window.innerWidth, H = window.innerHeight;
    const stepX = 260, stepY = 180;
    for (let y = 0; y < H + stepY; y += stepY) {
      for (let x = 0; x < W + stepX; x += stepX) {
        const cell = document.createElement("div");
        cell.className = "__wm_cell";
        cell.style.left = (x + (y/stepY)%2 * (stepX/2)) + "px"; // stagger rows
        cell.style.top = y + "px";
        cell.textContent = tag ? (tag + " • " + page + " • " + seed) : (page + " • " + seed);
        overlay.appendChild(cell);
      }
    }
  }
  buildOverlay();
  window.addEventListener("resize", buildOverlay);

  // Intercept print via keyboard
  function blockPrintDialog(ev) {
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === "p") {
      ev.preventDefault();
      alert("Printing/Saving to PDF is disabled for this page.");
      return false;
    }
  }
  document.addEventListener("keydown", blockPrintDialog, true);

  // Attempt to neuter PrintScreen clipboard (limited effectiveness, browser-dependent)
  async function onPrintScreen(ev) {
    if (ev.key === "PrintScreen") {
      try {
        await navigator.clipboard.writeText("Screenshots are disabled for this page.");
        alert("Screenshot blocked. This action is monitored.");
      } catch (e) {
        // Clipboard may be unavailable; still show a deterrent
        alert("Screenshot attempt detected. This action is monitored.");
      }
    }
  }
  document.addEventListener("keydown", onPrintScreen, true);

  // Blur when tab is hidden (deterrent for screen recorders/switching)
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
      document.documentElement.classList.add("__blurred");
    } else {
      document.documentElement.classList.remove("__blurred");
    }
  });

  // Block context menu & common copy actions (deterrent only)
  document.addEventListener("contextmenu", e => e.preventDefault());
  document.addEventListener("copy", e => { e.preventDefault(); alert("Copy disabled on this page."); });
  document.addEventListener("cut", e => { e.preventDefault(); });
  document.addEventListener("dragstart", e => { e.preventDefault(); });

  // Before print hide content (for browsers that fire events)
  function beforePrint() {
    const blocker = document.getElementById("__print_blocker");
    if (blocker) blocker.style.display = "flex";
  }
  function afterPrint() {
    const blocker = document.getElementById("__print_blocker");
    if (blocker) blocker.style.display = "none";
  }
  window.addEventListener("beforeprint", beforePrint);
  window.addEventListener("afterprint", afterPrint);
})();
</script>
<?php endif; ?>
