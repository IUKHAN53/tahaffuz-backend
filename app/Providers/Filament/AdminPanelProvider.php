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
        // Single-line lockup sized for the short sidebar. The shield uses
        // currentColor so it stays legible on both the light and dark admin
        // themes; the dots stay amber.
        return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 360 104" preserveAspectRatio="xMinYMid meet" aria-label="Tika Dost" role="img" style="height:100%;width:auto;display:block">
  <g transform="translate(2,2) scale(1.0)">
    <path d="M50 9 L82 20 C84 20.8 85 22 85 24 L85 50 C85 70 71 83 56 89 L60 99 L43 88 C27 84 15 70 15 50 L15 24 C15 22 16 20.8 18 20 Z" fill="currentColor"/>
    <circle cx="35" cy="50" r="6.5" fill="#E0A24A"/>
    <circle cx="50" cy="50" r="6.5" fill="#E0A24A"/>
    <circle cx="65" cy="50" r="6.5" fill="#E0A24A"/>
  </g>
  <text x="104" y="66" fill="currentColor" font-family="Manrope, system-ui, sans-serif" font-weight="800" font-size="52" letter-spacing="-1">Tika Dost</text>
</svg>
SVG;
    }
}
