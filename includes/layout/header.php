<?php
/** Cabecera + layout. Variables esperadas: $page_title, $page_subtitle, $page_actions. */
$page_title    = $GLOBALS['page_title'] ?? 'Panel';
$page_subtitle = $GLOBALS['page_subtitle'] ?? '';
$page_actions  = $GLOBALS['page_actions'] ?? '';
?>
<!doctype html>
<html lang="es" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?> · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
      colors: {
        brand: { 50:'#eff5ff',100:'#dbe8fe',200:'#bfd7fe',300:'#93bbfd',400:'#609afa',500:'#3b82f6',600:'#2563eb',700:'#1d4fd8',800:'#1e40af',900:'#1e3a8a' },
      },
      boxShadow: {
        card: '0 1px 2px 0 rgba(15,23,42,.04), 0 1px 3px 0 rgba(15,23,42,.06)',
        soft: '0 4px 24px -8px rgba(15,23,42,.12)',
        pop:  '0 12px 40px -12px rgba(15,23,42,.25)',
      },
    },
  },
};
</script>
<style type="text/tailwindcss">
  @layer base {
    body { @apply font-sans antialiased; }
    ::-webkit-scrollbar { width: 9px; height: 9px; }
    ::-webkit-scrollbar-thumb { @apply bg-slate-300 rounded-full; }
    ::-webkit-scrollbar-thumb:hover { @apply bg-slate-400; }
    ::-webkit-scrollbar-track { @apply bg-transparent; }
  }
  @layer components {
    .card { @apply bg-white rounded-2xl border border-slate-200/80 shadow-card; }
    .btn { @apply inline-flex items-center justify-center gap-2 font-semibold rounded-xl px-4 py-2.5 text-sm transition focus:outline-none disabled:opacity-50 disabled:pointer-events-none; }
    .btn-primary { @apply bg-blue-600 text-white hover:bg-blue-700 shadow-sm shadow-blue-600/25; }
    .btn-ghost { @apply bg-white text-slate-700 border border-slate-200 hover:bg-slate-50; }
    .btn-soft { @apply bg-blue-50 text-blue-700 hover:bg-blue-100; }
    .btn-danger { @apply bg-rose-600 text-white hover:bg-rose-700; }
    .btn-success { @apply bg-emerald-600 text-white hover:bg-emerald-700; }
    .btn-sm { @apply px-3 py-2 text-xs rounded-lg; }
    .input { @apply w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-700 placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition; }
    .select { @apply input appearance-none bg-no-repeat pr-10; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-position: right .75rem center; }
    .label { @apply block text-sm font-medium text-slate-600 mb-1.5; }
    .badge { @apply inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap; }
    .badge-emerald { @apply bg-emerald-50 text-emerald-700; }
    .badge-amber { @apply bg-amber-50 text-amber-700; }
    .badge-rose { @apply bg-rose-50 text-rose-700; }
    .badge-slate { @apply bg-slate-100 text-slate-600; }
    .badge-sky { @apply bg-sky-50 text-sky-700; }
    .badge-blue { @apply bg-blue-50 text-blue-700; }
    .badge-indigo { @apply bg-indigo-50 text-indigo-700; }
    .badge-cyan { @apply bg-cyan-50 text-cyan-700; }
    .badge-pink { @apply bg-pink-50 text-pink-700; }
    .badge-violet { @apply bg-violet-50 text-violet-700; }
    .data-table { @apply w-full text-sm; }
    .data-table thead th { @apply text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wider px-4 py-3 bg-slate-50/60; }
    .data-table tbody td { @apply px-4 py-3.5 border-t border-slate-100 align-middle; }
    .data-table tbody tr:hover { @apply bg-slate-50/50; }
    .nav-link { @apply flex items-center gap-3 px-3 py-2.5 rounded-xl text-[13.5px] font-medium text-slate-500 hover:bg-slate-100/70 hover:text-slate-800 transition relative; }
    .nav-active { @apply bg-blue-50 text-blue-700 hover:bg-blue-50 hover:text-blue-700 font-semibold; }
    .nav-active::before { content:''; @apply absolute left-0 top-1/2 -translate-y-1/2 h-5 w-1 rounded-r-full bg-blue-600; }
    .nav-section { @apply px-3 pt-5 pb-1.5 text-[10.5px] font-bold uppercase tracking-wider text-slate-300; }
    .stat-trend-up { @apply text-emerald-600 bg-emerald-50; }
    .stat-trend-down { @apply text-rose-600 bg-rose-50; }
  }
  @media print {
    aside, header.sticky, footer, .no-print { display: none !important; }
    .lg\:pl-\[260px\] { padding-left: 0 !important; }
    body { background: #fff !important; }
    .card { box-shadow: none !important; border-color: #e5e7eb !important; }
    main { overflow: visible !important; }
    @page { margin: 12mm; }
  }
</style>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.10/dist/cdn.min.js"></script>
</head>
<body class="h-full bg-slate-100 text-slate-700" x-data="{ sidebar:false }">
<div class="min-h-full">
  <?php include __DIR__ . '/sidebar.php'; ?>

  <!-- Overlay móvil -->
  <div x-show="sidebar" @click="sidebar=false" x-transition.opacity class="fixed inset-0 bg-slate-900/40 z-30 lg:hidden" style="display:none"></div>

  <div class="lg:pl-[260px] flex flex-col min-h-screen">
    <?php include __DIR__ . '/topbar.php'; ?>

    <main class="flex-1">
      <div class="px-4 sm:px-6 lg:px-8 py-6 mx-auto w-full max-w-[1500px]">
        <!-- Cabecera de página -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
          <div>
            <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight"><?= e($page_title) ?></h1>
            <?php if ($page_subtitle): ?><p class="text-slate-500 text-sm mt-1"><?= e($page_subtitle) ?></p><?php endif; ?>
          </div>
          <?php if ($page_actions): ?><div class="flex items-center gap-2 flex-wrap"><?= $page_actions ?></div><?php endif; ?>
        </div>

        <?php render_flashes(); ?>
