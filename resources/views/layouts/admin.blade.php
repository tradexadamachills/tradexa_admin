<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow"><title>@yield('title','Dashboard') &middot; Tradexa Admin</title>
  <link rel="stylesheet" href="{{ asset('admin.css') }}"><link rel="stylesheet" href="{{ asset('dashboard.css') }}"><link rel="stylesheet" href="{{ asset('admin-light.css') }}">
  <link rel="icon" href="{{ asset('tradexa-logo.png') }}">
  @stack('head')
</head>
<body>
<div class="admin-app">
  <input type="checkbox" id="sidebar-toggle" class="sidebar-toggle" aria-label="Toggle sidebar">
  <label for="sidebar-toggle" class="sidebar-scrim" aria-label="Close sidebar"></label>
  <aside class="admin-sidebar">
    <a class="admin-brand" href="{{ route('dashboard') }}"><img src="{{ asset('tradexa-logo.png') }}" alt="" width="35" height="35"> tradexa<span class="brand-period">.</span></a>
    <div class="control-label"><i></i> ADMIN WORKSPACE <span>CONTROL</span></div>
    <div class="side-section">WORKSPACE</div>
    <nav aria-label="Administration navigation">
      @foreach([['dashboard','Dashboard','grid'],['users.index','Users','users'],['reviews.index','Verification requests','shield'],['support.index','Support requests','help']] as [$route,$label,$icon])
        <a class="side-link {{ request()->routeIs($route) ? 'active':'' }}" href="{{ route($route) }}"><x-icon :name="$icon"/>{{ $label }}<x-icon name="chevron"/></a>
      @endforeach
    </nav>
    <div class="side-section">MANAGEMENT</div>
    <nav aria-label="Management navigation">
      @foreach([['audit.index','Activity log','list'],['integrations','Integrations','connect']] as [$route,$label,$icon])
        <a class="side-link {{ request()->routeIs($route)?'active':'' }}" href="{{ route($route) }}"><x-icon :name="$icon"/>{{ $label }}<x-icon name="chevron"/></a>
      @endforeach
    </nav>
    <div class="sidebar-bottom">
      <div class="sidebar-help"><span class="help-emblem"><x-icon name="help"/></span><strong>Everything in one place.</strong><p>Manage accounts, review documents and respond to requests.</p><a href="{{ config('tradexa.web_url') }}" target="_blank" rel="noreferrer">Open customer website <x-icon name="external"/></a></div>
      <form method="POST" action="{{ route('logout') }}">@csrf<button class="side-link"><x-icon name="logout"/>Sign out</button></form>
      <small class="sidebar-version">TRADEXA / ADMIN PORTAL</small>
    </div>
  </aside>
  <div class="admin-body">
    <header class="admin-topbar">
      <div class="topbar-title"><label for="sidebar-toggle" class="sidebar-button" title="Toggle sidebar"><x-icon name="grid"/><span class="sr-only">Toggle sidebar</span></label><strong>@yield('title','Dashboard')</strong></div>
      <div class="topbar-tools">
        <form class="topbar-search" action="{{ route('users.index') }}" method="GET"><x-icon name="users"/><input name="q" aria-label="Search users" placeholder="Search users..." value="{{ request('q') }}"></form>
        <span class="staff-avatar">{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span>
        <span class="staff-name">{{ auth()->user()->name }}<small>{{ ucfirst(auth()->user()->role) }}</small></span>
      </div>
    </header>
    <main class="admin-main">
      @if(session('status'))<div class="flash success" role="status">{{ session('status') }}</div>@endif
      @if($errors->any())<div class="flash error" role="alert">{{ $errors->first() }}</div>@endif
      @yield('content')
      <footer class="admin-footer"><span>&copy; {{ date('Y') }} Tradexa. All rights reserved.</span><span>Platform administration</span></footer>
    </main>
  </div>
</div>
</body></html>
