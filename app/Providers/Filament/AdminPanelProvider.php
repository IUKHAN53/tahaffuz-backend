<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\ChatsOverTimeChart;
use App\Filament\Widgets\OverviewStatsWidget;
use App\Filament\Widgets\RecentChatsTableWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Tika Dost')
            ->brandLogo(fn () => new HtmlString($this->brandLogoSvg()))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.svg'))
            ->colors([
                // Deep indigo "ink" from the Tahaffuz brief — Filament generates the full 50→950 scale.
                'primary' => Color::hex('#143C6C'),
                'gray' => Color::Slate,
                'warning' => Color::hex('#E0A24A'),
            ])
            ->darkMode()
            ->font('Manrope')
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                OverviewStatsWidget::class,
                ChatsOverTimeChart::class,
                RecentChatsTableWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Tahaffuz · Speech-Shield lockup. Mark + Latin wordmark + tagline.
     * Rendered inline so the Urdu font @import resolves and `currentColor`
     * follows Filament's dark-mode text color for the wordmark.
     */
    protected function brandLogoSvg(): string
    {
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 520 200" preserveAspectRatio="xMinYMid meet" aria-label="Tika Dost" role="img" style="height:100%;width:auto;display:block">
  <defs>
    <style>@import url('https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@600&amp;display=swap');</style>
    <linearGradient id="tahaffuz-shield" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#143C6C"/>
      <stop offset="1" stop-color="#07203F"/>
    </linearGradient>
  </defs>
  <g>
    <path d="M100 20 L168 42 V100 C168 134 142 156 110 170 L96 184 L92 168 C68 162 32 142 32 100 V42 Z" fill="url(#tahaffuz-shield)"/>
    <circle cx="76" cy="142" r="5" fill="#E0A24A"/>
    <circle cx="100" cy="142" r="5" fill="#E0A24A" opacity="0.7"/>
    <circle cx="124" cy="142" r="5" fill="#E0A24A" opacity="0.45"/>
    <text x="100" y="108" text-anchor="middle" fill="#F4EEE3" font-family="'Noto Nastaliq Urdu', serif" font-weight="600" font-size="54" direction="rtl">ٹیکہ دوست</text>
  </g>
  <text x="210" y="112" fill="currentColor" font-family="Manrope, system-ui, sans-serif" font-weight="800" font-size="72" letter-spacing="-1">Tika Dost</text>
  <text x="212" y="142" fill="currentColor" opacity="0.55" font-family="'JetBrains Mono', monospace" font-weight="500" font-size="14" letter-spacing="3.4">HEALTH · AI</text>
</svg>
SVG;
    }
}
