<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Resources\Sites\Tables\SitesTable;
use App\Models\Site;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $modelLabel = 'Site';

    protected static ?string $pluralModelLabel = 'Vaccination Sites';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return 'Vaccination Sites';
    }

    public static function table(Table $table): Table
    {
        return SitesTable::configure($table);
    }

    /**
     * Detail "card" for a single site: all fields plus an embedded map pin and
     * a one-tap Google Maps link to the exact coordinates.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Site')
                ->columns(2)
                ->schema([
                    TextEntry::make('fix_site')->label('Fixed Site')->weight('bold')->columnSpanFull(),
                    TextEntry::make('outreach_site')->label('Outreach Site')->placeholder('Fixed Site')->columnSpanFull(),
                    TextEntry::make('district')->label('District')->badge()->color('info'),
                    TextEntry::make('union_council')->label('Union Council')->badge()->color('success'),
                ]),

            Section::make('Location')
                ->columns(2)
                ->schema([
                    TextEntry::make('latitude')->label('Latitude')->placeholder('—'),
                    TextEntry::make('longitude')->label('Longitude')->placeholder('—'),
                    TextEntry::make('maps_link')
                        ->label('Google Maps')
                        ->state(fn (Site $r): string => SitesTable::mapUrl($r) ? 'Open the pin in Google Maps →' : 'No coordinates on file')
                        ->icon('heroicon-o-map-pin')
                        ->color(fn (Site $r): string => SitesTable::mapUrl($r) ? 'primary' : 'gray')
                        ->url(fn (Site $r): ?string => SitesTable::mapUrl($r))
                        ->openUrlInNewTab()
                        ->columnSpanFull(),
                    TextEntry::make('map_preview')
                        ->label('Map')
                        ->state(fn (Site $r): HtmlString|string => self::mapEmbed($r))
                        ->html()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** Embedded OpenStreetMap pin (no API key needed), or a dash if no coords. */
    protected static function mapEmbed(Site $site): HtmlString|string
    {
        if ($site->latitude === null || $site->longitude === null) {
            return '—';
        }

        $lat = $site->latitude;
        $lng = $site->longitude;
        // Small bounding box around the point so the marker sits centered.
        $d = 0.004;
        $bbox = ($lng - $d).','.($lat - $d).','.($lng + $d).','.($lat + $d);
        $src = "https://www.openstreetmap.org/export/embed.html?bbox={$bbox}&layer=mapnik&marker={$lat},{$lng}";

        return new HtmlString(
            '<iframe width="100%" height="280" frameborder="0" scrolling="no" '
            .'style="border:1px solid rgba(0,0,0,0.1);border-radius:10px" '
            .'src="'.e($src).'"></iframe>'
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
        ];
    }
}
