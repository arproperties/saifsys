<style>
    :root {
      --primary: <?= $brand['primary_color'] ?>;
      --primary-light: <?= $brand['primary_light'] ?>;
      --primary-dark: <?= $brand['primary_dark'] ?>;
      --accent: <?= $brand['accent_color'] ?>;
    }
    body{ background:#f6f7f9; }
    .sidebar{
      min-height:100vh; width:240px; background:linear-gradient(180deg, var(--primary), var(--primary-light));
      color:#fff; position:sticky; top:0; z-index:1030; transition:width .2s ease;
    }
    .sidebar.collapsed{ width:84px; }
    .brand{ font-weight:800; letter-spacing:.3px; color:var(--accent); opacity:.95; }
    .nav-sect{ font-size:.75rem; text-transform:uppercase; letter-spacing:.08em; color:#ffefc4; opacity:.7; margin:.5rem 0 .25rem .75rem; }
    .slink{ display:flex; align-items:center; gap:.75rem; padding:.6rem .9rem; margin:.15rem .5rem; border-radius:10px; color:#fff; text-decoration:none; }
    .slink:hover{ background:rgba(255,255,255,.08); }
    .slink.active{ background:rgba(255,255,255,.16); }
    .sicon{ width:28px; height:28px; display:grid; place-items:center; background:rgba(255,255,255,.15); border-radius:8px; }
    .slabel{ white-space:nowrap; }
    .sidebar.collapsed .slabel{ display:none; }
    .sidebar.collapsed .nav-sect{ display:none; }
    .collapse-btn{ position:absolute; right:-12px; top:12px; width:24px; height:24px; border-radius:50%; background:#fff; color:var(--primary);
      box-shadow:0 4px 10px rgba(0,0,0,.15); display:grid; place-items:center; cursor:pointer; }
    .avatar{ width:36px;height:36px;border-radius:50%; background:#eee; display:grid; place-items:center; font-weight:700; color:var(--primary); }
    .card-round{ border:0;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.06); }
    .page-header-label{ font-size:1.85rem;font-weight:700;color:var(--primary); }
    body.dark-mode {
      background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
      color: #e4e4e7;
    }
    body.dark-mode .sidebar {
      background: linear-gradient(180deg, var(--primary-dark), var(--primary));
    }
    body.dark-mode .navbar {
      background: rgba(30, 41, 59, 0.95) !important;
      border-bottom: 1px solid #334155;
    }
</style>
