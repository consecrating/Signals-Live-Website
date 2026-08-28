/**
 * Stale-tab detector.
 *
 * Every page embeds APP_BUILD and imports this. It polls ?action=build and, if the
 * server's deployed build no longer matches the one this tab loaded, shows a fixed
 * banner asking the user to reload.
 *
 * This exists because the recurring "it still shows the same error" reports were
 * traced to browser tabs left open across deploys: the HTML is served no-store, so
 * a fresh load always gets the current build, but an already-open tab keeps running
 * whatever it loaded hours ago. A tab cannot fix that itself — but it can now SAY so
 * instead of looking like an unfixed bug.
 */
export function startBuildCheck(pageBuild) {
  const URL = '/signals/api/proxy.php?action=build';
  let warned = false;

  function banner(serverBuild) {
    if (warned) return;
    warned = true;
    const b = document.createElement('div');
    b.id = 'stale-build-banner';
    b.style.cssText =
      'position:fixed;left:0;right:0;bottom:0;z-index:9999;padding:0.7rem 1rem;' +
      'background:#b45309;color:#fff;font:600 0.82rem system-ui,sans-serif;' +
      'display:flex;align-items:center;justify-content:center;gap:0.9rem;box-shadow:0 -6px 20px rgba(0,0,0,.4)';
    b.innerHTML =
      '<span>⚠ This page is running an old version (' + pageBuild + '). A newer build (' +
      serverBuild + ') is live — reload to get the current behaviour.</span>' +
      '<button id="stale-reload" style="background:#fff;color:#b45309;border:0;border-radius:6px;' +
      'padding:0.35rem 0.9rem;font-weight:800;cursor:pointer">Reload now</button>';
    document.body.appendChild(b);
    document.getElementById('stale-reload').onclick = () => location.reload(true);
  }

  async function check() {
    try {
      const r = await fetch(URL + '&cb=' + Date.now(), { cache: 'no-store' });
      const j = await r.json();
      if (j && j.build && pageBuild && j.build !== pageBuild) banner(j.build);
    } catch (e) { /* network hiccup — try again next interval */ }
  }

  check();
  setInterval(check, 60000);
}
