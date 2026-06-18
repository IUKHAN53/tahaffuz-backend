<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SitesTable
{
    /** Google Maps pin URL for a site's coordinates, or null if missing. */
    public static function mapUrl(Site $site): ?string
    {
        if ($site->latitude === null || $site->longitude === null) {
            return null;
        }

        return "https://www.google.com/maps/search/?api=1&query={$site->latitude},{$site->longitude}";
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fix_site')
                    ->label('Fixed Site')
                    ->weight('bold')
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('outreach_site')
                    ->label('Outreach Site')
                    ->wrap()
                    ->placeholder('Fixed Site')
                    ->searchable(),
                TextColumn::make('district')
                    ->label('District')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                TextColumn::make('union_council')
                    ->label('Union Council')
                    ->badge()
                    ->color('success')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('coordinates')
                    ->label('Location (tap to open map)')
                    ->state(fn (Site $r): string => $r->latitude !== null && $r->longitude !== null
                        ? "{$r->latitude}, {$r->longitude}"
                        : 'No coordinates')
                    ->icon('heroicon-o-map-pin')
                    ->color(fn (Site $r): string => self::mapUrl($r) ? 'primary' : 'gray')
                    ->url(fn (Site $r): ?string => self::mapUrl($r))
                    ->openUrlInNewTab()
                    ->copyable()
                    ->copyableState(fn (Site $r): string => $r->latitude !== null
                        ? "{$r->latitude},{$r->longitude}"
                        : ''),
            ])
            ->filters([
                SelectFilter::make('district')
                    ->options(fn () => Site::query()
                        ->whereNotNull('district')
                        ->distinct()
                        ->orderBy('district')
                        ->pluck('district', 'district')
                        ->all()),
                SelectFilter::make('union_council')
                    ->label('Union Council')
                    ->searchable()
                    ->options(fn () => Site::query()
                        ->whereNotNull('union_council')
                        ->distinct()
                        ->orderBy('union_council')
                        ->pluck('union_council', 'union_council')
                        ->all()),
                Filter::make('missing_coordinates')
                    ->label('Missing coordinates')
                    ->query(fn (Builder $query) => $query->whereNull('latitude')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Details'),
                Action::make('openMap')
                    ->label('Maps')
                    ->icon('heroicon-o-map')
                    ->color('primary')
                    ->url(fn (Site $r): ?string => self::mapUrl($r))
                    ->openUrlInNewTab()
                    ->hidden(fn (Site $r): bool => self::mapUrl($r) === null),
            ])
            ->defaultSort('district')
            ->paginated([25, 50, 100]);
    }
}
